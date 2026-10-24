// Package kafkaconsumer is the consumer runtime required by the platform:
// commit after handle, per-key ordering, a processing deadline per message,
// a retry topic with backoff and a DLQ after the last attempt.
package kafkaconsumer

import (
	"context"
	"fmt"
	"hash/fnv"
	"log/slog"
	"strconv"
	"time"

	"github.com/segmentio/kafka-go"
	"golang.org/x/sync/errgroup"
)

// Message is one Kafka record passed to a handler.
type Message struct {
	Topic     string
	Partition int
	Offset    int64
	Key       []byte
	Value     []byte
	// Attempt is 1 for the first delivery and grows with every retry.
	Attempt int
}

// Handler processes one message. A nil error commits the offset. An error,
// as well as a handler that does not return before the processing deadline,
// sends the message to the retry topic, and after the last attempt to the DLQ.
type Handler interface {
	Handle(ctx context.Context, msg Message) error
}

type Config struct {
	Brokers           []string
	Topic             string
	Group             string
	RetryTopic        string
	DLQTopic          string
	StartOffset       int64
	SessionTimeout    time.Duration
	CommitInterval    time.Duration
	Concurrency       int
	ProcessingTimeout time.Duration
	MaxAttempts       int
	Backoff           []time.Duration
}

type Consumer struct {
	cfg     Config
	handler Handler
	log     *slog.Logger
	retry   *kafka.Writer
	dlq     *kafka.Writer
}

func New(cfg Config, h Handler, log *slog.Logger) *Consumer {
	return &Consumer{
		cfg:     cfg,
		handler: h,
		log:     log,
		retry:   newWriter(cfg.Brokers, cfg.RetryTopic),
		dlq:     newWriter(cfg.Brokers, cfg.DLQTopic),
	}
}

func newWriter(brokers []string, topic string) *kafka.Writer {
	return &kafka.Writer{
		Addr:         kafka.TCP(brokers...),
		Topic:        topic,
		Balancer:     &kafka.Hash{},
		RequiredAcks: kafka.RequireAll,
	}
}

// Run consumes the main and the retry topic until ctx is cancelled.
func (c *Consumer) Run(ctx context.Context) error {
	defer c.retry.Close()
	defer c.dlq.Close()

	g, ctx := errgroup.WithContext(ctx)
	g.Go(func() error { return c.consume(ctx, c.cfg.Topic, c.cfg.Group) })
	g.Go(func() error { return c.consume(ctx, c.cfg.RetryTopic, c.cfg.Group+"-retry") })
	return g.Wait()
}

func (c *Consumer) consume(ctx context.Context, topic, group string) error {
	r := kafka.NewReader(kafka.ReaderConfig{
		Brokers:        c.cfg.Brokers,
		GroupID:        group,
		Topic:          topic,
		StartOffset:    c.cfg.StartOffset,
		SessionTimeout: c.cfg.SessionTimeout,
		CommitInterval: c.cfg.CommitInterval,
	})
	defer r.Close()

	g, ctx := errgroup.WithContext(ctx)
	inflight := newOffsets()

	// Messages with the same key always go to the same slot, so they are
	// handled in order.
	slots := make([]chan kafka.Message, c.cfg.Concurrency)
	for i := range slots {
		ch := make(chan kafka.Message)
		slots[i] = ch
		g.Go(func() error {
			for m := range ch {
				if err := c.process(ctx, m); err != nil {
					if ctx.Err() != nil {
						return nil
					}
					return err
				}
				if commit, ok := inflight.done(m); ok {
					if err := r.CommitMessages(ctx, commit); err != nil {
						return fmt.Errorf("commit %s/%d: %w", topic, m.Partition, err)
					}
				}
			}
			return nil
		})
	}

	g.Go(func() error {
		defer func() {
			for _, ch := range slots {
				close(ch)
			}
		}()
		for {
			m, err := r.FetchMessage(ctx)
			if err != nil {
				if ctx.Err() != nil {
					return nil
				}
				return fmt.Errorf("fetch %s: %w", topic, err)
			}
			inflight.fetched(m)
			select {
			case slots[slot(m.Key, len(slots))] <- m:
			case <-ctx.Done():
				return nil
			}
		}
	})

	return g.Wait()
}

func (c *Consumer) process(ctx context.Context, m kafka.Message) error {
	attempt := attemptOf(m)
	if notBefore, ok := notBeforeOf(m); ok {
		if err := sleepUntil(ctx, notBefore); err != nil {
			return err
		}
	}

	hctx, cancel := context.WithTimeout(ctx, c.cfg.ProcessingTimeout)
	err := c.handler.Handle(hctx, Message{
		Topic:     m.Topic,
		Partition: m.Partition,
		Offset:    m.Offset,
		Key:       m.Key,
		Value:     m.Value,
		Attempt:   attempt,
	})
	cancel()
	if err == nil {
		return nil
	}
	if ctx.Err() != nil {
		// Shutting down: the offset stays uncommitted, the message is
		// redelivered after restart.
		return ctx.Err()
	}

	log := c.log.With("topic", m.Topic, "partition", m.Partition, "offset", m.Offset, "attempt", attempt, "err", err)
	if attempt >= c.cfg.MaxAttempts {
		log.ErrorContext(ctx, "message sent to dlq")
		if err := c.dlq.WriteMessages(ctx, forward(m, attempt, time.Time{}, err)); err != nil {
			return fmt.Errorf("write to dlq: %w", err)
		}
		return nil
	}

	log.WarnContext(ctx, "message sent to retry")
	next := forward(m, attempt+1, time.Now().Add(c.backoff(attempt)), err)
	if err := c.retry.WriteMessages(ctx, next); err != nil {
		return fmt.Errorf("write to retry: %w", err)
	}
	return nil
}

func (c *Consumer) backoff(attempt int) time.Duration {
	if len(c.cfg.Backoff) == 0 {
		return 0
	}
	i := min(attempt-1, len(c.cfg.Backoff)-1)
	return c.cfg.Backoff[i]
}

func slot(key []byte, n int) int {
	h := fnv.New32a()
	h.Write(key)
	return int(h.Sum32() % uint32(n))
}

func sleepUntil(ctx context.Context, t time.Time) error {
	d := time.Until(t)
	if d <= 0 {
		return nil
	}
	timer := time.NewTimer(d)
	defer timer.Stop()
	select {
	case <-ctx.Done():
		return ctx.Err()
	case <-timer.C:
		return nil
	}
}

const (
	headerAttempt   = "x-attempt"
	headerNotBefore = "x-not-before"
	headerError     = "x-error"
)

func forward(m kafka.Message, attempt int, notBefore time.Time, cause error) kafka.Message {
	headers := []kafka.Header{
		{Key: headerAttempt, Value: []byte(strconv.Itoa(attempt))},
		{Key: headerError, Value: []byte(cause.Error())},
	}
	if !notBefore.IsZero() {
		headers = append(headers, kafka.Header{Key: headerNotBefore, Value: []byte(notBefore.Format(time.RFC3339Nano))})
	}
	return kafka.Message{Key: m.Key, Value: m.Value, Headers: headers}
}

func attemptOf(m kafka.Message) int {
	for _, h := range m.Headers {
		if h.Key == headerAttempt {
			if n, err := strconv.Atoi(string(h.Value)); err == nil && n > 0 {
				return n
			}
		}
	}
	return 1
}

func notBeforeOf(m kafka.Message) (time.Time, bool) {
	for _, h := range m.Headers {
		if h.Key == headerNotBefore {
			t, err := time.Parse(time.RFC3339Nano, string(h.Value))
			return t, err == nil
		}
	}
	return time.Time{}, false
}

package config

import (
	"fmt"
	"os"
	"strings"
	"time"
)

// platformDefault marks a value that the platform injects into the pod
// environment. It is the same for every service of the cluster.
const platformDefault = "${PLATFORM_DEFAULT}"

const (
	envProcessingTimeout = "PLATFORM_CONSUMER_PROCESSING_TIMEOUT"
	envRetryBackoff      = "PLATFORM_CONSUMER_RETRY_BACKOFF"
)

type Consumers struct {
	Service   string           `yaml:"service"`
	Kafka     Kafka            `yaml:"kafka"`
	Defaults  ConsumerSettings `yaml:"defaults"`
	Consumers []Consumer       `yaml:"consumers"`
}

type Kafka struct {
	Cluster string `yaml:"cluster"`
}

// Brokers returns the broker list of the cluster, e.g. KAFKA_MAIN_EVENTS_BROKERS
// for cluster main-events.
func (k Kafka) Brokers() []string {
	env := "KAFKA_" + strings.ToUpper(strings.ReplaceAll(k.Cluster, "-", "_")) + "_BROKERS"
	return strings.Split(os.Getenv(env), ",")
}

type ConsumerSettings struct {
	AutoOffsetReset string        `yaml:"auto_offset_reset"`
	SessionTimeout  time.Duration `yaml:"session_timeout"`
	Commit          Commit        `yaml:"commit"`
	Processing      Processing    `yaml:"processing"`
	Retry           Retry         `yaml:"retry"`
}

type Commit struct {
	Mode     string        `yaml:"mode"`
	Interval time.Duration `yaml:"interval"`
}

type Processing struct {
	Ordering          string        `yaml:"ordering"`
	ProcessingTimeout string        `yaml:"processing_timeout"`
	ShutdownGrace     time.Duration `yaml:"shutdown_grace"`
	Concurrency       int           `yaml:"concurrency"`
}

type Retry struct {
	MaxAttempts int    `yaml:"max_attempts"`
	Backoff     string `yaml:"backoff"`
	Topic       string `yaml:"topic"`
	DLQ         string `yaml:"dlq"`
}

type Consumer struct {
	Name       string     `yaml:"name"`
	Topic      string     `yaml:"topic"`
	Group      string     `yaml:"group"`
	Handler    string     `yaml:"handler"`
	Partitions int        `yaml:"partitions"`
	Processing Processing `yaml:"processing"`
	Retry      Retry      `yaml:"retry"`
}

// Effective is a consumer with defaults applied and platform values resolved.
type Effective struct {
	Consumer
	AutoOffsetReset   string
	SessionTimeout    time.Duration
	CommitInterval    time.Duration
	Concurrency       int
	ProcessingTimeout time.Duration
	MaxAttempts       int
	Backoff           []time.Duration
}

func LoadConsumers(path string) (Consumers, error) {
	var cfg Consumers
	if err := load(path, &cfg, false); err != nil {
		return Consumers{}, err
	}
	return cfg, nil
}

func (f Consumers) Effective(c Consumer) (Effective, error) {
	d := f.Defaults
	e := Effective{
		Consumer:        c,
		AutoOffsetReset: d.AutoOffsetReset,
		SessionTimeout:  d.SessionTimeout,
		CommitInterval:  d.Commit.Interval,
		Concurrency:     first(c.Processing.Concurrency, d.Processing.Concurrency, 1),
		MaxAttempts:     first(c.Retry.MaxAttempts, d.Retry.MaxAttempts, 1),
	}

	timeout, err := resolve(firstString(c.Processing.ProcessingTimeout, d.Processing.ProcessingTimeout), envProcessingTimeout)
	if err != nil {
		return Effective{}, fmt.Errorf("consumer %s: processing_timeout: %w", c.Name, err)
	}
	if e.ProcessingTimeout, err = time.ParseDuration(timeout); err != nil {
		return Effective{}, fmt.Errorf("consumer %s: processing_timeout: %w", c.Name, err)
	}

	backoff, err := resolve(firstString(c.Retry.Backoff, d.Retry.Backoff), envRetryBackoff)
	if err != nil {
		return Effective{}, fmt.Errorf("consumer %s: retry.backoff: %w", c.Name, err)
	}
	for _, s := range strings.Split(backoff, ",") {
		b, err := time.ParseDuration(strings.TrimSpace(s))
		if err != nil {
			return Effective{}, fmt.Errorf("consumer %s: retry.backoff: %w", c.Name, err)
		}
		e.Backoff = append(e.Backoff, b)
	}
	return e, nil
}

func resolve(value, env string) (string, error) {
	if value != platformDefault {
		return value, nil
	}
	v := os.Getenv(env)
	if v == "" {
		return "", fmt.Errorf("%s is not set", env)
	}
	return v, nil
}

func first(vs ...int) int {
	for _, v := range vs {
		if v != 0 {
			return v
		}
	}
	return 0
}

func firstString(vs ...string) string {
	for _, v := range vs {
		if v != "" {
			return v
		}
	}
	return ""
}

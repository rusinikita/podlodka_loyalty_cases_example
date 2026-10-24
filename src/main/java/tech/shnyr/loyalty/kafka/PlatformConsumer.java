package tech.shnyr.loyalty.kafka;

import static net.logstash.logback.argument.StructuredArguments.kv;

import java.nio.charset.StandardCharsets;
import java.time.Duration;
import java.time.Instant;
import java.time.OffsetDateTime;
import java.time.format.DateTimeParseException;
import java.util.ArrayList;
import java.util.Arrays;
import java.util.List;
import java.util.Map;
import java.util.concurrent.ExecutionException;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.Future;
import java.util.concurrent.SynchronousQueue;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.TimeoutException;

import org.apache.kafka.clients.consumer.ConsumerConfig;
import org.apache.kafka.clients.consumer.ConsumerRecord;
import org.apache.kafka.clients.producer.ProducerConfig;
import org.apache.kafka.clients.producer.ProducerRecord;
import org.apache.kafka.common.header.Header;
import org.apache.kafka.common.header.internals.RecordHeader;
import org.apache.kafka.common.serialization.ByteArrayDeserializer;
import org.apache.kafka.common.serialization.ByteArraySerializer;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.kafka.core.DefaultKafkaConsumerFactory;
import org.springframework.kafka.core.DefaultKafkaProducerFactory;
import org.springframework.kafka.core.KafkaTemplate;
import org.springframework.kafka.listener.AcknowledgingMessageListener;
import org.springframework.kafka.listener.ConcurrentMessageListenerContainer;
import org.springframework.kafka.listener.ContainerProperties;
import org.springframework.kafka.support.Acknowledgment;

import tech.shnyr.loyalty.config.ConsumersConfig;

/**
 * Consumer runtime required by the platform: commit after handle, per-key
 * ordering, a processing deadline per message, a retry topic with backoff and
 * a DLQ after the last attempt.
 */
public class PlatformConsumer {

    private static final Logger log = LoggerFactory.getLogger(PlatformConsumer.class);

    private static final String HEADER_ATTEMPT = "x-attempt";
    private static final String HEADER_NOT_BEFORE = "x-not-before";
    private static final String HEADER_ERROR = "x-error";

    private static final Duration FORWARD_RETRY_DELAY = Duration.ofSeconds(1);

    private final ConsumersConfig.Effective cfg;
    private final MessageHandler handler;
    private final DefaultKafkaProducerFactory<byte[], byte[]> producerFactory;
    private final KafkaTemplate<byte[], byte[]> producer;
    private final ExecutorService handlers = Executors.newVirtualThreadPerTaskExecutor();
    private final List<Reader> readers = new ArrayList<>();
    private volatile boolean running;

    public PlatformConsumer(List<String> brokers, ConsumersConfig.Effective cfg, MessageHandler handler) {
        this.cfg = cfg;
        this.handler = handler;
        this.producerFactory = new DefaultKafkaProducerFactory<>(Map.of(
                ProducerConfig.BOOTSTRAP_SERVERS_CONFIG, brokers,
                ProducerConfig.ACKS_CONFIG, "all",
                ProducerConfig.KEY_SERIALIZER_CLASS_CONFIG, ByteArraySerializer.class,
                ProducerConfig.VALUE_SERIALIZER_CLASS_CONFIG, ByteArraySerializer.class));
        this.producer = new KafkaTemplate<>(producerFactory);

        DefaultKafkaConsumerFactory<byte[], byte[]> consumerFactory = new DefaultKafkaConsumerFactory<>(Map.of(
                ConsumerConfig.BOOTSTRAP_SERVERS_CONFIG, brokers,
                ConsumerConfig.AUTO_OFFSET_RESET_CONFIG, cfg.autoOffsetReset(),
                ConsumerConfig.SESSION_TIMEOUT_MS_CONFIG, (int) cfg.sessionTimeout().toMillis(),
                ConsumerConfig.ENABLE_AUTO_COMMIT_CONFIG, false,
                ConsumerConfig.KEY_DESERIALIZER_CLASS_CONFIG, ByteArrayDeserializer.class,
                ConsumerConfig.VALUE_DESERIALIZER_CLASS_CONFIG, ByteArrayDeserializer.class));
        readers.add(new Reader(consumerFactory, cfg.topic(), cfg.group()));
        readers.add(new Reader(consumerFactory, cfg.retryTopic(), cfg.group() + "-retry"));
    }

    /**
     * Starts consuming the main and the retry topic.
     */
    public void start() {
        running = true;
        readers.forEach(Reader::start);
    }

    public void stop() {
        running = false;
        readers.forEach(Reader::stop);
        handlers.shutdownNow();
        producerFactory.destroy();
    }

    /**
     * Reads one topic. Messages with the same key always go to the same slot,
     * so they are handled in order; acknowledgments come out of order and the
     * container commits an offset only when every message before it is done.
     */
    private final class Reader {

        private final ConcurrentMessageListenerContainer<byte[], byte[]> container;
        private final List<SynchronousQueue<Runnable>> slots = new ArrayList<>();
        private final List<Thread> workers = new ArrayList<>();

        Reader(DefaultKafkaConsumerFactory<byte[], byte[]> consumerFactory, String topic, String group) {
            for (int i = 0; i < cfg.concurrency(); i++) {
                SynchronousQueue<Runnable> slot = new SynchronousQueue<>();
                slots.add(slot);
                workers.add(Thread.ofVirtual().name(topic + "-slot-" + i).unstarted(() -> drain(slot)));
            }

            ContainerProperties props = new ContainerProperties(topic);
            props.setGroupId(group);
            props.setAckMode(ContainerProperties.AckMode.MANUAL);
            props.setAsyncAcks(true);
            props.setMessageListener((AcknowledgingMessageListener<byte[], byte[]>) this::dispatch);
            container = new ConcurrentMessageListenerContainer<>(consumerFactory, props);
            container.setBeanName(cfg.name() + ":" + topic);
        }

        void start() {
            workers.forEach(Thread::start);
            container.start();
        }

        void stop() {
            workers.forEach(Thread::interrupt);
            container.stop();
        }

        private void dispatch(ConsumerRecord<byte[], byte[]> r, Acknowledgment ack) {
            SynchronousQueue<Runnable> slot = slots.get(Math.floorMod(Arrays.hashCode(r.key()), slots.size()));
            Runnable task = () -> {
                try {
                    process(r);
                    ack.acknowledge();
                } catch (InterruptedException e) {
                    // Shutting down: the offset stays uncommitted, the
                    // message is redelivered after restart.
                    Thread.currentThread().interrupt();
                }
            };
            try {
                while (!slot.offer(task, 100, TimeUnit.MILLISECONDS)) {
                    if (!running) {
                        return;
                    }
                }
            } catch (InterruptedException e) {
                Thread.currentThread().interrupt();
            }
        }

        private void drain(SynchronousQueue<Runnable> slot) {
            while (!Thread.currentThread().isInterrupted()) {
                try {
                    slot.take().run();
                } catch (InterruptedException e) {
                    return;
                }
            }
        }
    }

    private void process(ConsumerRecord<byte[], byte[]> r) throws InterruptedException {
        int attempt = attemptOf(r);
        Instant notBefore = notBeforeOf(r);
        if (notBefore != null) {
            long wait = Duration.between(Instant.now(), notBefore).toMillis();
            if (wait > 0) {
                Thread.sleep(wait);
            }
        }

        Message msg = new Message(r.topic(), r.partition(), r.offset(), r.key(), r.value(), attempt);
        Future<?> run = handlers.submit(() -> {
            handler.handle(msg);
            return null;
        });

        Throwable failure;
        try {
            run.get(cfg.processingTimeout().toNanos(), TimeUnit.NANOSECONDS);
            return;
        } catch (TimeoutException e) {
            run.cancel(true);
            failure = new TimeoutException("processing deadline exceeded");
        } catch (ExecutionException e) {
            failure = e.getCause();
        } catch (InterruptedException e) {
            run.cancel(true);
            throw e;
        }
        forward(r, attempt, failure);
    }

    private void forward(ConsumerRecord<byte[], byte[]> r, int attempt, Throwable cause) throws InterruptedException {
        Object[] fields = {kv("topic", r.topic()), kv("partition", r.partition()), kv("offset", r.offset()),
                kv("attempt", attempt), kv("err", String.valueOf(cause))};

        if (attempt >= cfg.maxAttempts()) {
            log.error("message sent to dlq", fields);
            send(new ProducerRecord<>(cfg.dlqTopic(), null, r.key(), r.value(), headers(attempt, null, cause)));
            return;
        }

        log.warn("message sent to retry", fields);
        Instant notBefore = Instant.now().plus(backoff(attempt));
        send(new ProducerRecord<>(cfg.retryTopic(), null, r.key(), r.value(), headers(attempt + 1, notBefore, cause)));
    }

    // The message is committed only after it is forwarded, so a failed write is
    // repeated until it succeeds or the consumer stops.
    private void send(ProducerRecord<byte[], byte[]> record) throws InterruptedException {
        while (true) {
            try {
                producer.send(record).get();
                return;
            } catch (ExecutionException e) {
                log.error("forward failed", kv("topic", record.topic()), kv("err", String.valueOf(e.getCause())));
                Thread.sleep(FORWARD_RETRY_DELAY);
            }
        }
    }

    private Duration backoff(int attempt) {
        List<Duration> b = cfg.backoff();
        if (b.isEmpty()) {
            return Duration.ZERO;
        }
        return b.get(Math.min(attempt - 1, b.size() - 1));
    }

    private static List<Header> headers(int attempt, Instant notBefore, Throwable cause) {
        List<Header> headers = new ArrayList<>();
        headers.add(header(HEADER_ATTEMPT, Integer.toString(attempt)));
        headers.add(header(HEADER_ERROR, String.valueOf(cause)));
        if (notBefore != null) {
            headers.add(header(HEADER_NOT_BEFORE, notBefore.toString()));
        }
        return headers;
    }

    private static Header header(String key, String value) {
        return new RecordHeader(key, value.getBytes(StandardCharsets.UTF_8));
    }

    private static int attemptOf(ConsumerRecord<?, ?> r) {
        Header h = r.headers().lastHeader(HEADER_ATTEMPT);
        if (h == null) {
            return 1;
        }
        try {
            int n = Integer.parseInt(new String(h.value(), StandardCharsets.UTF_8));
            return n > 0 ? n : 1;
        } catch (NumberFormatException e) {
            return 1;
        }
    }

    private static Instant notBeforeOf(ConsumerRecord<?, ?> r) {
        Header h = r.headers().lastHeader(HEADER_NOT_BEFORE);
        if (h == null) {
            return null;
        }
        try {
            return OffsetDateTime.parse(new String(h.value(), StandardCharsets.UTF_8)).toInstant();
        } catch (DateTimeParseException e) {
            return null;
        }
    }
}

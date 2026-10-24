package tech.shnyr.loyalty.config;

import java.io.IOException;
import java.nio.file.Path;
import java.time.Duration;
import java.util.Arrays;
import java.util.List;
import java.util.Locale;

import com.fasterxml.jackson.annotation.JsonIgnoreProperties;
import com.fasterxml.jackson.databind.PropertyNamingStrategies;
import com.fasterxml.jackson.dataformat.yaml.YAMLMapper;
import org.springframework.boot.convert.DurationStyle;

/**
 * config/consumers.yaml, the consumer file of the platform.
 */
@JsonIgnoreProperties(ignoreUnknown = true)
public record ConsumersConfig(String service, Kafka kafka, Settings defaults, List<Consumer> consumers) {

    // Marks a value that the platform injects into the pod environment. It is
    // the same for every service of the cluster.
    private static final String PLATFORM_DEFAULT = "${PLATFORM_DEFAULT}";

    private static final String ENV_PROCESSING_TIMEOUT = "PLATFORM_CONSUMER_PROCESSING_TIMEOUT";
    private static final String ENV_RETRY_BACKOFF = "PLATFORM_CONSUMER_RETRY_BACKOFF";

    public static ConsumersConfig load(Path path) throws IOException {
        YAMLMapper mapper = YAMLMapper.builder()
                .propertyNamingStrategy(PropertyNamingStrategies.SNAKE_CASE)
                .build();
        return mapper.readValue(path.toFile(), ConsumersConfig.class);
    }

    @JsonIgnoreProperties(ignoreUnknown = true)
    public record Kafka(String cluster) {

        /**
         * The broker list of the cluster, e.g. KAFKA_MAIN_EVENTS_BROKERS for
         * cluster main-events.
         */
        public List<String> brokers() {
            String env = "KAFKA_" + cluster.replace('-', '_').toUpperCase(Locale.ROOT) + "_BROKERS";
            return Arrays.asList(System.getenv().getOrDefault(env, "").split(","));
        }
    }

    @JsonIgnoreProperties(ignoreUnknown = true)
    public record Settings(String autoOffsetReset, String sessionTimeout, Commit commit, Processing processing,
                           Retry retry) {
    }

    @JsonIgnoreProperties(ignoreUnknown = true)
    public record Commit(String mode, String interval) {
    }

    @JsonIgnoreProperties(ignoreUnknown = true)
    public record Processing(String ordering, String processingTimeout, String shutdownGrace, int concurrency) {
    }

    @JsonIgnoreProperties(ignoreUnknown = true)
    public record Retry(int maxAttempts, String backoff, String topic, String dlq) {
    }

    @JsonIgnoreProperties(ignoreUnknown = true)
    public record Consumer(String name, String topic, String group, String handler, int partitions,
                           Processing processing, Retry retry) {
    }

    /**
     * A consumer with defaults applied and platform values resolved.
     */
    public record Effective(String name, String topic, String group, String retryTopic, String dlqTopic,
                            String autoOffsetReset, Duration sessionTimeout, int concurrency,
                            Duration processingTimeout, int maxAttempts, List<Duration> backoff) {
    }

    public Effective effective(Consumer c) {
        Processing p = c.processing() != null ? c.processing() : new Processing(null, null, null, 0);
        Retry r = c.retry() != null ? c.retry() : new Retry(0, null, null, null);
        Settings d = defaults;

        String timeout = resolve(first(p.processingTimeout(), d.processing().processingTimeout()),
                ENV_PROCESSING_TIMEOUT, c.name(), "processing_timeout");
        String backoff = resolve(first(r.backoff(), d.retry().backoff()),
                ENV_RETRY_BACKOFF, c.name(), "retry.backoff");

        return new Effective(
                c.name(),
                c.topic(),
                c.group(),
                r.topic(),
                r.dlq(),
                d.autoOffsetReset(),
                DurationStyle.SIMPLE.parse(d.sessionTimeout()),
                first(p.concurrency(), d.processing().concurrency(), 1),
                DurationStyle.SIMPLE.parse(timeout),
                first(r.maxAttempts(), d.retry().maxAttempts(), 1),
                Arrays.stream(backoff.split(",")).map(s -> DurationStyle.SIMPLE.parse(s.trim())).toList());
    }

    private static String resolve(String value, String env, String consumer, String field) {
        if (!PLATFORM_DEFAULT.equals(value)) {
            return value;
        }
        String v = System.getenv(env);
        if (v == null || v.isEmpty()) {
            throw new IllegalStateException("consumer %s: %s: %s is not set".formatted(consumer, field, env));
        }
        return v;
    }

    private static int first(int... values) {
        for (int v : values) {
            if (v != 0) {
                return v;
            }
        }
        return 0;
    }

    private static String first(String... values) {
        for (String v : values) {
            if (v != null && !v.isEmpty()) {
                return v;
            }
        }
        return "";
    }
}

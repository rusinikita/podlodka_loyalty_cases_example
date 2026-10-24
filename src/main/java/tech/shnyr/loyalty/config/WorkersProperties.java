package tech.shnyr.loyalty.config;

import java.time.Duration;
import java.util.List;

import org.springframework.boot.context.properties.ConfigurationProperties;
import org.springframework.boot.convert.DurationStyle;

/**
 * Periodic jobs from config/workers.yaml.
 */
@ConfigurationProperties
public record WorkersProperties(List<Worker> workers) {

    public WorkersProperties {
        workers = workers == null ? List.of() : List.copyOf(workers);
    }

    public record Worker(String name, String schedule, boolean runOnStart, int batchSize) {

        /**
         * Parses a schedule of the form "every &lt;duration&gt;".
         */
        public Duration interval() {
            if (schedule == null || !schedule.startsWith("every ")) {
                throw new IllegalArgumentException("worker %s: unsupported schedule \"%s\"".formatted(name, schedule));
            }
            return DurationStyle.SIMPLE.parse(schedule.substring("every ".length()).trim());
        }
    }
}

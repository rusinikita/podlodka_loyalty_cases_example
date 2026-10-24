package tech.shnyr.loyalty.worker;

import static net.logstash.logback.argument.StructuredArguments.kv;

import java.time.Duration;
import java.util.Map;
import java.util.function.IntConsumer;

import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Configuration;
import org.springframework.scheduling.annotation.EnableScheduling;
import org.springframework.scheduling.annotation.SchedulingConfigurer;
import org.springframework.scheduling.concurrent.ThreadPoolTaskScheduler;
import org.springframework.scheduling.config.FixedDelayTask;
import org.springframework.scheduling.config.ScheduledTaskRegistrar;

import tech.shnyr.loyalty.config.WorkersProperties;
import tech.shnyr.loyalty.referrals.ReferralService;

/**
 * Periodic jobs inside the service process, one per entry of
 * config/workers.yaml. With run_on_start the first run happens right away,
 * otherwise after the first interval. A failed run is logged; the next one
 * happens on schedule.
 */
@Configuration
@EnableScheduling
public class WorkersConfiguration implements SchedulingConfigurer {

    private static final Logger log = LoggerFactory.getLogger(WorkersConfiguration.class);

    private final WorkersProperties workers;
    private final Map<String, IntConsumer> jobs;

    public WorkersConfiguration(WorkersProperties workers, ReferralService referrals) {
        this.workers = workers;
        this.jobs = Map.of(
                "reward-payouts", referrals::payPending);
    }

    @Bean
    ThreadPoolTaskScheduler workerScheduler() {
        ThreadPoolTaskScheduler scheduler = new ThreadPoolTaskScheduler();
        scheduler.setPoolSize(Math.max(1, workers.workers().size()));
        scheduler.setThreadNamePrefix("worker-");
        return scheduler;
    }

    @Override
    public void configureTasks(ScheduledTaskRegistrar registrar) {
        registrar.setTaskScheduler(workerScheduler());
        for (WorkersProperties.Worker w : workers.workers()) {
            IntConsumer job = jobs.get(w.name());
            if (job == null) {
                throw new IllegalStateException("unknown worker \"%s\"".formatted(w.name()));
            }
            Duration interval = w.interval();
            Duration initialDelay = w.runOnStart() ? Duration.ZERO : interval;
            registrar.addFixedDelayTask(new FixedDelayTask(
                    () -> runOnce(w.name(), () -> job.accept(w.batchSize())), interval, initialDelay));
        }
    }

    private static void runOnce(String name, Runnable job) {
        long start = System.nanoTime();
        try {
            job.run();
        } catch (RuntimeException e) {
            if (!Thread.currentThread().isInterrupted()) {
                log.error("worker run failed", kv("worker", name), kv("took", took(start)), e);
            }
            return;
        }
        log.info("worker run finished", kv("worker", name), kv("took", took(start)));
    }

    private static Duration took(long start) {
        return Duration.ofNanos(System.nanoTime() - start);
    }
}

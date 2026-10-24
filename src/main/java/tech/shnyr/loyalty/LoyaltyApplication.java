package tech.shnyr.loyalty;

import java.time.Clock;

import org.springframework.boot.SpringApplication;
import org.springframework.boot.autoconfigure.SpringBootApplication;
import org.springframework.boot.autoconfigure.kafka.KafkaAutoConfiguration;
import org.springframework.boot.context.properties.ConfigurationPropertiesScan;
import org.springframework.context.annotation.Bean;

/**
 * The loyalty service: HTTP API, Kafka consumers and workers in one process.
 * Configuration is read from config/*.yaml, see application.yaml.
 */
@SpringBootApplication(exclude = KafkaAutoConfiguration.class)
@ConfigurationPropertiesScan
public class LoyaltyApplication {

    public static void main(String[] args) {
        SpringApplication.run(LoyaltyApplication.class, args);
    }

    @Bean
    Clock clock() {
        return Clock.systemDefaultZone();
    }
}

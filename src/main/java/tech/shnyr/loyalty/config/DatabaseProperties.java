package tech.shnyr.loyalty.config;

import org.springframework.boot.context.properties.ConfigurationProperties;

@ConfigurationProperties("database")
public record DatabaseProperties(String dsn, int poolSize) {
}

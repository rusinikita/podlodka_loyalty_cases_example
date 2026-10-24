package tech.shnyr.loyalty.config;

import java.time.Duration;

import org.springframework.boot.context.properties.ConfigurationProperties;

@ConfigurationProperties("clients")
public record ClientsProperties(Client payoutGateway, Client notifications) {

    public record Client(String baseUrl, Duration timeout) {
    }
}

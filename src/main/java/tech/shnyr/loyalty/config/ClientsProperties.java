package tech.shnyr.loyalty.config;

import java.time.Duration;

import org.springframework.boot.context.properties.ConfigurationProperties;

@ConfigurationProperties("clients")
public record ClientsProperties(Client payoutGateway, Client notifications, Client antifraud) {

    public record Client(String baseUrl, Duration timeout) {
    }
}

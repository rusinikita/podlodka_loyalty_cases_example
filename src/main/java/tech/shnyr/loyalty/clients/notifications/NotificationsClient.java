package tech.shnyr.loyalty.clients.notifications;

import org.springframework.beans.factory.annotation.Value;
import org.springframework.http.HttpStatus;
import org.springframework.http.MediaType;
import org.springframework.http.client.JdkClientHttpRequestFactory;
import org.springframework.stereotype.Component;
import org.springframework.web.client.RestClient;

import tech.shnyr.loyalty.config.ClientsProperties;

/**
 * Client of the CRM notifications service (api/notifications.yaml).
 */
@Component
public class NotificationsClient {

    private final RestClient http;

    public NotificationsClient(RestClient.Builder builder, ClientsProperties clients,
                               @Value("${LOYALTY_SERVICE_TOKEN:}") String token) {
        ClientsProperties.Client cfg = clients.notifications();
        JdkClientHttpRequestFactory factory = new JdkClientHttpRequestFactory();
        factory.setReadTimeout(cfg.timeout());
        this.http = builder
                .baseUrl(cfg.baseUrl())
                .requestFactory(factory)
                .defaultHeader("X-Service-Token", token)
                .build();
    }

    public void push(Push push) {
        http.post()
                .uri("/v1/push")
                .contentType(MediaType.APPLICATION_JSON)
                .body(push)
                .exchange((req, resp) -> {
                    if (!resp.getStatusCode().isSameCodeAs(HttpStatus.ACCEPTED)) {
                        throw new IllegalStateException("notifications: unexpected status " + resp.getStatusCode().value());
                    }
                    return null;
                });
    }
}

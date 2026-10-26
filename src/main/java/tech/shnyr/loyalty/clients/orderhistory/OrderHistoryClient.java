package tech.shnyr.loyalty.clients.orderhistory;

import com.fasterxml.jackson.annotation.JsonIgnoreProperties;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.http.HttpStatus;
import org.springframework.http.client.JdkClientHttpRequestFactory;
import org.springframework.stereotype.Component;
import org.springframework.web.client.RestClient;

import tech.shnyr.loyalty.config.ClientsProperties;

/**
 * Client of the order history service (api/order-history.yaml).
 */
@Component
public class OrderHistoryClient {

    private final RestClient http;

    public OrderHistoryClient(RestClient.Builder builder, ClientsProperties clients,
                              @Value("${LOYALTY_SERVICE_TOKEN:}") String token) {
        ClientsProperties.Client cfg = clients.orderHistory();
        JdkClientHttpRequestFactory factory = new JdkClientHttpRequestFactory();
        factory.setReadTimeout(cfg.timeout());
        this.http = builder
                .baseUrl(cfg.baseUrl())
                .requestFactory(factory)
                .defaultHeader("X-Service-Token", token)
                .build();
    }

    /**
     * The number of the user's paid and completed orders for all time, of
     * every channel and payment method. An unknown user has none.
     */
    public int countOrders(long userId) {
        return http.get()
                .uri("/v1/users/{id}/orders/count", userId)
                .exchange((req, resp) -> {
                    if (resp.getStatusCode().isSameCodeAs(HttpStatus.OK)) {
                        return resp.bodyTo(OrdersCount.class).count();
                    }
                    if (resp.getStatusCode().isSameCodeAs(HttpStatus.NOT_FOUND)) {
                        return 0;
                    }

                    ErrorBody err = null;
                    try {
                        err = resp.bodyTo(ErrorBody.class);
                    } catch (RuntimeException ignored) {
                        // Not a JSON error body; reported by status only.
                    }
                    if (err == null) {
                        err = new ErrorBody("", "");
                    }
                    throw new OrderHistoryException(resp.getStatusCode().value(), err.code(), err.message());
                });
    }

    @JsonIgnoreProperties(ignoreUnknown = true)
    private record OrdersCount(int count) {
    }

    @JsonIgnoreProperties(ignoreUnknown = true)
    private record ErrorBody(String code, String message) {
    }
}

package tech.shnyr.loyalty.clients.payoutgateway;

import com.fasterxml.jackson.annotation.JsonIgnoreProperties;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.http.HttpStatus;
import org.springframework.http.MediaType;
import org.springframework.http.client.JdkClientHttpRequestFactory;
import org.springframework.stereotype.Component;
import org.springframework.web.client.RestClient;

import tech.shnyr.loyalty.config.ClientsProperties;

/**
 * Client of payout-gateway (api/payout-gateway.yaml).
 */
@Component
public class PayoutGatewayClient {

    private final RestClient http;

    public PayoutGatewayClient(RestClient.Builder builder, ClientsProperties clients,
                               @Value("${LOYALTY_SERVICE_TOKEN:}") String token) {
        ClientsProperties.Client cfg = clients.payoutGateway();
        JdkClientHttpRequestFactory factory = new JdkClientHttpRequestFactory();
        factory.setReadTimeout(cfg.timeout());
        this.http = builder
                .baseUrl(cfg.baseUrl())
                .requestFactory(factory)
                .defaultHeader("X-Service-Token", token)
                .build();
    }

    /**
     * Creates a payout. A repeated call with the same idempotency key returns
     * the original payout.
     */
    public Payout pay(PayoutRequest request) {
        return http.post()
                .uri("/v1/payouts")
                .contentType(MediaType.APPLICATION_JSON)
                .body(request)
                .exchange((req, resp) -> {
                    int status = resp.getStatusCode().value();
                    if (resp.getStatusCode().isSameCodeAs(HttpStatus.OK)) {
                        return resp.bodyTo(Payout.class);
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
                    throw switch (err.code() == null ? "" : err.code()) {
                        case "no_payout_method" -> new NoPayoutMethodException(status, err.message());
                        case "user_blocked" -> new UserBlockedException(status, err.message());
                        default -> new PayoutGatewayException(status, err.code(), err.message());
                    };
                });
    }

    @JsonIgnoreProperties(ignoreUnknown = true)
    private record ErrorBody(String code, String message) {
    }
}

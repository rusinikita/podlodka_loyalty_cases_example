package tech.shnyr.loyalty.clients.antifraud;

import java.util.List;

import com.fasterxml.jackson.annotation.JsonIgnoreProperties;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.http.HttpStatus;
import org.springframework.http.MediaType;
import org.springframework.http.client.JdkClientHttpRequestFactory;
import org.springframework.stereotype.Component;
import org.springframework.web.client.RestClient;

import tech.shnyr.loyalty.config.ClientsProperties;

/**
 * Client of the antifraud verdicts API (api/antifraud.yaml).
 */
@Component
public class AntifraudClient {

    /** The largest number of rewards accepted in one request. */
    public static final int MAX_BATCH = 500;

    private static final String SOURCE = "loyalty";

    private final RestClient http;

    public AntifraudClient(RestClient.Builder builder, ClientsProperties clients,
                           @Value("${LOYALTY_SERVICE_TOKEN:}") String token) {
        ClientsProperties.Client cfg = clients.antifraud();
        JdkClientHttpRequestFactory factory = new JdkClientHttpRequestFactory();
        factory.setReadTimeout(cfg.timeout());
        this.http = builder
                .baseUrl(cfg.baseUrl())
                .requestFactory(factory)
                .defaultHeader("X-Service-Token", token)
                .build();
    }

    /**
     * Verdicts for up to {@link #MAX_BATCH} rewards, in the order of the
     * request. A reward antifraud has not seen yet gets
     * {@link Verdict#PENDING} and a check is started for it.
     */
    public List<RewardVerdict> verdicts(List<RewardCheck> items) {
        if (items.isEmpty()) {
            return List.of();
        }
        if (items.size() > MAX_BATCH) {
            throw new IllegalArgumentException(
                    "antifraud: batch of %d rewards, at most %d allowed".formatted(items.size(), MAX_BATCH));
        }

        VerdictsResponse out = http.post()
                .uri("/v1/referral-rewards/verdicts")
                .contentType(MediaType.APPLICATION_JSON)
                .body(new VerdictsRequest(SOURCE, items))
                .exchange((req, resp) -> {
                    if (resp.getStatusCode().isSameCodeAs(HttpStatus.OK)) {
                        return resp.bodyTo(VerdictsResponse.class);
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
                    throw new AntifraudException(resp.getStatusCode().value(), err.code(), err.message());
                });

        if (out == null || out.items() == null || out.items().size() != items.size()) {
            int got = out == null || out.items() == null ? 0 : out.items().size();
            throw new IllegalStateException("antifraud: got %d verdicts for %d rewards".formatted(got, items.size()));
        }
        return out.items();
    }

    private record VerdictsRequest(String source, List<RewardCheck> items) {
    }

    @JsonIgnoreProperties(ignoreUnknown = true)
    private record VerdictsResponse(List<RewardVerdict> items) {
    }

    @JsonIgnoreProperties(ignoreUnknown = true)
    private record ErrorBody(String code, String message) {
    }
}

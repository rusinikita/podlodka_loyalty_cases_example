package tech.shnyr.loyalty.clients.payoutgateway;

import java.time.OffsetDateTime;

import com.fasterxml.jackson.annotation.JsonIgnoreProperties;

@JsonIgnoreProperties(ignoreUnknown = true)
public record Payout(String payoutId, String status, String cardMask, OffsetDateTime createdAt) {
}

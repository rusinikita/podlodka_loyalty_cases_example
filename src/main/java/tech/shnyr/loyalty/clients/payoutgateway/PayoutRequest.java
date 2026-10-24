package tech.shnyr.loyalty.clients.payoutgateway;

public record PayoutRequest(String idempotencyKey, long userId, int amount, String currency, String purpose) {
}

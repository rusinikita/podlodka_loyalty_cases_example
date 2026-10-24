package tech.shnyr.loyalty.discounts;

/**
 * A discount granted by an operator in the admin panel.
 */
public record CompensationRequest(long userId, int percent, Integer maxAmount, int ttlDays, String operator, String comment) {
}

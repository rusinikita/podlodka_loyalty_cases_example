package tech.shnyr.loyalty.campaigns;

/**
 * Campaign search in the admin panel. query matches the slug or the promo
 * code; null trigger and active match any.
 */
public record SearchFilter(String query, Trigger trigger, Boolean active, long afterId, int limit) {
}

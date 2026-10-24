package tech.shnyr.loyalty.discounts;

/**
 * An unused discount together with the order conditions of its campaign.
 */
record Redeemable(Discount discount, OrderConditions conditions) {
}

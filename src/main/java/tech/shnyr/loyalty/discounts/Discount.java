package tech.shnyr.loyalty.discounts;

import java.time.OffsetDateTime;

import com.fasterxml.jackson.annotation.JsonIgnore;

/**
 * A row of user_discounts. Either percent (with an optional maxAmount cap) or
 * amount is set.
 */
public record Discount(
        long id,
        @JsonIgnore long userId,
        Long campaignId,
        @JsonIgnore String channel,
        @JsonIgnore String paymentId,
        Integer percent,
        Integer amount,
        Integer maxAmount,
        OffsetDateTime expiresAt,
        OffsetDateTime usedAt,
        @JsonIgnore Long usedOrderId,
        @JsonIgnore String createdBy,
        @JsonIgnore String comment,
        OffsetDateTime createdAt) {

    /**
     * The discount for an order of orderAmount.
     */
    public int value(int orderAmount) {
        if (percent != null) {
            int v = orderAmount * percent / 100;
            if (maxAmount != null && v > maxAmount) {
                v = maxAmount;
            }
            return v;
        }
        if (amount != null) {
            return Math.min(amount, orderAmount);
        }
        return 0;
    }
}

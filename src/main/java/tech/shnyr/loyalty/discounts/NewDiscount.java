package tech.shnyr.loyalty.discounts;

import java.time.OffsetDateTime;

record NewDiscount(
        long userId,
        Long campaignId,
        String channel,
        String paymentId,
        Integer percent,
        Integer amount,
        Integer maxAmount,
        OffsetDateTime expiresAt,
        String createdBy,
        String comment) {
}

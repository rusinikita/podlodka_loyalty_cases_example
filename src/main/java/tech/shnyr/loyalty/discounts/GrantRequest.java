package tech.shnyr.loyalty.discounts;

import java.time.OffsetDateTime;

/**
 * A discount granted by a campaign.
 *
 * @param sendPush send the user a push about the discount for the next order
 */
public record GrantRequest(
        long userId,
        long campaignId,
        String channel,
        String paymentId,
        int percent,
        int amount,
        int maxAmount,
        OffsetDateTime expiresAt,
        boolean sendPush) {
}

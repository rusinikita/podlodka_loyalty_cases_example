package tech.shnyr.loyalty.clients.antifraud;

import java.time.OffsetDateTime;

public record RewardCheck(long rewardId, long inviterId, long inviteeId, int amount, String currency,
                          OffsetDateTime createdAt) {
}

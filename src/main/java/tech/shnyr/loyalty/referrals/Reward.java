package tech.shnyr.loyalty.referrals;

import java.time.OffsetDateTime;

import com.fasterxml.jackson.annotation.JsonIgnore;
import com.fasterxml.jackson.annotation.JsonProperty;

/**
 * A row of referral_rewards. userId is the inviter who gets the money,
 * inviteeId is the invited friend.
 */
public record Reward(
        long id,
        @JsonIgnore long campaignId,
        @JsonIgnore long userId,
        long inviteeId,
        int amount,
        @JsonIgnore short status,
        OffsetDateTime statusChangedAt,
        @JsonIgnore String payoutId,
        OffsetDateTime createdAt) {

    @JsonProperty("status")
    public String statusName() {
        return RewardStatus.nameOf(status);
    }
}

package tech.shnyr.loyalty.campaigns;

import java.time.OffsetDateTime;

import com.fasterxml.jackson.annotation.JsonInclude;

/**
 * A row of campaigns.
 */
public record Campaign(
        long id,
        String slug,
        Trigger trigger,
        String promoCode,
        Conditions conditions,
        RewardType rewardType,
        RewardParams rewardParams,
        OffsetDateTime startsAt,
        OffsetDateTime endsAt,
        boolean isActive,
        @JsonInclude(JsonInclude.Include.NON_NULL) String createdBy,
        OffsetDateTime createdAt) {
}

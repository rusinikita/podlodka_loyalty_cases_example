package tech.shnyr.loyalty.campaigns;

import java.time.OffsetDateTime;

/**
 * A campaign from the admin panel. On update only the passed fields are
 * changed.
 */
public record CampaignInput(
        String slug,
        Trigger trigger,
        String promoCode,
        Conditions conditions,
        RewardType rewardType,
        RewardParams rewardParams,
        OffsetDateTime startsAt,
        OffsetDateTime endsAt,
        Boolean isActive) {

    Campaign applyTo(Campaign c) {
        return new Campaign(
                c.id(),
                slug != null ? slug : c.slug(),
                trigger != null ? trigger : c.trigger(),
                promoCode != null ? promoCode : c.promoCode(),
                conditions != null ? conditions : c.conditions(),
                rewardType != null ? rewardType : c.rewardType(),
                rewardParams != null ? rewardParams : c.rewardParams(),
                startsAt != null ? startsAt : c.startsAt(),
                endsAt != null ? endsAt : c.endsAt(),
                isActive != null ? isActive : c.isActive(),
                c.createdBy(),
                c.createdAt());
    }
}

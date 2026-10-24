package tech.shnyr.loyalty.campaigns;

import java.util.List;

/**
 * What fired the trigger: a promo code entered in the app, a payment or
 * a registration. Fields that the trigger does not carry are empty.
 *
 * @param channel   promo_code: deeplink or manual
 * @param invitedBy user_registered: the inviter, 0 if the user was not invited
 */
public record CampaignEvent(
        Trigger trigger,
        long userId,
        String country,
        int cityId,
        String segment,
        String channel,
        String paymentId,
        String paymentMethod,
        List<Long> skuIds,
        long invitedBy) {

    public static CampaignEvent promoCode(long userId, String country, int cityId, String segment, String channel) {
        return new CampaignEvent(Trigger.PROMO_CODE, userId, country, cityId, segment, channel, null, null, List.of(), 0);
    }

    public static CampaignEvent paymentSucceeded(long userId, String country, int cityId, String segment,
                                                 String paymentId, String paymentMethod, List<Long> skuIds) {
        return new CampaignEvent(Trigger.PAYMENT_SUCCEEDED, userId, country, cityId, segment, null,
                paymentId, paymentMethod, skuIds, 0);
    }

    public static CampaignEvent userRegistered(long userId, String country, int cityId, long invitedBy) {
        return new CampaignEvent(Trigger.USER_REGISTERED, userId, country, cityId, null, null, null, null, List.of(),
                invitedBy);
    }
}

package tech.shnyr.loyalty.campaigns;

import com.fasterxml.jackson.annotation.JsonInclude;

/**
 * Reward of a campaign. A discount: percent with an optional maxAmount cap,
 * or a fixed amount; ttlDays, or until the end of the campaign if not set.
 * A referral reward: amount and recipient.
 */
@JsonInclude(JsonInclude.Include.NON_DEFAULT)
public record RewardParams(int percent, int amount, int maxAmount, int ttlDays, String recipient) {

    public static final String RECIPIENT_INVITER = "inviter";

    public static final RewardParams NONE = new RewardParams(0, 0, 0, 0, null);
}

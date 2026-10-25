package tech.shnyr.loyalty.referrals;

import java.util.Locale;

/**
 * State of a referral reward, stored in referral_rewards.status.
 */
public enum RewardStatus {
    UNSPECIFIED,
    PENDING,
    PAID,
    WAITING_ANTIFRAUD_RESULT,
    REJECTED;

    public short code() {
        return (short) ordinal();
    }

    /**
     * The API name of a stored status: "pending", "paid".
     */
    public static String nameOf(short code) {
        RewardStatus[] all = values();
        if (code >= 0 && code < all.length) {
            return all[code].name().toLowerCase(Locale.ROOT);
        }
        return "RewardStatus(" + code + ")";
    }
}

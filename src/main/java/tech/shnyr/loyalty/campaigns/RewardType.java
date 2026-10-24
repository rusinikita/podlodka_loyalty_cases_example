package tech.shnyr.loyalty.campaigns;

import com.fasterxml.jackson.annotation.JsonValue;

public enum RewardType {
    DISCOUNT("discount"),
    REFERRAL_REWARD("referral_reward");

    private final String value;

    RewardType(String value) {
        this.value = value;
    }

    @JsonValue
    public String value() {
        return value;
    }

    public static RewardType of(String value) {
        for (RewardType t : values()) {
            if (t.value.equals(value)) {
                return t;
            }
        }
        throw new IllegalArgumentException("unknown reward type \"" + value + "\"");
    }
}

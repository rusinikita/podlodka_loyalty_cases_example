package tech.shnyr.loyalty.campaigns;

import com.fasterxml.jackson.annotation.JsonValue;

/**
 * What makes a campaign grant its reward.
 */
public enum Trigger {
    PROMO_CODE("promo_code"),
    PAYMENT_SUCCEEDED("payment_succeeded"),
    USER_REGISTERED("user_registered");

    private final String value;

    Trigger(String value) {
        this.value = value;
    }

    @JsonValue
    public String value() {
        return value;
    }

    public static Trigger of(String value) {
        for (Trigger t : values()) {
            if (t.value.equals(value)) {
                return t;
            }
        }
        throw new IllegalArgumentException("unknown trigger \"" + value + "\"");
    }
}

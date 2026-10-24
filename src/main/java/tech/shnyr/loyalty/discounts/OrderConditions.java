package tech.shnyr.loyalty.discounts;

import java.time.OffsetDateTime;
import java.time.format.DateTimeFormatter;

import com.fasterxml.jackson.annotation.JsonIgnoreProperties;

/**
 * The campaign conditions checked when a discount is used for an order.
 */
@JsonIgnoreProperties(ignoreUnknown = true)
public record OrderConditions(String timeFrom, String timeTo, int minOrderAmount) {

    private static final DateTimeFormatter HH_MM = DateTimeFormatter.ofPattern("HH:mm");

    public boolean match(int orderAmount, OffsetDateTime orderedAt) {
        if (minOrderAmount > 0 && orderAmount < minOrderAmount) {
            return false;
        }
        if (timeFrom == null || timeFrom.isEmpty() || timeTo == null || timeTo.isEmpty()) {
            return true;
        }
        String hm = orderedAt.format(HH_MM);
        if (timeFrom.compareTo(timeTo) <= 0) {
            return hm.compareTo(timeFrom) >= 0 && hm.compareTo(timeTo) < 0;
        }
        // The interval crosses midnight, e.g. 22:00–06:00.
        return hm.compareTo(timeFrom) >= 0 || hm.compareTo(timeTo) < 0;
    }
}

package tech.shnyr.loyalty.campaigns;

import java.util.List;

import com.fasterxml.jackson.annotation.JsonInclude;

/**
 * Conditions of a campaign. Grant conditions are checked by
 * {@link CampaignService} when the trigger fires; order conditions (timeFrom,
 * timeTo, minOrderAmount) are checked by discounts when the discount is used.
 */
@JsonInclude(JsonInclude.Include.NON_DEFAULT)
public record Conditions(
        List<String> countries,
        String segment,
        List<Integer> cityIds,
        int perUserLimit,
        int maxActivations,
        List<Long> skuIds,
        List<String> paymentMethods,
        boolean invited,
        boolean firstOrder,
        String timeFrom,
        String timeTo,
        int minOrderAmount) {

    public static final String SEGMENT_ALL = "all";

    public static final Conditions NONE = new Conditions(null, null, null, 0, 0, null, null, false, false, null, null, 0);

    public Conditions {
        countries = countries == null ? List.of() : List.copyOf(countries);
        cityIds = cityIds == null ? List.of() : List.copyOf(cityIds);
        skuIds = skuIds == null ? List.of() : List.copyOf(skuIds);
        paymentMethods = paymentMethods == null ? List.of() : List.copyOf(paymentMethods);
    }
}

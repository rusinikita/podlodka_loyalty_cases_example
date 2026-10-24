package tech.shnyr.loyalty.discounts;

public record Quote(Long discountId, int amount) {

    static final Quote NONE = new Quote(null, 0);
}

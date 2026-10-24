package tech.shnyr.loyalty.discounts;

public class DiscountUnavailableException extends RuntimeException {

    public DiscountUnavailableException(long discountId) {
        super("discount %d is used or expired".formatted(discountId));
    }
}

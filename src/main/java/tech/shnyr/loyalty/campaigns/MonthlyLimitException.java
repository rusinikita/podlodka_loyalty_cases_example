package tech.shnyr.loyalty.campaigns;

public class MonthlyLimitException extends RuntimeException {

    public MonthlyLimitException() {
        super("monthly promo code limit reached");
    }
}

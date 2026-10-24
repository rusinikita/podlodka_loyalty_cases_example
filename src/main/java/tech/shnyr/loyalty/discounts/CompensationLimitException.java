package tech.shnyr.loyalty.discounts;

public class CompensationLimitException extends RuntimeException {

    public CompensationLimitException() {
        super("compensation is above the limits");
    }
}

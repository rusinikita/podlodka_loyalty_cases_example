package tech.shnyr.loyalty.clients.payoutgateway;

/**
 * The user has no card to pay out to.
 */
public class NoPayoutMethodException extends PayoutGatewayException {

    public NoPayoutMethodException(int status, String message) {
        super(status, "no_payout_method", message);
    }
}

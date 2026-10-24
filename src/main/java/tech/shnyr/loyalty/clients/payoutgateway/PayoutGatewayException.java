package tech.shnyr.loyalty.clients.payoutgateway;

/**
 * An error response of payout-gateway.
 */
public class PayoutGatewayException extends RuntimeException {

    private final int status;
    private final String code;

    public PayoutGatewayException(int status, String code, String message) {
        super("payout-gateway: %d %s: %s".formatted(status, code, message));
        this.status = status;
        this.code = code;
    }

    public int status() {
        return status;
    }

    public String code() {
        return code;
    }
}

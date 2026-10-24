package tech.shnyr.loyalty.clients.payoutgateway;

public class UserBlockedException extends PayoutGatewayException {

    public UserBlockedException(int status, String message) {
        super(status, "user_blocked", message);
    }
}

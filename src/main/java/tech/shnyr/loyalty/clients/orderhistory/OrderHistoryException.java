package tech.shnyr.loyalty.clients.orderhistory;

/**
 * An error response of the order history service.
 */
public class OrderHistoryException extends RuntimeException {

    private final int status;
    private final String code;

    public OrderHistoryException(int status, String code, String message) {
        super("order-history: %d %s: %s".formatted(status, code, message));
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

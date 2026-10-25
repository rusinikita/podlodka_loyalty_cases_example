package tech.shnyr.loyalty.clients.antifraud;

/**
 * A non-200 response of the antifraud service.
 */
public class AntifraudException extends RuntimeException {

    private final int status;
    private final String code;

    public AntifraudException(int status, String code, String message) {
        super("antifraud: %d %s: %s".formatted(status, code, message));
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

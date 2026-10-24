package tech.shnyr.loyalty.campaigns;

/**
 * A grant condition of the campaign does not match the event.
 */
public class IneligibleException extends RuntimeException {

    public enum Kind {
        NOT_ELIGIBLE("not eligible"),
        WRONG_COUNTRY("campaign is not available in the user's country"),
        ALREADY_USED("per-user limit reached"),
        ACTIVATIONS_EXHAUSTED("campaign activations exhausted");

        private final String message;

        Kind(String message) {
            this.message = message;
        }
    }

    private final Kind kind;
    private final String reason;

    public IneligibleException(Kind kind, String reason) {
        super(kind.message + ": " + reason);
        this.kind = kind;
        this.reason = reason;
    }

    static IneligibleException notEligible(String reason) {
        return new IneligibleException(Kind.NOT_ELIGIBLE, reason);
    }

    public Kind kind() {
        return kind;
    }

    /**
     * The condition that did not match, e.g. "segment".
     */
    public String reason() {
        return reason;
    }
}

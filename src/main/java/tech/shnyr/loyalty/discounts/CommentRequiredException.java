package tech.shnyr.loyalty.discounts;

public class CommentRequiredException extends RuntimeException {

    public CommentRequiredException() {
        super("comment with the ticket number is required");
    }
}

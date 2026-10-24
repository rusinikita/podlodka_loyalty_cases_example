package tech.shnyr.loyalty.kafka;

/**
 * Processes one message. A normal return commits the offset. An exception, as
 * well as a handler that does not return before the processing deadline, sends
 * the message to the retry topic, and after the last attempt to the DLQ. When
 * the deadline passes, the handler thread is interrupted.
 */
public interface MessageHandler {

    void handle(Message msg) throws Exception;
}

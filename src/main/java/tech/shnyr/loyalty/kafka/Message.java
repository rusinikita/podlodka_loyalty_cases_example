package tech.shnyr.loyalty.kafka;

/**
 * One Kafka record passed to a handler.
 *
 * @param attempt 1 for the first delivery, grows with every retry
 */
public record Message(String topic, int partition, long offset, byte[] key, byte[] value, int attempt) {
}

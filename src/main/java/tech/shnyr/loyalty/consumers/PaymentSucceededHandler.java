package tech.shnyr.loyalty.consumers;

import static net.logstash.logback.argument.StructuredArguments.kv;

import java.io.IOException;
import java.time.OffsetDateTime;
import java.util.List;

import com.fasterxml.jackson.annotation.JsonIgnoreProperties;
import com.fasterxml.jackson.databind.ObjectMapper;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.stereotype.Component;

import tech.shnyr.loyalty.campaigns.CampaignEvent;
import tech.shnyr.loyalty.campaigns.CampaignService;
import tech.shnyr.loyalty.kafka.Message;
import tech.shnyr.loyalty.kafka.MessageHandler;

/**
 * Runs the payment_succeeded campaigns for a paid order.
 */
@Component
public class PaymentSucceededHandler implements MessageHandler {

    private static final Logger log = LoggerFactory.getLogger(PaymentSucceededHandler.class);

    private final CampaignService campaigns;
    private final ObjectMapper json;

    public PaymentSucceededHandler(CampaignService campaigns, ObjectMapper json) {
        this.campaigns = campaigns;
        this.json = json;
    }

    /**
     * Payload of payments.payment-succeeded (api/events/payment-succeeded.yaml).
     */
    @JsonIgnoreProperties(ignoreUnknown = true)
    record PaymentSucceeded(
            String eventId,
            OffsetDateTime occurredAt,
            String paymentId,
            long orderId,
            long userId,
            String country,
            int cityId,
            String userSegment,
            long amount,
            String currency,
            String paymentMethod,
            List<Item> items) {
    }

    @JsonIgnoreProperties(ignoreUnknown = true)
    record Item(long skuId, int quantity, long price) {
    }

    @Override
    public void handle(Message msg) {
        PaymentSucceeded evt;
        try {
            evt = json.readValue(msg.value(), PaymentSucceeded.class);
        } catch (IOException e) {
            // Redelivery will not fix a broken payload.
            log.error("malformed payment event",
                    kv("partition", msg.partition()), kv("offset", msg.offset()), kv("err", e.getMessage()));
            return;
        }
        if (evt.userId() == 0 || evt.paymentId() == null || evt.paymentId().isEmpty()) {
            log.error("incomplete payment event",
                    kv("event_id", evt.eventId()), kv("partition", msg.partition()), kv("offset", msg.offset()));
            return;
        }

        List<Long> skuIds = evt.items() == null ? List.of() : evt.items().stream().map(Item::skuId).toList();

        campaigns.handle(CampaignEvent.paymentSucceeded(
                evt.userId(),
                evt.country(),
                evt.cityId(),
                evt.userSegment(),
                evt.paymentId(),
                evt.paymentMethod(),
                skuIds));
    }
}

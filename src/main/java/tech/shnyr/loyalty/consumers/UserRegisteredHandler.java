package tech.shnyr.loyalty.consumers;

import static net.logstash.logback.argument.StructuredArguments.kv;

import java.io.IOException;
import java.time.OffsetDateTime;

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
 * Runs the user_registered campaigns for a new user.
 */
@Component
public class UserRegisteredHandler implements MessageHandler {

    private static final Logger log = LoggerFactory.getLogger(UserRegisteredHandler.class);

    private final CampaignService campaigns;
    private final ObjectMapper json;

    public UserRegisteredHandler(CampaignService campaigns, ObjectMapper json) {
        this.campaigns = campaigns;
        this.json = json;
    }

    /**
     * Payload of users.user-registered (api/events/user-registered.yaml).
     */
    @JsonIgnoreProperties(ignoreUnknown = true)
    record UserRegistered(String eventId, OffsetDateTime occurredAt, long userId, String country, int cityId,
                          Long invitedBy) {
    }

    @Override
    public void handle(Message msg) {
        UserRegistered evt;
        try {
            evt = json.readValue(msg.value(), UserRegistered.class);
        } catch (IOException e) {
            log.error("malformed registration event",
                    kv("partition", msg.partition()), kv("offset", msg.offset()), kv("err", e.getMessage()));
            return;
        }
        if (evt.userId() == 0) {
            log.error("incomplete registration event",
                    kv("event_id", evt.eventId()), kv("partition", msg.partition()), kv("offset", msg.offset()));
            return;
        }

        long invitedBy = evt.invitedBy() == null ? 0 : evt.invitedBy();
        campaigns.handle(CampaignEvent.userRegistered(evt.userId(), evt.country(), evt.cityId(), invitedBy));
    }
}

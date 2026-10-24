package tech.shnyr.loyalty.campaigns;

import static net.logstash.logback.argument.StructuredArguments.kv;

import java.util.List;

import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.stereotype.Service;

/**
 * Campaigns in the admin panel: search, creation and editing.
 */
@Service
public class CampaignAdminService {

    private static final Logger log = LoggerFactory.getLogger(CampaignAdminService.class);

    private final CampaignRepository repo;

    public CampaignAdminService(CampaignRepository repo) {
        this.repo = repo;
    }

    public Campaign get(long id) {
        return repo.byId(id).orElseThrow(() -> new CampaignNotFoundException(id));
    }

    public List<Campaign> search(SearchFilter filter) {
        return repo.search(filter);
    }

    public Campaign create(CampaignInput in, String operator) {
        Campaign c = in.applyTo(new Campaign(0, null, null, null, Conditions.NONE, null, RewardParams.NONE,
                null, null, true, operator, null));
        validate(c);
        Campaign created = repo.insert(c);
        log.info("campaign created",
                kv("campaign_id", created.id()), kv("slug", created.slug()), kv("operator", operator));
        return created;
    }

    public Campaign update(long id, CampaignInput in, String operator) {
        Campaign c = in.applyTo(get(id));
        validate(c);
        Campaign updated = repo.update(c);
        log.info("campaign updated", kv("campaign_id", id), kv("operator", operator));
        return updated;
    }

    private static void validate(Campaign c) {
        if (c.slug() == null || c.slug().isEmpty()) {
            throw new InvalidCampaignException("slug is required");
        }
        if (c.trigger() == null) {
            throw new InvalidCampaignException("trigger is required");
        }
        if (c.trigger() == Trigger.PROMO_CODE && (c.promoCode() == null || c.promoCode().isEmpty())) {
            throw new InvalidCampaignException("promo_code is required for trigger " + c.trigger().value());
        }
        if (c.rewardType() == null) {
            throw new InvalidCampaignException("reward_type is required");
        }
        switch (c.rewardType()) {
            case DISCOUNT -> {
                if (c.rewardParams().percent() == 0 && c.rewardParams().amount() == 0) {
                    throw new InvalidCampaignException("percent or amount is required");
                }
            }
            case REFERRAL_REWARD -> {
                if (c.rewardParams().amount() == 0) {
                    throw new InvalidCampaignException("amount is required");
                }
            }
        }
        if (c.startsAt() == null) {
            throw new InvalidCampaignException("starts_at is required");
        }
    }
}

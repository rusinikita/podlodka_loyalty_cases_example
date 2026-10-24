package tech.shnyr.loyalty.campaigns;

import static net.logstash.logback.argument.StructuredArguments.kv;

import java.time.Clock;
import java.time.OffsetDateTime;
import java.util.ArrayList;
import java.util.List;
import java.util.Optional;

import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.stereotype.Service;

import tech.shnyr.loyalty.campaigns.IneligibleException.Kind;
import tech.shnyr.loyalty.config.PromoProperties;
import tech.shnyr.loyalty.discounts.Discount;
import tech.shnyr.loyalty.discounts.DiscountService;
import tech.shnyr.loyalty.discounts.GrantRequest;
import tech.shnyr.loyalty.referrals.NewReward;
import tech.shnyr.loyalty.referrals.ReferralService;

/**
 * Runs loyalty campaigns: on a trigger it picks the active campaigns, checks
 * their grant conditions and grants the reward.
 */
@Service
public class CampaignService {

    private static final Logger log = LoggerFactory.getLogger(CampaignService.class);

    private final CampaignRepository repo;
    private final DiscountService discounts;
    private final ReferralService referrals;
    private final int maxPromoPerMonth;
    private final Clock clock;

    public CampaignService(CampaignRepository repo, DiscountService discounts, ReferralService referrals,
                           PromoProperties promo, Clock clock) {
        this.repo = repo;
        this.discounts = discounts;
        this.referrals = referrals;
        this.maxPromoPerMonth = promo.maxPerMonth();
        this.clock = clock;
    }

    /**
     * Processes a payment or a registration: every active campaign of the
     * trigger whose grant conditions match the event grants its reward.
     */
    public void handle(CampaignEvent ev) {
        List<Campaign> active = repo.activeByTrigger(ev.trigger(), OffsetDateTime.now(clock));

        List<Campaign> matched = new ArrayList<>();
        for (Campaign c : active) {
            try {
                checkGrant(c, ev);
            } catch (IneligibleException e) {
                log.debug("campaign skipped", kv("campaign", c.slug()), kv("user_id", ev.userId()), kv("reason", e.reason()));
                continue;
            }
            matched.add(c);
        }

        for (Campaign c : matched) {
            grant(c, ev);
        }
    }

    public Optional<Campaign> byPromoCode(String code) {
        return repo.byPromoCode(code);
    }

    /**
     * Grants the discount of a promo code campaign to the user who entered
     * the code.
     */
    public Discount applyPromoCode(Campaign c, CampaignEvent ev) {
        OffsetDateTime now = OffsetDateTime.now(clock);
        if (!c.isActive() || now.isBefore(c.startsAt())) {
            throw IneligibleException.notEligible("inactive");
        }
        if (c.endsAt() != null && !now.isBefore(c.endsAt())) {
            throw new CampaignExpiredException(c.id());
        }
        checkGrant(c, ev);

        if (discounts.countPromoUsedThisMonth(ev.userId()) >= maxPromoPerMonth) {
            throw new MonthlyLimitException();
        }
        return grantDiscount(c, ev);
    }

    /**
     * Throws {@link IneligibleException} if a grant condition of c does not
     * match the event.
     */
    private void checkGrant(Campaign c, CampaignEvent ev) {
        Conditions cond = c.conditions();

        if (!cond.countries().isEmpty() && !cond.countries().contains(ev.country())) {
            throw new IneligibleException(Kind.WRONG_COUNTRY, "country");
        }
        if (cond.segment() != null && !cond.segment().isEmpty()
                && !cond.segment().equals(Conditions.SEGMENT_ALL) && !cond.segment().equals(ev.segment())) {
            throw IneligibleException.notEligible("segment");
        }
        if (!cond.cityIds().isEmpty() && !cond.cityIds().contains(ev.cityId())) {
            throw IneligibleException.notEligible("city");
        }
        if (!cond.skuIds().isEmpty() && ev.skuIds().stream().noneMatch(cond.skuIds()::contains)) {
            throw IneligibleException.notEligible("sku");
        }
        if (!cond.paymentMethods().isEmpty() && !cond.paymentMethods().contains(ev.paymentMethod())) {
            throw IneligibleException.notEligible("payment_method");
        }
        if (cond.invited() && ev.invitedBy() == 0) {
            throw IneligibleException.notEligible("not_invited");
        }

        if (cond.perUserLimit() > 0 && discounts.countByUser(c.id(), ev.userId()) >= cond.perUserLimit()) {
            throw new IneligibleException(Kind.ALREADY_USED, "per_user_limit");
        }
        if (cond.maxActivations() > 0 && discounts.countByCampaign(c.id()) >= cond.maxActivations()) {
            throw new IneligibleException(Kind.ACTIVATIONS_EXHAUSTED, "max_activations");
        }
    }

    private void grant(Campaign c, CampaignEvent ev) {
        switch (c.rewardType()) {
            case DISCOUNT -> grantDiscount(c, ev);
            case REFERRAL_REWARD -> grantReferral(c, ev);
        }
    }

    private Discount grantDiscount(Campaign c, CampaignEvent ev) {
        RewardParams p = c.rewardParams();

        OffsetDateTime expiresAt;
        if (p.ttlDays() > 0) {
            expiresAt = OffsetDateTime.now(clock).plusDays(p.ttlDays());
        } else if (c.endsAt() != null) {
            expiresAt = c.endsAt();
        } else {
            throw new IllegalStateException("campaign %s: neither ttl_days nor ends_at is set".formatted(c.slug()));
        }

        return discounts.grant(new GrantRequest(
                ev.userId(),
                c.id(),
                ev.channel(),
                ev.paymentId(),
                p.percent(),
                p.amount(),
                p.maxAmount(),
                expiresAt,
                c.trigger() == Trigger.PAYMENT_SUCCEEDED));
    }

    private void grantReferral(Campaign c, CampaignEvent ev) {
        if (!RewardParams.RECIPIENT_INVITER.equals(c.rewardParams().recipient())) {
            log.warn("unsupported reward recipient",
                    kv("campaign", c.slug()), kv("recipient", c.rewardParams().recipient()));
            return;
        }
        referrals.create(new NewReward(c.id(), ev.invitedBy(), ev.userId(), c.rewardParams().amount()));
    }
}

package tech.shnyr.loyalty.discounts;

import static net.logstash.logback.argument.StructuredArguments.kv;

import java.time.Clock;
import java.time.OffsetDateTime;
import java.util.HashMap;
import java.util.List;
import java.util.Map;

import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.stereotype.Service;

import tech.shnyr.loyalty.clients.notifications.NotificationsClient;
import tech.shnyr.loyalty.clients.notifications.Push;
import tech.shnyr.loyalty.config.CompensationProperties;

/**
 * User discounts: granting by a campaign or as a compensation, picking a
 * discount for an order and redeeming it.
 */
@Service
public class DiscountService {

    private static final Logger log = LoggerFactory.getLogger(DiscountService.class);

    private static final String NEXT_ORDER_PUSH_TEMPLATE = "loyalty_next_order_discount";

    private final DiscountRepository repo;
    private final NotificationsClient notifications;
    private final CompensationProperties compensation;
    private final Clock clock;

    public DiscountService(DiscountRepository repo, NotificationsClient notifications,
                           CompensationProperties compensation, Clock clock) {
        this.repo = repo;
        this.notifications = notifications;
        this.compensation = compensation;
        this.clock = clock;
    }

    /**
     * Saves a discount of a campaign. With sendPush the user also gets a push
     * about the discount for the next order.
     */
    public Discount grant(GrantRequest req) {
        Discount d = repo.insert(new NewDiscount(
                req.userId(),
                req.campaignId(),
                emptyToNull(req.channel()),
                emptyToNull(req.paymentId()),
                zeroToNull(req.percent()),
                zeroToNull(req.amount()),
                zeroToNull(req.maxAmount()),
                req.expiresAt(),
                null,
                null));

        if (req.sendPush()) {
            pushNextOrder(d);
        }

        log.info("discount granted",
                kv("discount_id", d.id()), kv("user_id", d.userId()), kv("campaign_id", req.campaignId()));
        return d;
    }

    private void pushNextOrder(Discount d) {
        Map<String, String> params = new HashMap<>();
        params.put("expires_at", d.expiresAt().atZoneSameInstant(clock.getZone()).toLocalDate().toString());
        if (d.percent() != null) {
            params.put("percent", d.percent().toString());
        }
        if (d.amount() != null) {
            params.put("amount", d.amount().toString());
        }
        notifications.push(new Push(d.userId(), NEXT_ORDER_PUSH_TEMPLATE, params));
    }

    /**
     * Saves a discount given by an operator. It has no campaign; the operator
     * and the ticket number are kept in the row.
     */
    public Discount grantCompensation(CompensationRequest req) {
        if (req.percent() < 1 || req.percent() > compensation.maxPercent()
                || req.ttlDays() < 1 || req.ttlDays() > compensation.ttlDays()) {
            throw new CompensationLimitException();
        }
        if (req.comment() == null || req.comment().isBlank()) {
            throw new CommentRequiredException();
        }

        Discount d = repo.insert(new NewDiscount(
                req.userId(),
                null,
                null,
                null,
                req.percent(),
                null,
                req.maxAmount(),
                OffsetDateTime.now(clock).plusDays(req.ttlDays()),
                req.operator(),
                req.comment()));
        log.info("compensation granted",
                kv("discount_id", d.id()), kv("user_id", d.userId()),
                kv("operator", req.operator()), kv("percent", req.percent()));
        return d;
    }

    public List<Discount> active(long userId) {
        return repo.active(userId, OffsetDateTime.now(clock));
    }

    public List<Discount> byUser(long userId, long afterId, int limit) {
        return repo.byUser(userId, afterId, limit);
    }

    /**
     * Picks the user's discount for an order. Discounts do not stack: the
     * order gets the single largest one whose order conditions match.
     */
    public Quote quote(QuoteRequest req) {
        Quote best = Quote.NONE;
        for (Redeemable r : repo.redeemable(req.userId(), req.orderedAt())) {
            if (!r.conditions().match(req.orderAmount(), req.orderedAt())) {
                continue;
            }
            int v = r.discount().value(req.orderAmount());
            if (v > best.amount()) {
                best = new Quote(r.discount().id(), v);
            }
        }
        return best;
    }

    public void redeem(long discountId, long userId, long orderId) {
        if (!repo.markUsed(discountId, userId, orderId)) {
            throw new DiscountUnavailableException(discountId);
        }
    }

    public long countByCampaign(long campaignId) {
        return repo.countByCampaign(campaignId);
    }

    public long countByUser(long campaignId, long userId) {
        return repo.countByUser(campaignId, userId);
    }

    public long countPromoUsedThisMonth(long userId) {
        return repo.countPromoUsedThisMonth(userId);
    }

    private static String emptyToNull(String s) {
        return s == null || s.isEmpty() ? null : s;
    }

    private static Integer zeroToNull(int v) {
        return v == 0 ? null : v;
    }
}

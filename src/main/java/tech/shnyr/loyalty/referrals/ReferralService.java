package tech.shnyr.loyalty.referrals;

import static net.logstash.logback.argument.StructuredArguments.kv;

import java.util.List;

import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.stereotype.Service;

import tech.shnyr.loyalty.clients.payoutgateway.NoPayoutMethodException;
import tech.shnyr.loyalty.clients.payoutgateway.Payout;
import tech.shnyr.loyalty.clients.payoutgateway.PayoutGatewayClient;
import tech.shnyr.loyalty.clients.payoutgateway.PayoutRequest;

/**
 * Rewards for invited friends: creation, statuses and payouts to the
 * inviter's card through payout-gateway.
 */
@Service
public class ReferralService {

    private static final Logger log = LoggerFactory.getLogger(ReferralService.class);

    private static final String PAYOUT_CURRENCY = "RUB";
    private static final String PAYOUT_PURPOSE = "referral_reward";

    private final RewardRepository repo;
    private final PayoutGatewayClient payouts;

    public ReferralService(RewardRepository repo, PayoutGatewayClient payouts) {
        this.repo = repo;
        this.payouts = payouts;
    }

    /**
     * Saves a reward for the inviter. The payout is done later by the
     * reward-payouts worker.
     */
    public void create(NewReward reward) {
        if (!repo.insert(reward, RewardStatus.PENDING)) {
            log.info("referral reward already exists",
                    kv("campaign_id", reward.campaignId()), kv("invitee_id", reward.inviteeId()));
            return;
        }
        log.info("referral reward created",
                kv("campaign_id", reward.campaignId()), kv("user_id", reward.userId()),
                kv("invitee_id", reward.inviteeId()), kv("amount", reward.amount()));
    }

    public List<Reward> byUser(long userId, long afterId, int limit) {
        return repo.byUser(userId, afterId, limit);
    }

    public long countByCampaign(long campaignId) {
        return repo.countByCampaign(campaignId);
    }

    /**
     * Pays out pending rewards, walking them by id in batches of batchSize.
     * A reward whose owner has no card stays pending and is tried again on
     * the next run.
     */
    public void payPending(int batchSize) {
        long lastId = 0;
        int paid = 0;
        int noMethod = 0;
        int failed = 0;

        while (true) {
            List<Reward> batch = repo.byStatusAfter(RewardStatus.PENDING, lastId, batchSize);
            if (batch.isEmpty()) {
                break;
            }

            for (Reward r : batch) {
                lastId = r.id();
                try {
                    payOut(r);
                    paid++;
                } catch (NoPayoutMethodException e) {
                    noMethod++;
                    log.info("reward not paid",
                            kv("reward_id", r.id()), kv("user_id", r.userId()), kv("reason", "no_payout_method"));
                } catch (RuntimeException e) {
                    if (Thread.currentThread().isInterrupted()) {
                        throw e;
                    }
                    failed++;
                    log.warn("reward payout failed",
                            kv("reward_id", r.id()), kv("user_id", r.userId()), kv("err", e.getMessage()));
                }
            }
        }

        log.info("reward payouts done", kv("paid", paid), kv("no_payout_method", noMethod), kv("failed", failed));
    }

    private void payOut(Reward r) {
        Payout p = payouts.pay(new PayoutRequest(
                Long.toString(r.id()), r.userId(), r.amount(), PAYOUT_CURRENCY, PAYOUT_PURPOSE));
        repo.markPaid(r.id(), p.payoutId());
    }
}

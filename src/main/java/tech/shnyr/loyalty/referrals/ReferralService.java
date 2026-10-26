package tech.shnyr.loyalty.referrals;

import static net.logstash.logback.argument.StructuredArguments.kv;

import java.time.Clock;
import java.time.Duration;
import java.time.OffsetDateTime;
import java.time.temporal.ChronoUnit;
import java.util.List;

import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.stereotype.Service;

import tech.shnyr.loyalty.clients.antifraud.AntifraudClient;
import tech.shnyr.loyalty.clients.antifraud.RewardCheck;
import tech.shnyr.loyalty.clients.antifraud.RewardVerdict;
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

    /**
     * How long a reward waits for the antifraud verdict. After that it goes
     * to payout anyway: fail open, honest users must not wait for antifraud
     * longer than a day.
     */
    private static final Duration ANTIFRAUD_VERDICT_TIMEOUT = Duration.ofHours(24);

    private final RewardRepository repo;
    private final PayoutGatewayClient payouts;
    private final AntifraudClient antifraud;
    private final Clock clock;

    public ReferralService(RewardRepository repo, PayoutGatewayClient payouts, AntifraudClient antifraud,
                           Clock clock) {
        this.repo = repo;
        this.payouts = payouts;
        this.antifraud = antifraud;
        this.clock = clock;
    }

    /**
     * Saves a reward for the inviter. The reward waits for the antifraud
     * verdict (see {@link #checkAntifraud}); once approved, it is paid by the
     * reward-payouts worker.
     */
    public void create(NewReward reward) {
        if (!repo.insert(reward, RewardStatus.WAITING_ANTIFRAUD_RESULT)) {
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

    /**
     * Asks antifraud for verdicts on rewards waiting for one, walking them by
     * id in batches of batchSize. Approved rewards become pending and are
     * paid by reward-payouts, rejected ones are closed. A reward still
     * pending at antifraud after {@link #ANTIFRAUD_VERDICT_TIMEOUT} is
     * approved.
     */
    public void checkAntifraud(int batchSize) {
        batchSize = Math.min(batchSize, AntifraudClient.MAX_BATCH);

        long lastId = 0;
        int approved = 0;
        int rejected = 0;
        int failedOpen = 0;
        int waiting = 0;

        while (true) {
            List<Reward> batch = repo.byStatusAfter(RewardStatus.WAITING_ANTIFRAUD_RESULT, lastId, batchSize);
            if (batch.isEmpty()) {
                break;
            }
            lastId = batch.getLast().id();

            List<RewardVerdict> verdicts = antifraud.verdicts(batch.stream().map(ReferralService::rewardCheck).toList());

            OffsetDateTime now = OffsetDateTime.now(clock);
            for (int i = 0; i < batch.size(); i++) {
                Reward r = batch.get(i);
                RewardVerdict v = verdicts.get(i);
                if (v.rewardId() != r.id()) {
                    throw new IllegalStateException(
                            "antifraud verdict for reward %d, expected %d".formatted(v.rewardId(), r.id()));
                }

                Duration waited = Duration.between(r.statusChangedAt(), now);
                switch (v.verdict()) {
                    case APPROVED -> {
                        repo.setStatus(r.id(), RewardStatus.WAITING_ANTIFRAUD_RESULT, RewardStatus.PENDING);
                        approved++;
                    }
                    case REJECTED -> {
                        repo.setStatus(r.id(), RewardStatus.WAITING_ANTIFRAUD_RESULT, RewardStatus.REJECTED);
                        rejected++;
                        log.info("reward rejected by antifraud",
                                kv("reward_id", r.id()), kv("user_id", r.userId()), kv("reason", v.reason()));
                    }
                    case PENDING -> {
                        if (waited.compareTo(ANTIFRAUD_VERDICT_TIMEOUT) >= 0) {
                            repo.setStatus(r.id(), RewardStatus.WAITING_ANTIFRAUD_RESULT, RewardStatus.PENDING);
                            failedOpen++;
                            log.warn("no antifraud verdict in time, reward released to payout",
                                    kv("reward_id", r.id()), kv("user_id", r.userId()),
                                    kv("waiting", waited.truncatedTo(ChronoUnit.MINUTES)));
                        } else {
                            waiting++;
                        }
                    }
                }
            }
        }

        log.info("antifraud check done",
                kv("approved", approved), kv("rejected", rejected), kv("failed_open", failedOpen), kv("waiting", waiting));
    }

    private static RewardCheck rewardCheck(Reward r) {
        return new RewardCheck(r.id(), r.userId(), r.inviteeId(), r.amount(), PAYOUT_CURRENCY, r.createdAt());
    }

    private void payOut(Reward r) {
        Payout p = payouts.pay(new PayoutRequest(
                Long.toString(r.id()), r.userId(), r.amount(), PAYOUT_CURRENCY, PAYOUT_PURPOSE));
        repo.markPaid(r.id(), p.payoutId());
    }
}

<?php

declare(strict_types=1);

namespace App\Referrals;

use App\Clients\Antifraud\AntifraudClient;
use App\Clients\Antifraud\RewardCheck;
use App\Clients\Antifraud\Verdict;
use App\Clients\PayoutGateway\NoPayoutMethodException;
use App\Clients\PayoutGateway\PayoutGatewayClient;
use App\Clients\PayoutGateway\PayoutRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;
use UnexpectedValueException;

/**
 * Rewards for invited friends: creation, statuses and payouts to the
 * inviter's card through payout-gateway.
 */
final class ReferralService
{
    private const PAYOUT_CURRENCY = 'RUB';

    private const PAYOUT_PURPOSE = 'referral_reward';

    /**
     * How long a reward waits for the antifraud verdict, in hours. After that
     * it goes to payout anyway: fail open, honest users must not wait for
     * antifraud longer than a day.
     */
    private const ANTIFRAUD_VERDICT_TIMEOUT_HOURS = 24;

    public function __construct(
        private readonly RewardRepository $rewards,
        private readonly PayoutGatewayClient $payouts,
        private readonly AntifraudClient $antifraud,
    ) {}

    /**
     * Saves a reward for the inviter. The reward waits for the antifraud
     * verdict (see checkAntifraud); once approved, it is paid by the
     * reward-payouts worker.
     */
    public function create(NewReward $reward): void
    {
        if (! $this->rewards->insert($reward, RewardStatus::WaitingAntifraudResult)) {
            Log::info('referral reward already exists', [
                'campaign_id' => $reward->campaignId,
                'invitee_id' => $reward->inviteeId,
            ]);

            return;
        }

        Log::info('referral reward created', [
            'campaign_id' => $reward->campaignId,
            'user_id' => $reward->userId,
            'invitee_id' => $reward->inviteeId,
            'amount' => $reward->amount,
        ]);
    }

    /**
     * @return array<int, Reward>
     */
    public function byUser(int $userId, int $afterId, int $limit): array
    {
        return $this->rewards->byUser($userId, $afterId, $limit);
    }

    public function countByCampaign(int $campaignId): int
    {
        return $this->rewards->countByCampaign($campaignId);
    }

    /**
     * Pays out pending rewards, walking them by id in batches. A reward whose
     * owner has no card stays pending and is tried again on the next run.
     */
    public function payPending(int $batchSize): void
    {
        $lastId = 0;
        $paid = $noMethod = $failed = 0;

        while ($batch = $this->rewards->byStatusAfter(RewardStatus::Pending, $lastId, $batchSize)) {
            foreach ($batch as $reward) {
                $lastId = $reward->id;
                try {
                    $this->payOut($reward);
                    $paid++;
                } catch (NoPayoutMethodException) {
                    $noMethod++;
                    Log::info('reward not paid', [
                        'reward_id' => $reward->id,
                        'user_id' => $reward->userId,
                        'reason' => 'no_payout_method',
                    ]);
                } catch (Throwable $e) {
                    $failed++;
                    Log::warning('reward payout failed', [
                        'reward_id' => $reward->id,
                        'user_id' => $reward->userId,
                        'err' => $e->getMessage(),
                    ]);
                }
            }
        }

        Log::info('reward payouts done', ['paid' => $paid, 'no_payout_method' => $noMethod, 'failed' => $failed]);
    }

    /**
     * Asks antifraud for verdicts on rewards waiting for one, walking them by
     * id in batches. Approved rewards become pending and are paid by
     * reward-payouts, rejected ones are closed. A reward still pending at
     * antifraud after ANTIFRAUD_VERDICT_TIMEOUT_HOURS is approved.
     */
    public function checkAntifraud(int $batchSize): void
    {
        $batchSize = min($batchSize, AntifraudClient::MAX_BATCH);
        $waitingStatus = RewardStatus::WaitingAntifraudResult;

        $lastId = 0;
        $approved = $rejected = $failedOpen = $waiting = 0;

        while ($batch = $this->rewards->byStatusAfter($waitingStatus, $lastId, $batchSize)) {
            $lastId = $batch[array_key_last($batch)]->id;

            $verdicts = $this->antifraud->verdicts(array_values(array_map(self::rewardCheck(...), $batch)));

            $now = CarbonImmutable::now();
            $deadline = $now->subHours(self::ANTIFRAUD_VERDICT_TIMEOUT_HOURS);
            foreach (array_values($batch) as $i => $reward) {
                $verdict = $verdicts[$i];
                if ($verdict->rewardId !== $reward->id) {
                    throw new UnexpectedValueException(
                        "antifraud verdict for reward {$verdict->rewardId}, expected {$reward->id}",
                    );
                }

                if ($verdict->verdict === Verdict::Approved) {
                    $this->rewards->setStatus($reward->id, $waitingStatus, RewardStatus::Pending);
                    $approved++;
                } elseif ($verdict->verdict === Verdict::Rejected) {
                    $this->rewards->setStatus($reward->id, $waitingStatus, RewardStatus::Rejected);
                    $rejected++;
                    Log::info('reward rejected by antifraud', [
                        'reward_id' => $reward->id,
                        'user_id' => $reward->userId,
                        'reason' => $verdict->reason ?? '',
                    ]);
                } elseif ($reward->statusChangedAt <= $deadline) {
                    $this->rewards->setStatus($reward->id, $waitingStatus, RewardStatus::Pending);
                    $failedOpen++;
                    Log::warning('no antifraud verdict in time, reward released to payout', [
                        'reward_id' => $reward->id,
                        'user_id' => $reward->userId,
                        'waiting_min' => intdiv($now->getTimestamp() - $reward->statusChangedAt->getTimestamp(), 60),
                    ]);
                } else {
                    $waiting++;
                }
            }
        }

        Log::info('antifraud check done', [
            'approved' => $approved,
            'rejected' => $rejected,
            'failed_open' => $failedOpen,
            'waiting' => $waiting,
        ]);
    }

    private static function rewardCheck(Reward $reward): RewardCheck
    {
        return new RewardCheck(
            rewardId: $reward->id,
            inviterId: $reward->userId,
            inviteeId: $reward->inviteeId,
            amount: $reward->amount,
            currency: self::PAYOUT_CURRENCY,
            createdAt: $reward->createdAt,
        );
    }

    private function payOut(Reward $reward): void
    {
        $payout = $this->payouts->pay(new PayoutRequest(
            idempotencyKey: (string) $reward->id,
            userId: $reward->userId,
            amount: $reward->amount,
            currency: self::PAYOUT_CURRENCY,
            purpose: self::PAYOUT_PURPOSE,
        ));
        $this->rewards->markPaid($reward->id, $payout->payoutId);
    }
}

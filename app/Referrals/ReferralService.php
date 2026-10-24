<?php

declare(strict_types=1);

namespace App\Referrals;

use App\Clients\PayoutGateway\NoPayoutMethodException;
use App\Clients\PayoutGateway\PayoutGatewayClient;
use App\Clients\PayoutGateway\PayoutRequest;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rewards for invited friends: creation, statuses and payouts to the
 * inviter's card through payout-gateway.
 */
final class ReferralService
{
    private const PAYOUT_CURRENCY = 'RUB';

    private const PAYOUT_PURPOSE = 'referral_reward';

    public function __construct(
        private readonly RewardRepository $rewards,
        private readonly PayoutGatewayClient $payouts,
    ) {}

    /**
     * Saves a reward for the inviter. The payout is done later by the
     * reward-payouts worker.
     */
    public function create(NewReward $reward): void
    {
        if (! $this->rewards->insert($reward, RewardStatus::Pending)) {
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

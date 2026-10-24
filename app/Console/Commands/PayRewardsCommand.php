<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Referrals\ReferralService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Worker reward-payouts: pays out pending referral rewards.
 */
final class PayRewardsCommand extends Command
{
    protected $signature = 'rewards:pay {--batch-size=500}';

    protected $description = 'Pay out pending referral rewards';

    public function handle(ReferralService $referrals): int
    {
        $start = microtime(true);
        try {
            $referrals->payPending((int) $this->option('batch-size'));
        } catch (Throwable $e) {
            Log::error('worker run failed', [
                'worker' => 'reward-payouts',
                'err' => $e->getMessage(),
                'took' => microtime(true) - $start,
            ]);

            return self::FAILURE;
        }

        Log::info('worker run finished', ['worker' => 'reward-payouts', 'took' => microtime(true) - $start]);

        return self::SUCCESS;
    }
}

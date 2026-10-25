<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Referrals\ReferralService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Worker reward-antifraud-check: asks antifraud for verdicts on referral
 * rewards waiting for one.
 */
final class CheckRewardsAntifraudCommand extends Command
{
    protected $signature = 'rewards:check-antifraud {--batch-size=500}';

    protected $description = 'Check referral rewards with antifraud';

    public function handle(ReferralService $referrals): int
    {
        $start = microtime(true);
        try {
            $referrals->checkAntifraud((int) $this->option('batch-size'));
        } catch (Throwable $e) {
            Log::error('worker run failed', [
                'worker' => 'reward-antifraud-check',
                'err' => $e->getMessage(),
                'took' => microtime(true) - $start,
            ]);

            return self::FAILURE;
        }

        Log::info('worker run finished', ['worker' => 'reward-antifraud-check', 'took' => microtime(true) - $start]);

        return self::SUCCESS;
    }
}

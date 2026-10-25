<?php

declare(strict_types=1);

namespace App\Referrals;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use stdClass;

final class RewardRepository
{
    private const COLUMNS = 'id, campaign_id, user_id, invitee_id, amount, status, status_changed_at,
        payout_id::text AS payout_id, created_at';

    /**
     * Saves the reward unless the invitee already has one in the campaign.
     * Returns false in that case.
     */
    public function insert(NewReward $r, RewardStatus $status): bool
    {
        $inserted = DB::affectingStatement('
            INSERT INTO referral_rewards (campaign_id, user_id, invitee_id, amount, status)
            VALUES (?, ?, ?, ?, ?)
            ON CONFLICT (campaign_id, invitee_id) DO NOTHING',
            [$r->campaignId, $r->userId, $r->inviteeId, $r->amount, $status->value],
        );

        return $inserted === 1;
    }

    /**
     * @return array<int, Reward>
     */
    public function byUser(int $userId, int $afterId, int $limit): array
    {
        $rows = DB::select('
            SELECT '.self::COLUMNS.'
            FROM referral_rewards
            WHERE user_id = ? AND (CAST(? AS bigint) = 0 OR id < ?)
            ORDER BY id DESC
            LIMIT ?',
            [$userId, $afterId, $afterId, $limit],
        );

        return array_map(self::hydrate(...), $rows);
    }

    public function countByCampaign(int $campaignId): int
    {
        return (int) DB::scalar('SELECT count(*) FROM referral_rewards WHERE campaign_id = ?', [$campaignId]);
    }

    /**
     * @return array<int, Reward>
     */
    public function byStatusAfter(RewardStatus $status, int $afterId, int $limit): array
    {
        $rows = DB::select('
            SELECT '.self::COLUMNS.'
            FROM referral_rewards
            WHERE status = ? AND id > ?
            ORDER BY id
            LIMIT ?',
            [$status->value, $afterId, $limit],
        );

        return array_map(self::hydrate(...), $rows);
    }

    public function markPaid(int $id, string $payoutId): void
    {
        DB::update('
            UPDATE referral_rewards
            SET status = ?, status_changed_at = now(), payout_id = CAST(? AS uuid)
            WHERE id = ? AND status = ?',
            [RewardStatus::Paid->value, $payoutId, $id, RewardStatus::Pending->value],
        );
    }

    /**
     * Moves the reward from one status to another; a reward that is no longer
     * in $from is left as is.
     */
    public function setStatus(int $id, RewardStatus $from, RewardStatus $to): void
    {
        DB::update('
            UPDATE referral_rewards
            SET status = ?, status_changed_at = now()
            WHERE id = ? AND status = ?',
            [$to->value, $id, $from->value],
        );
    }

    private static function hydrate(stdClass $row): Reward
    {
        return new Reward(
            id: (int) $row->id,
            campaignId: (int) $row->campaign_id,
            userId: (int) $row->user_id,
            inviteeId: (int) $row->invitee_id,
            amount: (int) $row->amount,
            status: (int) $row->status,
            statusChangedAt: new DateTimeImmutable($row->status_changed_at),
            payoutId: $row->payout_id,
            createdAt: new DateTimeImmutable($row->created_at),
        );
    }
}

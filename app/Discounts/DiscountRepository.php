<?php

declare(strict_types=1);

namespace App\Discounts;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use stdClass;

final class DiscountRepository
{
    private const COLUMNS = 'd.id, d.user_id, d.campaign_id, d.channel, d.payment_id, d.percent, d.amount,
        d.max_amount, d.expires_at, d.used_at, d.used_order_id, d.created_by, d.comment, d.created_at';

    public function insert(NewDiscount $d): Discount
    {
        $row = DB::selectOne('
            INSERT INTO user_discounts AS d
                (user_id, campaign_id, channel, payment_id, percent, amount, max_amount, expires_at, created_by, comment)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            RETURNING '.self::COLUMNS,
            [
                $d->userId, $d->campaignId, $d->channel, $d->paymentId, $d->percent, $d->amount, $d->maxAmount,
                self::time($d->expiresAt), $d->createdBy, $d->comment,
            ],
        );

        return self::hydrate($row);
    }

    /**
     * @return array<int, Discount>
     */
    public function active(int $userId, DateTimeImmutable $now): array
    {
        $rows = DB::select('
            SELECT '.self::COLUMNS.'
            FROM user_discounts d
            WHERE d.user_id = ? AND d.used_at IS NULL AND d.expires_at > ?
            ORDER BY d.expires_at',
            [$userId, self::time($now)],
        );

        return array_map(self::hydrate(...), $rows);
    }

    /**
     * @return array<int, Discount>
     */
    public function byUser(int $userId, int $afterId, int $limit): array
    {
        $rows = DB::select('
            SELECT '.self::COLUMNS.'
            FROM user_discounts d
            WHERE d.user_id = ? AND (CAST(? AS bigint) = 0 OR d.id < ?)
            ORDER BY d.id DESC
            LIMIT ?',
            [$userId, $afterId, $afterId, $limit],
        );

        return array_map(self::hydrate(...), $rows);
    }

    /**
     * @return array<int, Redeemable>
     */
    public function redeemable(int $userId, DateTimeImmutable $at): array
    {
        $rows = DB::select('
            SELECT '.self::COLUMNS.', coalesce(c.conditions, \'{}\') AS conditions
            FROM user_discounts d
            LEFT JOIN campaigns c ON c.id = d.campaign_id
            WHERE d.user_id = ? AND d.used_at IS NULL AND d.expires_at > ?',
            [$userId, self::time($at)],
        );

        return array_map(static fn (stdClass $row): Redeemable => new Redeemable(
            self::hydrate($row),
            OrderConditions::fromArray(json_decode($row->conditions, true, flags: JSON_THROW_ON_ERROR)),
        ), $rows);
    }

    public function markUsed(int $id, int $userId, int $orderId): bool
    {
        $updated = DB::update('
            UPDATE user_discounts
            SET used_at = now(), used_order_id = ?
            WHERE id = ? AND user_id = ? AND used_at IS NULL AND expires_at > now()',
            [$orderId, $id, $userId],
        );

        return $updated === 1;
    }

    public function countByCampaign(int $campaignId): int
    {
        return (int) DB::scalar('SELECT count(*) FROM user_discounts WHERE campaign_id = ?', [$campaignId]);
    }

    public function countByUser(int $campaignId, int $userId): int
    {
        return (int) DB::scalar('
            SELECT count(*) FROM user_discounts WHERE campaign_id = ? AND user_id = ?',
            [$campaignId, $userId],
        );
    }

    public function countPromoUsedThisMonth(int $userId): int
    {
        return (int) DB::scalar('
            SELECT count(*)
            FROM user_discounts d
            JOIN campaigns c ON c.id = d.campaign_id
            WHERE d.user_id = ?
              AND c.trigger = \'promo_code\'
              AND d.used_at >= date_trunc(\'month\', now())',
            [$userId],
        );
    }

    private static function time(DateTimeImmutable $t): string
    {
        return $t->format(DateTimeInterface::RFC3339_EXTENDED);
    }

    private static function hydrate(stdClass $row): Discount
    {
        return new Discount(
            id: (int) $row->id,
            userId: (int) $row->user_id,
            campaignId: $row->campaign_id !== null ? (int) $row->campaign_id : null,
            channel: $row->channel,
            paymentId: $row->payment_id,
            percent: $row->percent,
            amount: $row->amount,
            maxAmount: $row->max_amount,
            expiresAt: new DateTimeImmutable($row->expires_at),
            usedAt: $row->used_at !== null ? new DateTimeImmutable($row->used_at) : null,
            usedOrderId: $row->used_order_id !== null ? (int) $row->used_order_id : null,
            createdBy: $row->created_by,
            comment: $row->comment,
            createdAt: new DateTimeImmutable($row->created_at),
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Campaigns;

use App\Campaigns\Exceptions\CampaignConflictException;
use App\Campaigns\Exceptions\CampaignNotFoundException;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use stdClass;

final class CampaignRepository
{
    private const COLUMNS = 'id, slug, trigger, promo_code, conditions, reward_type, reward_params,
        starts_at, ends_at, is_active, created_by, created_at';

    /**
     * @return array<int, Campaign>
     */
    public function activeByTrigger(Trigger $trigger, DateTimeImmutable $now): array
    {
        $at = $now->format(DateTimeInterface::RFC3339_EXTENDED);
        $rows = DB::select('
            SELECT '.self::COLUMNS.'
            FROM campaigns
            WHERE trigger = ?
              AND is_active
              AND starts_at <= ?
              AND (ends_at IS NULL OR ends_at > ?)
            ORDER BY id',
            [$trigger->value, $at, $at],
        );

        return array_map(self::hydrate(...), $rows);
    }

    /**
     * @throws CampaignNotFoundException
     */
    public function byPromoCode(string $code): Campaign
    {
        $row = DB::selectOne('
            SELECT '.self::COLUMNS.'
            FROM campaigns
            WHERE promo_code = ?',
            [$code],
        );

        return self::one($row);
    }

    /**
     * @throws CampaignNotFoundException
     */
    public function byId(int $id): Campaign
    {
        return self::one(DB::selectOne('SELECT '.self::COLUMNS.' FROM campaigns WHERE id = ?', [$id]));
    }

    /**
     * @return array<int, Campaign>
     */
    /**
     * @return array<int, Campaign>
     */
    public function search(SearchFilter $f): array
    {
        $rows = DB::table('campaigns')
            ->selectRaw(self::COLUMNS)
            ->when($f->query !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('slug', 'ilike', "%{$f->query}%")
                ->orWhere('promo_code', 'ilike', "%{$f->query}%")))
            ->when($f->trigger !== '', fn (Builder $q) => $q->where('trigger', $f->trigger))
            ->when($f->active !== null, fn (Builder $q) => $q->where('is_active', $f->active))
            ->where('id', '>', $f->afterId)
            ->orderBy('id')
            ->limit($f->limit)
            ->get();

        return $rows->map(self::hydrate(...))->values()->all();
    }

    /**
     * @throws CampaignConflictException
     */
    public function insert(CampaignInput $c, string $createdBy): Campaign
    {
        try {
            $row = DB::selectOne('
                INSERT INTO campaigns
                    (slug, trigger, promo_code, conditions, reward_type, reward_params, starts_at, ends_at, is_active, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                RETURNING '.self::COLUMNS,
                [...self::values($c), $createdBy],
            );
        } catch (UniqueConstraintViolationException) {
            throw new CampaignConflictException;
        }

        return self::one($row);
    }

    /**
     * @throws CampaignConflictException
     * @throws CampaignNotFoundException
     */
    public function update(int $id, CampaignInput $c): Campaign
    {
        try {
            $row = DB::selectOne('
                UPDATE campaigns
                SET slug = ?, trigger = ?, promo_code = ?, conditions = ?, reward_type = ?,
                    reward_params = ?, starts_at = ?, ends_at = ?, is_active = ?
                WHERE id = ?
                RETURNING '.self::COLUMNS,
                [...self::values($c), $id],
            );
        } catch (UniqueConstraintViolationException) {
            throw new CampaignConflictException;
        }

        return self::one($row);
    }

    /**
     * @return array<int, mixed>
     */
    private static function values(CampaignInput $c): array
    {
        return [
            $c->slug,
            $c->trigger,
            $c->promoCode,
            json_encode((object) $c->conditions?->toArray(), JSON_THROW_ON_ERROR),
            $c->rewardType,
            json_encode((object) $c->rewardParams?->toArray(), JSON_THROW_ON_ERROR),
            $c->startsAt?->format(DateTimeInterface::RFC3339_EXTENDED),
            $c->endsAt?->format(DateTimeInterface::RFC3339_EXTENDED),
            $c->isActive,
        ];
    }

    private static function one(?stdClass $row): Campaign
    {
        if ($row === null) {
            throw new CampaignNotFoundException;
        }

        return self::hydrate($row);
    }

    private static function hydrate(stdClass $row): Campaign
    {
        return new Campaign(
            id: (int) $row->id,
            slug: $row->slug,
            trigger: Trigger::from($row->trigger),
            promoCode: $row->promo_code,
            conditions: Conditions::fromArray(json_decode($row->conditions, true, flags: JSON_THROW_ON_ERROR)),
            rewardType: RewardType::from($row->reward_type),
            rewardParams: RewardParams::fromArray(json_decode($row->reward_params, true, flags: JSON_THROW_ON_ERROR)),
            startsAt: new DateTimeImmutable($row->starts_at),
            endsAt: $row->ends_at !== null ? new DateTimeImmutable($row->ends_at) : null,
            isActive: (bool) $row->is_active,
            createdBy: $row->created_by,
            createdAt: new DateTimeImmutable($row->created_at),
        );
    }
}

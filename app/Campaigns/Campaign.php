<?php

declare(strict_types=1);

namespace App\Campaigns;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Row of campaigns. A campaign is a trigger, conditions and a reward.
 */
final readonly class Campaign
{
    public function __construct(
        public int $id,
        public string $slug,
        public Trigger $trigger,
        public ?string $promoCode,
        public Conditions $conditions,
        public RewardType $rewardType,
        public RewardParams $rewardParams,
        public DateTimeImmutable $startsAt,
        public ?DateTimeImmutable $endsAt,
        public bool $isActive,
        public ?string $createdBy,
        public DateTimeImmutable $createdAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'id' => $this->id,
            'slug' => $this->slug,
            'trigger' => $this->trigger->value,
            'promo_code' => $this->promoCode,
            'conditions' => (object) $this->conditions->toArray(),
            'reward_type' => $this->rewardType->value,
            'reward_params' => (object) $this->rewardParams->toArray(),
            'starts_at' => $this->startsAt->format(DateTimeInterface::RFC3339_EXTENDED),
            'ends_at' => $this->endsAt?->format(DateTimeInterface::RFC3339_EXTENDED),
            'is_active' => $this->isActive,
            'created_by' => $this->createdBy,
            'created_at' => $this->createdAt->format(DateTimeInterface::RFC3339_EXTENDED),
        ], static fn (mixed $v, string $k): bool => $k !== 'created_by' || $v !== null, ARRAY_FILTER_USE_BOTH);
    }
}

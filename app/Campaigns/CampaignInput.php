<?php

declare(strict_types=1);

namespace App\Campaigns;

use App\Campaigns\Exceptions\InvalidCampaignException;
use DateTimeImmutable;
use Exception;

/**
 * Campaign from the admin panel. On update only the set fields are changed.
 */
final readonly class CampaignInput
{
    public function __construct(
        public ?string $slug = null,
        public ?string $trigger = null,
        public ?string $promoCode = null,
        public ?Conditions $conditions = null,
        public ?string $rewardType = null,
        public ?RewardParams $rewardParams = null,
        public ?DateTimeImmutable $startsAt = null,
        public ?DateTimeImmutable $endsAt = null,
        public ?bool $isActive = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidCampaignException
     */
    public static function fromArray(array $data): self
    {
        return new self(
            slug: isset($data['slug']) ? (string) $data['slug'] : null,
            trigger: isset($data['trigger']) ? (string) $data['trigger'] : null,
            promoCode: isset($data['promo_code']) ? (string) $data['promo_code'] : null,
            conditions: is_array($data['conditions'] ?? null) ? Conditions::fromArray($data['conditions']) : null,
            rewardType: isset($data['reward_type']) ? (string) $data['reward_type'] : null,
            rewardParams: is_array($data['reward_params'] ?? null) ? RewardParams::fromArray($data['reward_params']) : null,
            startsAt: self::time($data, 'starts_at'),
            endsAt: self::time($data, 'ends_at'),
            isActive: isset($data['is_active']) ? (bool) $data['is_active'] : null,
        );
    }

    /**
     * Returns the input with the unset fields taken from the current campaign,
     * or with the defaults of a new one.
     */
    public function over(?Campaign $current): self
    {
        if ($current === null) {
            return new self(
                slug: $this->slug,
                trigger: $this->trigger,
                promoCode: $this->promoCode,
                conditions: $this->conditions ?? new Conditions,
                rewardType: $this->rewardType,
                rewardParams: $this->rewardParams ?? new RewardParams,
                startsAt: $this->startsAt,
                endsAt: $this->endsAt,
                isActive: $this->isActive ?? true,
            );
        }

        return new self(
            slug: $this->slug ?? $current->slug,
            trigger: $this->trigger ?? $current->trigger->value,
            promoCode: $this->promoCode ?? $current->promoCode,
            conditions: $this->conditions ?? $current->conditions,
            rewardType: $this->rewardType ?? $current->rewardType->value,
            rewardParams: $this->rewardParams ?? $current->rewardParams,
            startsAt: $this->startsAt ?? $current->startsAt,
            endsAt: $this->endsAt ?? $current->endsAt,
            isActive: $this->isActive ?? $current->isActive,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidCampaignException
     */
    private static function time(array $data, string $key): ?DateTimeImmutable
    {
        if (! isset($data[$key])) {
            return null;
        }
        try {
            return new DateTimeImmutable((string) $data[$key]);
        } catch (Exception) {
            throw new InvalidCampaignException("{$key} is not a valid time");
        }
    }
}

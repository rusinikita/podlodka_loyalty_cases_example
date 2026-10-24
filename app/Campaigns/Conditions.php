<?php

declare(strict_types=1);

namespace App\Campaigns;

/**
 * Conditions of a campaign. Grant conditions are checked by CampaignService
 * when the trigger fires; order conditions (time_from, time_to,
 * min_order_amount) are checked by discounts when the discount is used.
 */
final readonly class Conditions
{
    public const SEGMENT_ALL = 'all';

    /**
     * @param  array<int, string>  $countries
     * @param  array<int, int>  $cityIds
     * @param  array<int, int>  $skuIds
     * @param  array<int, string>  $paymentMethods
     */
    public function __construct(
        public array $countries = [],
        public ?string $segment = null,
        public array $cityIds = [],
        public int $perUserLimit = 0,
        public int $maxActivations = 0,
        public array $skuIds = [],
        public array $paymentMethods = [],
        public bool $invited = false,
        public ?string $timeFrom = null,
        public ?string $timeTo = null,
        public int $minOrderAmount = 0,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            countries: array_values(array_map('strval', $data['countries'] ?? [])),
            segment: isset($data['segment']) ? (string) $data['segment'] : null,
            cityIds: array_values(array_map('intval', $data['city_ids'] ?? [])),
            perUserLimit: (int) ($data['per_user_limit'] ?? 0),
            maxActivations: (int) ($data['max_activations'] ?? 0),
            skuIds: array_values(array_map('intval', $data['sku_ids'] ?? [])),
            paymentMethods: array_values(array_map('strval', $data['payment_methods'] ?? [])),
            invited: (bool) ($data['invited'] ?? false),
            timeFrom: isset($data['time_from']) ? (string) $data['time_from'] : null,
            timeTo: isset($data['time_to']) ? (string) $data['time_to'] : null,
            minOrderAmount: (int) ($data['min_order_amount'] ?? 0),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'countries' => $this->countries,
            'segment' => $this->segment,
            'city_ids' => $this->cityIds,
            'per_user_limit' => $this->perUserLimit,
            'max_activations' => $this->maxActivations,
            'sku_ids' => $this->skuIds,
            'payment_methods' => $this->paymentMethods,
            'invited' => $this->invited,
            'time_from' => $this->timeFrom,
            'time_to' => $this->timeTo,
            'min_order_amount' => $this->minOrderAmount,
        ], static fn (mixed $v): bool => $v !== null && $v !== [] && $v !== 0 && $v !== false && $v !== '');
    }
}

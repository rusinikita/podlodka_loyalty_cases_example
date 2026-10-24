<?php

declare(strict_types=1);

namespace App\Campaigns;

/**
 * What fired the trigger: a promo code entered in the app, a payment or
 * a registration. Fields that the trigger does not carry are empty.
 */
final readonly class Event
{
    /**
     * @param  array<int, int>  $skuIds
     */
    public function __construct(
        public Trigger $trigger,
        public int $userId,
        public string $country = '',
        public int $cityId = 0,
        public string $segment = '',

        // promo_code
        public ?string $channel = null,

        // payment_succeeded
        public ?string $paymentId = null,
        public string $paymentMethod = '',
        public array $skuIds = [],

        // user_registered; null if the user was not invited
        public ?int $invitedBy = null,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Campaigns;

use App\Campaigns\Exceptions\ActivationsExhaustedException;
use App\Campaigns\Exceptions\AlreadyUsedException;
use App\Campaigns\Exceptions\CampaignExpiredException;
use App\Campaigns\Exceptions\IneligibleException;
use App\Campaigns\Exceptions\InvalidCampaignException;
use App\Campaigns\Exceptions\MonthlyLimitException;
use App\Campaigns\Exceptions\WrongCountryException;
use App\Clients\OrderHistory\OrderHistoryClient;
use App\Discounts\Discount;
use App\Discounts\DiscountService;
use App\Discounts\GrantRequest;
use App\Referrals\NewReward;
use App\Referrals\ReferralService;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Runs loyalty campaigns: on a trigger it picks the active campaigns, checks
 * their grant conditions and grants the reward.
 */
final class CampaignService
{
    public function __construct(
        private readonly CampaignRepository $campaigns,
        private readonly DiscountService $discounts,
        private readonly ReferralService $referrals,
        private readonly OrderHistoryClient $orders,
        private readonly int $maxPromoPerMonth,
    ) {}

    /**
     * Processes a payment or a registration: every active campaign of the
     * trigger whose grant conditions match the event grants its reward.
     */
    public function handle(Event $event): void
    {
        $matched = [];
        foreach ($this->campaigns->activeByTrigger($event->trigger, CarbonImmutable::now()) as $campaign) {
            try {
                $this->checkGrant($campaign, $event);
            } catch (IneligibleException $e) {
                Log::debug('campaign skipped', [
                    'campaign' => $campaign->slug,
                    'user_id' => $event->userId,
                    'reason' => $e->reason,
                ]);

                continue;
            }
            $matched[] = $campaign;
        }

        foreach ($matched as $campaign) {
            $this->grant($campaign, $event);
        }
    }

    public function byPromoCode(string $code): Campaign
    {
        return $this->campaigns->byPromoCode($code);
    }

    /**
     * Grants the discount of a promo code campaign to the user who entered
     * the code.
     *
     * @throws IneligibleException
     * @throws CampaignExpiredException
     * @throws MonthlyLimitException
     */
    public function applyPromoCode(Campaign $campaign, Event $event): Discount
    {
        $now = CarbonImmutable::now();
        if (! $campaign->isActive || $now < $campaign->startsAt) {
            throw new IneligibleException('inactive');
        }
        if ($campaign->endsAt !== null && $now >= $campaign->endsAt) {
            throw new CampaignExpiredException;
        }
        $this->checkGrant($campaign, $event);

        if ($this->discounts->countPromoUsedThisMonth($event->userId) >= $this->maxPromoPerMonth) {
            throw new MonthlyLimitException;
        }

        return $this->grantDiscount($campaign, $event);
    }

    public function get(int $id): Campaign
    {
        return $this->campaigns->byId($id);
    }

    /**
     * @return array<int, Campaign>
     */
    public function search(SearchFilter $filter): array
    {
        return $this->campaigns->search($filter);
    }

    /**
     * @throws InvalidCampaignException
     */
    public function create(CampaignInput $input, string $operator): Campaign
    {
        $values = $input->over(null);
        self::validate($values);

        $created = $this->campaigns->insert($values, $operator);
        Log::info('campaign created', [
            'campaign_id' => $created->id,
            'slug' => $created->slug,
            'operator' => $operator,
        ]);

        return $created;
    }

    /**
     * @throws InvalidCampaignException
     */
    public function update(int $id, CampaignInput $input, string $operator): Campaign
    {
        $values = $input->over($this->campaigns->byId($id));
        self::validate($values);

        $updated = $this->campaigns->update($id, $values);
        Log::info('campaign updated', ['campaign_id' => $id, 'operator' => $operator]);

        return $updated;
    }

    /**
     * Throws an IneligibleException if a grant condition of the campaign does
     * not match the event.
     */
    private function checkGrant(Campaign $campaign, Event $event): void
    {
        $cond = $campaign->conditions;

        if ($cond->countries !== [] && ! in_array($event->country, $cond->countries, true)) {
            throw new WrongCountryException;
        }
        if ($cond->segment !== null && $cond->segment !== Conditions::SEGMENT_ALL && $cond->segment !== $event->segment) {
            throw new IneligibleException('segment');
        }
        if ($cond->cityIds !== [] && ! in_array($event->cityId, $cond->cityIds, true)) {
            throw new IneligibleException('city');
        }
        if ($cond->skuIds !== [] && array_intersect($event->skuIds, $cond->skuIds) === []) {
            throw new IneligibleException('sku');
        }
        if ($cond->paymentMethods !== [] && ! in_array($event->paymentMethod, $cond->paymentMethods, true)) {
            throw new IneligibleException('payment_method');
        }
        if ($cond->invited && $event->invitedBy === null) {
            throw new IneligibleException('not_invited');
        }
        if ($cond->firstOrder && ! $this->isFirstOrder($event->userId)) {
            throw new IneligibleException('not_first_order');
        }

        if ($cond->perUserLimit > 0
            && $this->discounts->countByUser($campaign->id, $event->userId) >= $cond->perUserLimit) {
            throw new AlreadyUsedException;
        }
        if ($cond->maxActivations > 0
            && $this->discounts->countByCampaign($campaign->id) >= $cond->maxActivations) {
            throw new ActivationsExhaustedException;
        }
    }

    /**
     * Whether the payment is for the user's first order: the order history,
     * which already includes the paid order, has exactly one.
     */
    private function isFirstOrder(int $userId): bool
    {
        return $this->orders->countOrders($userId) === 1;
    }

    private function grant(Campaign $campaign, Event $event): void
    {
        match ($campaign->rewardType) {
            RewardType::Discount => $this->grantDiscount($campaign, $event),
            RewardType::Referral => $this->grantReferral($campaign, $event),
        };
    }

    private function grantDiscount(Campaign $campaign, Event $event): Discount
    {
        $params = $campaign->rewardParams;

        $expiresAt = match (true) {
            $params->ttlDays > 0 => CarbonImmutable::now()->addDays($params->ttlDays),
            $campaign->endsAt !== null => $campaign->endsAt,
            default => throw new RuntimeException("campaign {$campaign->slug}: neither ttl_days nor ends_at is set"),
        };

        return $this->discounts->grant(new GrantRequest(
            userId: $event->userId,
            campaignId: $campaign->id,
            channel: $event->channel,
            paymentId: $event->paymentId,
            percent: $params->percent,
            amount: $params->amount,
            maxAmount: $params->maxAmount,
            expiresAt: $expiresAt,
            notify: $campaign->trigger === Trigger::PaymentSucceeded,
        ));
    }

    private function grantReferral(Campaign $campaign, Event $event): void
    {
        if ($campaign->rewardParams->recipient !== RewardParams::RECIPIENT_INVITER) {
            Log::warning('unsupported reward recipient', [
                'campaign' => $campaign->slug,
                'recipient' => $campaign->rewardParams->recipient,
            ]);

            return;
        }

        $this->referrals->create(new NewReward(
            campaignId: $campaign->id,
            userId: $event->invitedBy ?? 0,
            inviteeId: $event->userId,
            amount: $campaign->rewardParams->amount,
        ));
    }

    /**
     * @throws InvalidCampaignException
     */
    private static function validate(CampaignInput $c): void
    {
        if ($c->slug === null || $c->slug === '') {
            throw new InvalidCampaignException('slug is required');
        }
        $trigger = Trigger::tryFrom((string) $c->trigger)
            ?? throw new InvalidCampaignException("unknown trigger \"{$c->trigger}\"");
        if ($trigger === Trigger::PromoCode && ($c->promoCode === null || $c->promoCode === '')) {
            throw new InvalidCampaignException("promo_code is required for trigger {$trigger->value}");
        }

        $params = $c->rewardParams ?? new RewardParams;
        switch (RewardType::tryFrom((string) $c->rewardType)) {
            case RewardType::Discount:
                if ($params->percent === 0 && $params->amount === 0) {
                    throw new InvalidCampaignException('percent or amount is required');
                }
                break;
            case RewardType::Referral:
                if ($params->amount === 0) {
                    throw new InvalidCampaignException('amount is required');
                }
                break;
            default:
                throw new InvalidCampaignException("unknown reward type \"{$c->rewardType}\"");
        }

        if (! $c->startsAt instanceof DateTimeImmutable) {
            throw new InvalidCampaignException('starts_at is required');
        }
    }
}

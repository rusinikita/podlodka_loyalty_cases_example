<?php

declare(strict_types=1);

namespace App\Discounts;

use App\Clients\Notifications\NotificationsClient;
use App\Clients\Notifications\Push;
use App\Discounts\Exceptions\CommentRequiredException;
use App\Discounts\Exceptions\CompensationLimitException;
use App\Discounts\Exceptions\DiscountUnavailableException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * User discounts: granting by a campaign or as a compensation, picking
 * a discount for an order and redeeming it.
 */
final class DiscountService
{
    private const NEXT_ORDER_PUSH_TEMPLATE = 'loyalty_next_order_discount';

    public function __construct(
        private readonly DiscountRepository $discounts,
        private readonly NotificationsClient $notifications,
        private readonly Compensation $compensation,
    ) {}

    /**
     * Saves a discount of a campaign. With notify the user also gets a push
     * about the discount for the next order.
     */
    public function grant(GrantRequest $req): Discount
    {
        $discount = $this->discounts->insert(new NewDiscount(
            userId: $req->userId,
            expiresAt: $req->expiresAt,
            campaignId: $req->campaignId,
            channel: $req->channel,
            paymentId: $req->paymentId,
            percent: $req->percent ?: null,
            amount: $req->amount ?: null,
            maxAmount: $req->maxAmount ?: null,
        ));

        if ($req->notify) {
            $this->pushNextOrder($discount);
        }

        Log::info('discount granted', [
            'discount_id' => $discount->id,
            'user_id' => $discount->userId,
            'campaign_id' => $req->campaignId,
        ]);

        return $discount;
    }

    /**
     * Saves a discount given by an operator. It has no campaign; the operator
     * and the ticket number are kept in the row.
     *
     * @throws CompensationLimitException
     * @throws CommentRequiredException
     */
    public function grantCompensation(CompensationRequest $req): Discount
    {
        if ($req->percent < 1 || $req->percent > $this->compensation->maxPercent
            || $req->ttlDays < 1 || $req->ttlDays > $this->compensation->ttlDays) {
            throw new CompensationLimitException;
        }
        if (trim($req->comment) === '') {
            throw new CommentRequiredException;
        }

        $discount = $this->discounts->insert(new NewDiscount(
            userId: $req->userId,
            expiresAt: CarbonImmutable::now()->addDays($req->ttlDays),
            percent: $req->percent,
            maxAmount: $req->maxAmount,
            createdBy: $req->operator,
            comment: $req->comment,
        ));

        Log::info('compensation granted', [
            'discount_id' => $discount->id,
            'user_id' => $discount->userId,
            'operator' => $req->operator,
            'percent' => $req->percent,
        ]);

        return $discount;
    }

    /**
     * @return array<int, Discount>
     */
    public function active(int $userId): array
    {
        return $this->discounts->active($userId, CarbonImmutable::now());
    }

    /**
     * @return array<int, Discount>
     */
    public function byUser(int $userId, int $afterId, int $limit): array
    {
        return $this->discounts->byUser($userId, $afterId, $limit);
    }

    /**
     * Picks the user's discount for an order. Discounts do not stack: the
     * order gets the single largest one whose order conditions match.
     */
    public function quote(QuoteRequest $req): Quote
    {
        $best = new Quote;
        foreach ($this->discounts->redeemable($req->userId, $req->orderedAt) as $candidate) {
            if (! $candidate->conditions->match($req->orderAmount, $req->orderedAt)) {
                continue;
            }
            $value = $candidate->discount->value($req->orderAmount);
            if ($value > $best->amount) {
                $best = new Quote($candidate->discount->id, $value);
            }
        }

        return $best;
    }

    /**
     * @throws DiscountUnavailableException
     */
    public function redeem(int $discountId, int $userId, int $orderId): void
    {
        if (! $this->discounts->markUsed($discountId, $userId, $orderId)) {
            throw new DiscountUnavailableException;
        }
    }

    public function countByCampaign(int $campaignId): int
    {
        return $this->discounts->countByCampaign($campaignId);
    }

    public function countByUser(int $campaignId, int $userId): int
    {
        return $this->discounts->countByUser($campaignId, $userId);
    }

    public function countPromoUsedThisMonth(int $userId): int
    {
        return $this->discounts->countPromoUsedThisMonth($userId);
    }

    private function pushNextOrder(Discount $discount): void
    {
        $params = ['expires_at' => $discount->expiresAt->format('Y-m-d')];
        if ($discount->percent !== null) {
            $params['percent'] = (string) $discount->percent;
        }
        if ($discount->amount !== null) {
            $params['amount'] = (string) $discount->amount;
        }

        $this->notifications->push(new Push(
            userId: $discount->userId,
            template: self::NEXT_ORDER_PUSH_TEMPLATE,
            params: $params,
        ));
    }
}

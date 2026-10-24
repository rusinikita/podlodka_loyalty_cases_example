<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Discounts\Discount;
use App\Discounts\DiscountService;
use App\Http\ApiError;
use App\Http\Pagination;
use App\Http\UserContext;
use App\Promo\ApplyRequest;
use App\Promo\Channel;
use App\Promo\Exceptions\PromoAlreadyUsedException;
use App\Promo\Exceptions\PromoExpiredException;
use App\Promo\Exceptions\PromoLimitReachedException;
use App\Promo\Exceptions\PromoMonthlyLimitException;
use App\Promo\Exceptions\PromoNotFoundException;
use App\Promo\Exceptions\PromoWrongCountryException;
use App\Promo\PromoService;
use App\Referrals\ReferralService;
use App\Referrals\Reward;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * App endpoints (/v1), see api/loyalty-api.yaml.
 */
final class AppController
{
    public function __construct(
        private readonly PromoService $promo,
        private readonly DiscountService $discounts,
        private readonly ReferralService $referrals,
    ) {}

    public function applyPromo(Request $request): JsonResponse
    {
        $user = UserContext::fromRequest($request);
        $body = $request->validate([
            'code' => ['required', 'string'],
            'channel' => ['required', 'string'],
        ]);
        $channel = Channel::tryFrom($body['channel']) ?? throw ApiError::badRequest('unknown channel');

        try {
            $discount = $this->promo->apply(new ApplyRequest(
                userId: $user->id,
                country: $user->country,
                cityId: $user->cityId,
                segment: $user->segment,
                code: $body['code'],
                channel: $channel,
            ));
        } catch (PromoNotFoundException) {
            throw new ApiError(404, 'promo_not_found');
        } catch (PromoAlreadyUsedException) {
            throw new ApiError(409, 'promo_already_used');
        } catch (PromoExpiredException) {
            throw new ApiError(422, 'promo_expired');
        } catch (PromoLimitReachedException) {
            throw new ApiError(422, 'promo_activations_exhausted');
        } catch (PromoMonthlyLimitException) {
            throw new ApiError(422, 'promo_monthly_limit');
        } catch (PromoWrongCountryException) {
            throw new ApiError(422, 'promo_country_unavailable');
        }

        return response()->json($discount->toArray());
    }

    public function activeDiscounts(Request $request): JsonResponse
    {
        $user = UserContext::fromRequest($request);
        $items = $this->discounts->active($user->id);

        return response()->json([
            'items' => array_map(static fn (Discount $d): array => $d->toArray(), $items),
        ]);
    }

    public function referralRewards(Request $request): JsonResponse
    {
        $user = UserContext::fromRequest($request);
        $items = $this->referrals->byUser($user->id, Pagination::cursor($request), Pagination::PAGE_SIZE);

        return response()->json([
            'items' => array_map(static fn (Reward $r): array => $r->toArray(), $items),
            'next_cursor' => Pagination::next($items),
        ]);
    }
}

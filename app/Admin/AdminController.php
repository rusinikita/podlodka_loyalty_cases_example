<?php

declare(strict_types=1);

namespace App\Admin;

use App\Campaigns\Campaign;
use App\Campaigns\CampaignInput;
use App\Campaigns\CampaignService;
use App\Campaigns\Exceptions\CampaignConflictException;
use App\Campaigns\Exceptions\CampaignNotFoundException;
use App\Campaigns\Exceptions\InvalidCampaignException;
use App\Campaigns\RewardType;
use App\Campaigns\SearchFilter;
use App\Discounts\CompensationRequest;
use App\Discounts\Discount;
use App\Discounts\DiscountService;
use App\Discounts\Exceptions\CommentRequiredException;
use App\Discounts\Exceptions\CompensationLimitException;
use App\Http\ApiError;
use App\Http\Pagination;
use App\Referrals\ReferralService;
use App\Referrals\Reward;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin panel endpoints (/admin/v1): campaigns, compensation discounts and
 * user rewards.
 */
final class AdminController
{
    public function __construct(
        private readonly CampaignService $campaigns,
        private readonly DiscountService $discounts,
        private readonly ReferralService $referrals,
    ) {}

    public function searchCampaigns(Request $request): JsonResponse
    {
        $active = $request->query('active');
        if ($active !== null && ! in_array($active, ['true', 'false', '1', '0'], true)) {
            throw ApiError::badRequest('invalid active');
        }

        $items = $this->campaigns->search(new SearchFilter(
            query: (string) $request->query('query', ''),
            trigger: (string) $request->query('trigger', ''),
            active: $active === null ? null : in_array($active, ['true', '1'], true),
            afterId: Pagination::cursor($request),
            limit: Pagination::PAGE_SIZE,
        ));

        return response()->json([
            'items' => array_map(static fn (Campaign $c): array => $c->toArray(), $items),
            'next_cursor' => Pagination::next($items),
        ]);
    }

    public function createCampaign(Request $request): JsonResponse
    {
        $campaign = $this->campaignCall(fn () => $this->campaigns->create(
            CampaignInput::fromArray($request->json()->all()),
            Operator::of($request)->login,
        ));

        return response()->json($campaign->toArray(), 201);
    }

    public function getCampaign(string $id): JsonResponse
    {
        $campaign = $this->campaignCall(fn () => $this->campaigns->get(self::pathId($id, 'id')));

        $activations = match ($campaign->rewardType) {
            RewardType::Discount => $this->discounts->countByCampaign($campaign->id),
            RewardType::Referral => $this->referrals->countByCampaign($campaign->id),
        };

        return response()->json([...$campaign->toArray(), 'activations' => $activations]);
    }

    public function updateCampaign(Request $request, string $id): JsonResponse
    {
        $campaign = $this->campaignCall(fn () => $this->campaigns->update(
            self::pathId($id, 'id'),
            CampaignInput::fromArray($request->json()->all()),
            Operator::of($request)->login,
        ));

        return response()->json($campaign->toArray());
    }

    public function userDiscounts(Request $request, string $userId): JsonResponse
    {
        $items = $this->discounts->byUser(
            self::pathId($userId, 'user_id'),
            Pagination::cursor($request),
            Pagination::PAGE_SIZE,
        );

        return response()->json([
            'items' => array_map(static fn (Discount $d): array => $d->toArray(), $items),
            'next_cursor' => Pagination::next($items),
        ]);
    }

    public function grantCompensation(Request $request, string $userId): JsonResponse
    {
        $body = $request->validate([
            'percent' => ['required', 'integer'],
            'max_amount' => ['nullable', 'integer'],
            'ttl_days' => ['required', 'integer'],
            'comment' => ['present', 'nullable', 'string'],
        ]);

        try {
            $discount = $this->discounts->grantCompensation(new CompensationRequest(
                userId: self::pathId($userId, 'user_id'),
                percent: (int) $body['percent'],
                maxAmount: isset($body['max_amount']) ? (int) $body['max_amount'] : null,
                ttlDays: (int) $body['ttl_days'],
                operator: Operator::of($request)->login,
                comment: (string) $body['comment'],
            ));
        } catch (CompensationLimitException) {
            throw new ApiError(422, 'compensation_limit');
        } catch (CommentRequiredException $e) {
            throw ApiError::badRequest($e->getMessage());
        }

        return response()->json($discount->toArray(), 201);
    }

    public function userReferralRewards(string $userId): JsonResponse
    {
        $items = $this->referrals->byUser(self::pathId($userId, 'user_id'), 0, Pagination::PAGE_SIZE);

        return response()->json([
            'items' => array_map(static fn (Reward $r): array => $r->toArray(), $items),
        ]);
    }

    /**
     * @param  Closure(): Campaign  $call
     */
    private function campaignCall(Closure $call): Campaign
    {
        try {
            return $call();
        } catch (CampaignNotFoundException) {
            throw new ApiError(404, 'not_found');
        } catch (CampaignConflictException $e) {
            throw new ApiError(409, 'conflict', $e->getMessage());
        } catch (InvalidCampaignException $e) {
            throw ApiError::badRequest($e->getMessage());
        }
    }

    private static function pathId(string $value, string $name): int
    {
        if (! ctype_digit($value) || (int) $value <= 0) {
            throw ApiError::badRequest("invalid {$name}");
        }

        return (int) $value;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Discounts\DiscountService;
use App\Discounts\Exceptions\DiscountUnavailableException;
use App\Discounts\QuoteRequest;
use App\Http\ApiError;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Endpoints for the orders and pricing services (/v1/orders).
 */
final class OrdersController
{
    public function __construct(
        private readonly DiscountService $discounts,
    ) {}

    public function quote(Request $request): JsonResponse
    {
        $body = $request->validate([
            'user_id' => ['required', 'integer'],
            'order_amount' => ['required', 'integer'],
            'city_id' => ['integer'],
            'ordered_at' => ['required', 'date'],
        ]);

        $quote = $this->discounts->quote(new QuoteRequest(
            userId: (int) $body['user_id'],
            orderAmount: (int) $body['order_amount'],
            cityId: (int) ($body['city_id'] ?? 0),
            orderedAt: new DateTimeImmutable($body['ordered_at']),
        ));

        return response()->json([
            'discount_id' => $quote->discountId,
            'discount_amount' => $quote->amount,
        ]);
    }

    public function redeem(Request $request, string $orderId): Response
    {
        if (! ctype_digit($orderId)) {
            throw ApiError::badRequest('invalid order_id');
        }
        $body = $request->validate([
            'discount_id' => ['required', 'integer'],
            'user_id' => ['required', 'integer'],
        ]);

        try {
            $this->discounts->redeem((int) $body['discount_id'], (int) $body['user_id'], (int) $orderId);
        } catch (DiscountUnavailableException) {
            throw new ApiError(409, 'discount_unavailable');
        }

        return response()->noContent();
    }
}

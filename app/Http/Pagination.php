<?php

declare(strict_types=1);

namespace App\Http;

use App\Campaigns\Campaign;
use App\Discounts\Discount;
use App\Referrals\Reward;
use Illuminate\Http\Request;

/**
 * Cursor pagination: the cursor is the id of the last item of the previous
 * page.
 */
final class Pagination
{
    public const PAGE_SIZE = 50;

    public static function cursor(Request $request): int
    {
        $cursor = $request->query('cursor');
        if ($cursor === null || $cursor === '') {
            return 0;
        }
        if (! is_string($cursor) || ! ctype_digit($cursor)) {
            throw ApiError::badRequest('invalid cursor');
        }

        return (int) $cursor;
    }

    /**
     * Returns the cursor of the next page, or null if the page is not full.
     *
     * @param  array<int, Campaign|Discount|Reward>  $items
     */
    public static function next(array $items): ?string
    {
        if (count($items) < self::PAGE_SIZE) {
            return null;
        }

        return (string) end($items)->id;
    }
}

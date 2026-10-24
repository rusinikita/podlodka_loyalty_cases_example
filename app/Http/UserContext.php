<?php

declare(strict_types=1);

namespace App\Http;

use Illuminate\Http\Request;

/**
 * User context passed by the API gateway in headers.
 */
final readonly class UserContext
{
    public function __construct(
        public int $id,
        public string $country,
        public int $cityId,
        public string $segment,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $id = (string) $request->header('X-User-Id');
        if (! ctype_digit($id) || (int) $id <= 0) {
            throw ApiError::badRequest('invalid X-User-Id');
        }

        $city = (string) $request->header('X-User-City-Id');
        if ($city !== '' && ! ctype_digit($city)) {
            throw ApiError::badRequest('invalid X-User-City-Id');
        }

        return new self(
            id: (int) $id,
            country: (string) $request->header('X-User-Country'),
            cityId: (int) $city,
            segment: (string) $request->header('X-User-Segment'),
        );
    }
}

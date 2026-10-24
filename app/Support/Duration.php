<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Durations in the config are written as "500ms", "2s", "5m", "1h30m".
 */
final class Duration
{
    private const UNITS = ['ms' => 0.001, 's' => 1.0, 'm' => 60.0, 'h' => 3600.0];

    /**
     * Returns the duration in seconds.
     */
    public static function seconds(string $value): float
    {
        $value = trim($value);
        if ($value === '0') {
            return 0.0;
        }
        if (! preg_match('/^(\d+(?:\.\d+)?(?:ms|s|m|h))+$/', $value)) {
            throw new InvalidArgumentException("invalid duration \"{$value}\"");
        }

        preg_match_all('/(\d+(?:\.\d+)?)(ms|s|m|h)/', $value, $parts, PREG_SET_ORDER);
        $seconds = 0.0;
        foreach ($parts as [, $number, $unit]) {
            $seconds += (float) $number * self::UNITS[$unit];
        }

        return $seconds;
    }

    /**
     * Parses a comma separated list: "1s, 5s".
     *
     * @return array<int, float>
     */
    public static function list(string $value): array
    {
        return array_map(self::seconds(...), explode(',', $value));
    }
}

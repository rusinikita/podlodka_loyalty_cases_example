<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads the service configuration from config/*.yaml.
 */
final class YamlConfig
{
    /**
     * @return array<string, mixed>
     */
    public static function load(string $file, bool $expandEnv = false): array
    {
        $dir = getenv('LOYALTY_CONFIG_DIR') ?: dirname(__DIR__, 2).'/config';
        $path = $dir.'/'.$file;

        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("read {$path}: file is not readable");
        }
        if ($expandEnv) {
            $raw = self::expandEnv($raw);
        }

        $parsed = Yaml::parse($raw);
        if (! is_array($parsed)) {
            throw new RuntimeException("parse {$path}: not a mapping");
        }

        return $parsed;
    }

    /**
     * Replaces ${VAR} references with environment variables.
     */
    private static function expandEnv(string $raw): string
    {
        return (string) preg_replace_callback(
            '/\$\{(\w+)\}/',
            static fn (array $m): string => (string) getenv($m[1]),
            $raw,
        );
    }
}

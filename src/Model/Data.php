<?php

declare(strict_types=1);

namespace ProSms\Model;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * Lecture tolérante des champs JSON de l'API.
 *
 * @internal
 */
final class Data
{
    /** @param array<string, mixed> $data */
    public static function string(array $data, string $key, string $default = ''): string
    {
        $value = $data[$key] ?? null;

        return is_scalar($value) ? (string) $value : $default;
    }

    /** @param array<string, mixed> $data */
    public static function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    /** @param array<string, mixed> $data */
    public static function int(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    /** @param array<string, mixed> $data */
    public static function bool(array $data, string $key): bool
    {
        return (bool) ($data[$key] ?? false);
    }

    /** @param array<string, mixed> $data */
    public static function date(array $data, string $key): ?DateTimeImmutable
    {
        $value = $data[$key] ?? null;
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Exception) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function array(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        return is_array($value) ? $value : [];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    public static function list(array $data, string $key): array
    {
        return array_values(array_filter(self::array($data, $key), 'is_array'));
    }
}

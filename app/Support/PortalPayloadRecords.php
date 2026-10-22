<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Reading records and matching values in a dataset payload, shared by the
 * rule formats in `config/portal.php` (`notify` rules and `archive` guards).
 *
 * `records` is a dot path to a list, or `parent.*.children` for one nested
 * level (each child then sees its parent as `parent`); null means the payload
 * itself is the single record.
 *
 * Matchers: `@any`, `@empty`, `@filled`, `@true`, `@false`, `@positive`,
 * `@zero`, a scalar (case-insensitive equality) or a list of them (any).
 */
final class PortalPayloadRecords
{
    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>|null Null when the list is missing.
     */
    public static function records(?string $path, array $data): ?array
    {
        if ($path === null) {
            return [$data];
        }

        if (! str_contains($path, '.*.')) {
            $records = data_get($data, $path);

            return is_array($records) ? array_values(array_filter($records, 'is_array')) : null;
        }

        [$parentPath, $childPath] = explode('.*.', $path, 2);
        $parents = data_get($data, $parentPath);

        if (! is_array($parents)) {
            return null;
        }

        $records = [];

        foreach (array_filter($parents, 'is_array') as $parent) {
            $children = data_get($parent, $childPath);

            if (! is_array($children)) {
                continue;
            }

            $context = $parent;
            data_forget($context, $childPath);

            foreach (array_filter($children, 'is_array') as $child) {
                $records[] = [...$child, 'parent' => $context];
            }
        }

        return $records;
    }

    /**
     * @param  list<string>  $paths
     * @param  array<string, mixed>  $record
     */
    public static function recordKey(array $paths, array $record): ?string
    {
        $parts = array_map(static fn (string $path): string => (string) (self::normalize(data_get($record, $path)) ?? ''), $paths);

        if ($paths === [] || implode('', $parts) === '') {
            return null;
        }

        $key = implode('|', $parts);

        return mb_strlen($key) > 191 ? 'sha1:'.sha1($key) : $key;
    }

    /**
     * Every path => matcher pair holds for the record.
     *
     * @param  array<string, mixed>  $guards
     * @param  array<string, mixed>  $record
     */
    public static function passes(array $guards, array $record): bool
    {
        foreach ($guards as $path => $matcher) {
            if (! self::matches($matcher, self::normalize(data_get($record, $path)))) {
                return false;
            }
        }

        return true;
    }

    public static function normalize(mixed $value): bool|int|float|string|null
    {
        return match (true) {
            $value === null, is_bool($value), is_int($value), is_float($value) => $value,
            is_string($value) => trim($value) === '' ? null : trim($value),
            is_array($value) => $value === [] ? null : (string) json_encode($value),
            default => null,
        };
    }

    public static function matches(mixed $matcher, bool|int|float|string|null $value): bool
    {
        if (is_array($matcher)) {
            foreach ($matcher as $candidate) {
                if (self::matches($candidate, $value)) {
                    return true;
                }
            }

            return false;
        }

        return match (true) {
            $matcher === null, $matcher === '@any' => true,
            $matcher === '@empty' => $value === null,
            $matcher === '@filled' => $value !== null,
            $matcher === '@true' => $value === true,
            $matcher === '@false' => $value === false,
            $matcher === '@positive' => is_numeric($value) && (float) $value > 0,
            $matcher === '@zero' => is_numeric($value) && (float) $value === 0.0,
            is_bool($matcher) => $value === $matcher,
            is_int($matcher), is_float($matcher) => is_numeric($value) && (float) $value === (float) $matcher,
            is_string($matcher) => is_scalar($value) && ! is_bool($value) && mb_strtolower((string) $value) === mb_strtolower($matcher),
            default => false,
        };
    }
}

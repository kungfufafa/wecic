<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

class PortalEndpointRegistry
{
    /**
     * Return the full endpoint catalogue.
     */
    public static function all(): array
    {
        return config('portal.endpoints', []);
    }

    /**
     * Get the list of registered endpoint keys.
     */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /**
     * Retrieve a single endpoint definition.
     */
    public static function definition(string $key): array
    {
        $definition = self::all()[$key] ?? null;

        if ($definition === null) {
            throw new InvalidArgumentException("Portal endpoint [{$key}] is not defined.");
        }

        return $definition;
    }

    /**
     * Build a URL for the given endpoint, merging additional query values.
     */
    public static function url(string $key, array $queryOverrides = []): string
    {
        $definition = self::definition($key);
        $rawUrl = $definition['url'] ?? null;

        if ($rawUrl === null) {
            throw new InvalidArgumentException("Portal endpoint [{$key}] does not define a base URL.");
        }

        [$baseUrl, $baseQuery] = self::splitUrl($rawUrl);
        $defaultQuery = $definition['default_query'] ?? $baseQuery;

        $mergedQuery = array_merge($defaultQuery, $queryOverrides);
        $filteredQuery = array_filter(
            $mergedQuery,
            static fn ($value) => $value !== null && $value !== ''
        );

        self::guardRequiredParams($key, $definition, $filteredQuery);

        if ($filteredQuery === []) {
            return $baseUrl;
        }

        $queryString = http_build_query($filteredQuery, '', '&', PHP_QUERY_RFC3986);

        return $baseUrl.'?'.$queryString;
    }

    public static function method(string $key): string
    {
        return strtoupper(self::definition($key)['method'] ?? 'GET');
    }

    public static function requiredParams(string $key): array
    {
        return self::definition($key)['required_params'] ?? [];
    }

    public static function describe(string $key): array
    {
        $definition = self::definition($key);

        return [
            'method' => self::method($key),
            'url' => self::url($key),
            'description' => $definition['description'] ?? '',
            'category' => $definition['category'] ?? null,
            'priority' => $definition['priority'] ?? null,
            'requires_session' => $definition['requires_session'] ?? true,
            'required_params' => $definition['required_params'] ?? [],
        ];
    }

    /**
     * Return endpoints grouped by their category label.
     */
    public static function groupedByCategory(): array
    {
        $grouped = [];

        foreach (self::all() as $key => $definition) {
            $category = $definition['category'] ?? 'uncategorized';
            $grouped[$category][$key] = $definition;
        }

        ksort($grouped);

        return $grouped;
    }

    /**
     * Ensure the caller provided all required parameters for the endpoint.
     */
    public static function ensureRequiredParams(string $key, array $parameters): void
    {
        $definition = self::definition($key);
        self::guardRequiredParams($key, $definition, $parameters);
    }

    private static function splitUrl(string $url): array
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException("Invalid portal endpoint URL: {$url}");
        }

        $baseUrl = $parts['scheme'].'://'.$parts['host']
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .($parts['path'] ?? '');

        $query = [];
        if (! empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        return [$baseUrl, $query];
    }

    private static function guardRequiredParams(string $key, array $definition, array $query): void
    {
        $missing = array_filter($definition['required_params'] ?? [], static function ($param) use ($query) {
            return ! array_key_exists($param, $query);
        });

        if ($missing !== []) {
            throw new InvalidArgumentException(sprintf(
                'Portal endpoint [%s] is missing required parameters: %s',
                $key,
                implode(', ', $missing)
            ));
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\PortalPayloadRecords;

/**
 * Decides whether a new sync of a dataset looks like the portal lost or
 * mangled data, by comparing it with the version MyCIC trusts.
 *
 * Each dataset declares its guards under `portal.datasets.<name>.archive`;
 * `records` paths and matchers follow `App\Support\PortalPayloadRecords`.
 *
 * - `requires`: the payload is only data when these hold (`found => true`).
 *   A trusted version followed by an unreadable payload is a finding.
 * - `count`: the number of `records` must not drop.
 * - `values`: a record's `field` whose old value matches `from` (default
 *   `@filled`) must not change, and that record must not disappear; records
 *   are paired by `key`, so a record lost while another one appears (the
 *   count stays the same) is still found.
 * - `field`: the value at `path` must not change once it matches `from`.
 *
 * `display` maps raw values to words for the messages
 * (`['present' => 'Hadir']`).
 *
 * @phpstan-type ArchiveGuard array{
 *     type: 'count'|'values'|'field',
 *     label: string,
 *     records?: string|null,
 *     key?: list<string>,
 *     field?: string,
 *     path?: string,
 *     from?: mixed,
 *     display?: array<string, string>
 * }
 * @phpstan-type Finding array{rule: string, label: string, message: string}
 */
class PortalArchiveGuard
{
    private const MAX_EXAMPLES = 3;

    /**
     * @param  array<string, mixed>  $data
     */
    public function isReadable(string $dataset, array $data): bool
    {
        $requires = config("portal.datasets.{$dataset}.archive.requires", []);

        return PortalPayloadRecords::passes(is_array($requires) ? $requires : [], $data);
    }

    /**
     * @param  array<string, mixed>  $trusted
     * @param  array<string, mixed>  $candidate
     * @return list<Finding>
     */
    public function inspect(string $dataset, array $trusted, array $candidate): array
    {
        if (! $this->isReadable($dataset, $candidate)) {
            $label = (string) config("portal.datasets.{$dataset}.archive.label", $dataset);

            return [[
                'rule' => 'requires',
                'label' => $label,
                'message' => "Portal tidak lagi menampilkan {$label}, padahal sebelumnya ada.",
            ]];
        }

        $findings = [];

        foreach ($this->guards($dataset) as $guard) {
            $finding = match ($guard['type']) {
                'count' => $this->inspectCount($guard, $trusted, $candidate),
                'values' => $this->inspectValues($guard, $trusted, $candidate),
                'field' => $this->inspectField($guard, $trusted, $candidate),
            };

            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * @return list<ArchiveGuard>
     */
    public function guards(string $dataset): array
    {
        $guards = config("portal.datasets.{$dataset}.archive.guards", []);

        if (! is_array($guards)) {
            return [];
        }

        return array_values(array_filter($guards, static fn (mixed $guard): bool => is_array($guard)
            && in_array($guard['type'] ?? null, ['count', 'values', 'field'], true)
            && is_string($guard['label'] ?? null)
            && match ($guard['type']) {
                'count' => true,
                'values' => is_string($guard['field'] ?? null) && is_array($guard['key'] ?? null),
                'field' => is_string($guard['path'] ?? null),
            }));
    }

    /**
     * @param  ArchiveGuard  $guard
     * @param  array<string, mixed>  $trusted
     * @param  array<string, mixed>  $candidate
     * @return Finding|null
     */
    private function inspectCount(array $guard, array $trusted, array $candidate): ?array
    {
        $old = PortalPayloadRecords::records($guard['records'] ?? null, $trusted);
        $new = PortalPayloadRecords::records($guard['records'] ?? null, $candidate);

        if ($old === null || $old === [] || count($new ?? []) >= count($old)) {
            return null;
        }

        return [
            'rule' => 'count',
            'label' => $guard['label'],
            'message' => sprintf('%s berkurang dari %d menjadi %d.', $guard['label'], count($old), count($new ?? [])),
        ];
    }

    /**
     * @param  ArchiveGuard  $guard
     * @param  array<string, mixed>  $trusted
     * @param  array<string, mixed>  $candidate
     * @return Finding|null
     */
    private function inspectValues(array $guard, array $trusted, array $candidate): ?array
    {
        $old = $this->valuesByKey($guard, $trusted);
        $new = $this->valuesByKey($guard, $candidate);
        $changes = [];
        $lost = [];

        foreach ($old as $recordKey => $oldValue) {
            if (! PortalPayloadRecords::matches($guard['from'] ?? '@filled', $oldValue)) {
                continue;
            }

            if (! array_key_exists($recordKey, $new)) {
                $lost[] = sprintf('%s (%s)', $recordKey, $this->display($guard, $oldValue));
            } elseif ($new[$recordKey] !== $oldValue) {
                $changes[] = sprintf('%s: %s → %s', $recordKey, $this->display($guard, $oldValue), $this->display($guard, $new[$recordKey]));
            }
        }

        if ($changes === [] && $lost === []) {
            return null;
        }

        $sentences = array_filter([
            $this->listed($guard['label'].' berubah', $changes),
            $this->listed($guard['label'].' hilang', $lost),
        ]);

        return [
            'rule' => 'values',
            'label' => $guard['label'],
            'message' => implode(' ', $sentences),
        ];
    }

    /**
     * One sentence naming the first few records, e.g. "Nilai huruf hilang
     * pada 1 data: Ganjil 2025/2026|IF101 (A)."; null when none.
     *
     * @param  list<string>  $records
     */
    private function listed(string $subject, array $records): ?string
    {
        if ($records === []) {
            return null;
        }

        $examples = implode('; ', array_slice($records, 0, self::MAX_EXAMPLES));
        $more = count($records) > self::MAX_EXAMPLES ? sprintf(' (dan %d lainnya)', count($records) - self::MAX_EXAMPLES) : '';

        return sprintf('%s pada %d data: %s%s.', $subject, count($records), $examples, $more);
    }

    /**
     * @param  ArchiveGuard  $guard
     * @param  array<string, mixed>  $trusted
     * @param  array<string, mixed>  $candidate
     * @return Finding|null
     */
    private function inspectField(array $guard, array $trusted, array $candidate): ?array
    {
        $old = PortalPayloadRecords::normalize(data_get($trusted, $guard['path']));
        $new = PortalPayloadRecords::normalize(data_get($candidate, $guard['path']));

        if ($old === null || $old === $new || ! PortalPayloadRecords::matches($guard['from'] ?? '@filled', $old)) {
            return null;
        }

        return [
            'rule' => 'field',
            'label' => $guard['label'],
            'message' => sprintf('%s berubah dari %s menjadi %s.', $guard['label'], $this->display($guard, $old), $this->display($guard, $new)),
        ];
    }

    /**
     * @param  ArchiveGuard  $guard
     * @param  array<string, mixed>  $data
     * @return array<string, bool|int|float|string|null>
     */
    private function valuesByKey(array $guard, array $data): array
    {
        $values = [];

        foreach (PortalPayloadRecords::records($guard['records'] ?? null, $data) ?? [] as $record) {
            $recordKey = PortalPayloadRecords::recordKey($guard['key'] ?? [], $record);

            if ($recordKey !== null) {
                $values[$recordKey] ??= PortalPayloadRecords::normalize(data_get($record, (string) $guard['field']));
            }
        }

        return $values;
    }

    /**
     * @param  ArchiveGuard  $guard
     */
    private function display(array $guard, bool|int|float|string|null $value): string
    {
        if ($value === null) {
            return 'kosong';
        }

        $text = is_bool($value) ? ($value ? 'ya' : 'tidak') : (string) $value;

        return (string) ($guard['display'][$text] ?? $text);
    }
}

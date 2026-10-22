<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PortalNotification;
use App\Support\PortalPayloadRecords;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Turns changes between two syncs of a dataset into notifications (K9).
 *
 * Each dataset declares its rules under `portal.datasets.<name>.notify`;
 * there is no per-feature code here. A rule watches records (a list in the
 * payload, or the payload itself) and fires when a record appears (`added`)
 * or when one field changes in a way its `from`/`to` matchers accept
 * (`changed`).
 *
 * The comparison is against what this notifier last saw for the student,
 * kept apart from the snapshot and for much longer: a student who logs in
 * after weeks still hears that the KRS got validated meanwhile. The very
 * first sync only records a baseline, so nothing that was already there is
 * announced as new. Records missing from a later sync, and watched values
 * that come back empty (or listed in `ignore`), keep their last known value:
 * a page that failed to load must not make anything "new" again.
 *
 * `records` paths and matchers follow `App\Support\PortalPayloadRecords`.
 * `requires` guards the whole payload (unreadable → the rule is skipped),
 * `when` filters records.
 * Templates fill `:path.in.record` from the new record, plus `:old`/`:new`
 * for the watched field; `|date` formats a date, `|path` keeps a MyCIC path
 * as is (URL values are encoded otherwise).
 *
 * @phpstan-type NotifyRule array{
 *     type: string,
 *     on: 'added'|'changed',
 *     records?: string|null,
 *     key?: list<string>,
 *     field?: string,
 *     ignore?: mixed,
 *     from?: mixed,
 *     to?: mixed,
 *     when?: array<string, mixed>,
 *     requires?: array<string, mixed>,
 *     title: string,
 *     body?: string|null,
 *     url?: string|null,
 *     urgency?: string,
 *     max_per_sync?: int
 * }
 * @phpstan-type BaselineEntry array{0: bool|int|float|string|null, 1: string}
 */
class PortalSnapshotDiffNotifier
{
    private const SINGLE_RECORD_KEY = '_';

    private const PLACEHOLDER_PATTERN = '/:([a-z_][a-z0-9_]*(?:\.[a-z0-9_]+)*)(?:\|(date|path))?/i';

    public function __construct(
        private readonly PortalNotificationCenter $center,
        private readonly PortalDataOwner $owner
    ) {}

    /**
     * Called right after a snapshot is stored. Never throws: a notification
     * problem must not fail the sync that stored the data.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $params
     * @return list<PortalNotification>
     */
    public function process(string $sessionToken, string $dataset, array $data, array $params = []): array
    {
        $rules = $this->rules($dataset);

        if ($rules === []) {
            return [];
        }

        try {
            $baselineKey = $this->baselineKey($sessionToken, $dataset, $params);
            $baseline = Cache::get($baselineKey);
            $previousRules = is_array($baseline) && is_array($baseline['rules'] ?? null) ? $baseline['rules'] : [];
            $now = CarbonImmutable::now()->toISOString();
            $notifications = [];
            $nextRules = [];

            foreach ($rules as $rule) {
                $type = $rule['type'];
                $previous = is_array($previousRules[$type] ?? null) ? $previousRules[$type] : null;
                $current = $this->isReliable($rule, $data) ? $this->observe($rule, $data) : null;

                if ($current === null) {
                    // Unreadable this time: keep what was known.
                    if ($previous !== null) {
                        $nextRules[$type] = $previous;
                    }

                    continue;
                }

                if ($previous !== null) {
                    array_push($notifications, ...$this->diff($dataset, $rule, $previous, $current));
                }

                $nextRules[$type] = $this->mergeBaseline($previous ?? [], $current, $now);
            }

            // Publish first: if storing fails, the baseline is not advanced
            // and the next sync finds the same change again.
            $created = $this->center->publish($sessionToken, $notifications);

            Cache::put($baselineKey, ['rules' => $nextRules, 'updated_at' => $now], $this->baselineRetention());

            return $created;
        } catch (\Throwable $throwable) {
            Log::warning('Portal snapshot notifications failed', [
                'dataset' => $dataset,
                'error' => $throwable->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @return list<NotifyRule>
     */
    public function rules(string $dataset): array
    {
        $rules = config("portal.datasets.{$dataset}.notify", []);

        if (! is_array($rules)) {
            return [];
        }

        return array_values(array_filter($rules, static fn (mixed $rule): bool => is_array($rule)
            && is_string($rule['type'] ?? null)
            && in_array($rule['on'] ?? null, ['added', 'changed'], true)
            && is_string($rule['title'] ?? null)
            && (($rule['on'] ?? null) === 'added' || is_string($rule['field'] ?? null))));
    }

    /**
     * @param  NotifyRule  $rule
     * @param  array<string, mixed>  $data
     */
    private function isReliable(array $rule, array $data): bool
    {
        if (PortalPayloadRecords::records($rule['records'] ?? null, $data) === null) {
            return false;
        }

        foreach ($rule['requires'] ?? [] as $path => $matcher) {
            if (! PortalPayloadRecords::matches($matcher, PortalPayloadRecords::normalize(data_get($data, $path)))) {
                return false;
            }
        }

        return true;
    }

    /**
     * The watched value of every record, keyed by record key.
     *
     * @param  NotifyRule  $rule
     * @param  array<string, mixed>  $data
     * @return array<string, array{value: bool|int|float|string|null, record: array<string, mixed>}>
     */
    private function observe(array $rule, array $data): array
    {
        $observed = [];

        foreach (PortalPayloadRecords::records($rule['records'] ?? null, $data) ?? [] as $record) {
            if (! PortalPayloadRecords::passes($rule['when'] ?? [], $record)) {
                continue;
            }

            $recordKey = isset($rule['records']) ? PortalPayloadRecords::recordKey($rule['key'] ?? [], $record) : self::SINGLE_RECORD_KEY;
            $value = $rule['on'] === 'added' ? true : PortalPayloadRecords::normalize(data_get($record, $rule['field']));

            if ($recordKey === null || $value === null || (isset($rule['ignore']) && PortalPayloadRecords::matches($rule['ignore'], $value))) {
                continue;
            }

            $observed[$recordKey] ??= ['value' => $value, 'record' => $record];
        }

        return $observed;
    }

    /**
     * @param  NotifyRule  $rule
     * @param  array<string, BaselineEntry>  $previous
     * @param  array<string, array{value: bool|int|float|string|null, record: array<string, mixed>}>  $current
     * @return list<array{type: string, dataset: string, record_key: string, dedupe_key: string, title: string, body: string|null, url: string|null, urgency: string}>
     */
    private function diff(string $dataset, array $rule, array $previous, array $current): array
    {
        $notifications = [];

        foreach ($current as $recordKey => ['value' => $new, 'record' => $record]) {
            // PHP turns numeric keys ("5102") into integers.
            $recordKey = (string) $recordKey;
            $known = array_key_exists($recordKey, $previous);
            $old = $known ? $previous[$recordKey][0] : null;

            if ($rule['on'] === 'added') {
                if ($known) {
                    continue;
                }

                $identity = [$dataset, $rule['type'], $recordKey, 'added'];
            } else {
                if ($old === $new
                    || ! PortalPayloadRecords::matches($rule['from'] ?? null, $old)
                    || ! PortalPayloadRecords::matches($rule['to'] ?? null, $new)) {
                    continue;
                }

                // The moment the old value was first seen separates a repeat
                // of the same transition (bill paid again next semester) from
                // the same change found twice.
                $identity = [$dataset, $rule['type'], $recordKey, json_encode($old), $known ? $previous[$recordKey][1] : '', json_encode($new)];
            }

            $notifications[] = [
                'type' => $rule['type'],
                'dataset' => $dataset,
                'record_key' => $recordKey,
                'dedupe_key' => hash('sha256', implode("\0", $identity)),
                'title' => $this->render($rule['title'], $record, $old, $new) ?: 'Ada pembaruan di portal',
                'body' => isset($rule['body']) ? ($this->render($rule['body'], $record, $old, $new) ?: null) : null,
                'url' => isset($rule['url']) ? $this->renderUrl($rule['url'], $record) : null,
                'urgency' => (string) ($rule['urgency'] ?? 'info'),
            ];
        }

        $limit = (int) ($rule['max_per_sync'] ?? config('portal.notifications.max_per_sync', 5));

        if (count($notifications) > $limit) {
            // Many changes at once is more likely a parser change than news;
            // announce the first few and let the baseline absorb the rest.
            Log::info('Portal snapshot notifications capped', [
                'dataset' => $dataset,
                'type' => $rule['type'],
                'found' => count($notifications),
                'kept' => $limit,
            ]);
        }

        return array_slice($notifications, 0, max(0, $limit));
    }

    /**
     * @param  array<string, BaselineEntry>  $previous
     * @param  array<string, array{value: bool|int|float|string|null, record: array<string, mixed>}>  $current
     * @return array<string, BaselineEntry>
     */
    private function mergeBaseline(array $previous, array $current, string $now): array
    {
        $merged = [];

        foreach ($current as $recordKey => ['value' => $value]) {
            $unchanged = array_key_exists($recordKey, $previous) && $previous[$recordKey][0] === $value;
            $merged[$recordKey] = [$value, $unchanged ? $previous[$recordKey][1] : $now];
        }

        $retained = array_diff_key($previous, $merged);
        $room = max(0, (int) config('portal.notifications.max_baseline_records', 500) - count($merged));

        if (count($retained) > $room) {
            uasort($retained, static fn (array $left, array $right): int => strcmp($right[1], $left[1]));
            $retained = array_slice($retained, 0, $room, true);
        }

        return $merged + $retained;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function render(string $template, array $record, mixed $old, mixed $new): string
    {
        $text = (string) preg_replace_callback(self::PLACEHOLDER_PATTERN, function (array $match) use ($record, $old, $new): string {
            $value = match ($match[1]) {
                'old' => $old,
                'new' => $new,
                default => data_get($record, $match[1]),
            };

            return ($match[2] ?? '') === 'date' ? $this->formatDate($value) : $this->display($value);
        }, $template);

        // Missing values must not leave "()" or a dangling separator behind.
        $text = (string) preg_replace('/\(\s*\)/u', '', $text);
        $parts = array_filter(array_map('trim', explode(' · ', $text)), static fn (string $part): bool => $part !== '');

        return trim((string) preg_replace('/\s+/u', ' ', implode(' · ', $parts)), " \t\n\r\0\x0B,:;");
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function renderUrl(string $template, array $record): ?string
    {
        $url = (string) preg_replace_callback(self::PLACEHOLDER_PATTERN, function (array $match) use ($record): string {
            $value = $this->display(data_get($record, $match[1]));

            if (($match[2] ?? '') === 'path') {
                return str_starts_with($value, '/') && ! str_starts_with($value, '//') ? $value : '';
            }

            return rawurlencode($value);
        }, $template);

        return $url === '' ? null : $url;
    }

    private function display(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'ya' : 'tidak',
            is_float($value) => number_format($value, 2, ',', '.'),
            is_scalar($value) => trim((string) $value),
            default => '',
        };
    }

    private function formatDate(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return '';
        }

        try {
            return CarbonImmutable::parse($value)->locale('id')->translatedFormat('j F Y');
        } catch (\Throwable) {
            return trim($value);
        }
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function baselineKey(string $sessionToken, string $dataset, array $params): string
    {
        return 'portal_notify_baseline_'.md5($this->owner->key($sessionToken).'|'.$dataset.'|'.PortalDataCache::paramsHash($params));
    }

    private function baselineRetention(): int
    {
        return (int) config('portal.notifications.baseline_retention', 15552000);
    }
}

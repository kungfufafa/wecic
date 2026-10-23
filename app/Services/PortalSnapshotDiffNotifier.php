<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PortalNotification;
use App\Support\PortalPayloadRecords;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
 * A rule raises at most `max_per_sync` notifications per sync; with
 * `overflow` (a `title` with `:count`, and a `url`) the rest become one
 * summary instead of being dropped. With `repeat_window_days`, an added
 * record that reads exactly like a notification of the same type from the
 * last days (a re-post under a new id) is not announced again. `aliases`
 * (a list of `key`-style path lists, `[['legacy_id']]` when a rule names
 * none) names the keys a record went by before: a record found under one
 * of them is not new and keeps its history, so changing how records are
 * keyed (announcement ids no longer built from the wording) re-keys the
 * baseline instead of announcing everything again. The student's stored
 * notifications of that record are re-keyed with it, their link included,
 * and the baseline remembers every alias of a record it saw, so a record
 * that later goes by one of them again (an announcement falling back to
 * its wording-based id) is not new either.
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
 *     max_per_sync?: int,
 *     overflow?: array{title: string, url?: string|null},
 *     repeat_window_days?: int,
 *     aliases?: list<list<string>>
 * }
 * @phpstan-type BaselineEntry array{0: bool|int|float|string|null, 1: string}
 * @phpstan-type Observation array{value: bool|int|float|string|null, record: array<string, mixed>, aliases: list<string>}
 */
class PortalSnapshotDiffNotifier
{
    private const SINGLE_RECORD_KEY = '_';

    private const PLACEHOLDER_PATTERN = '/:([a-z_][a-z0-9_]*(?:\.[a-z0-9_]+)*)(?:\|(date|path))?/i';

    /**
     * A record keeps the key it had before a change in how it is keyed in
     * `legacy_id` (announcements); records without one have no alias.
     */
    private const DEFAULT_ALIASES = [['legacy_id']];

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
            $renamed = [];
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
                    $found = $this->withoutRepeats($sessionToken, $rule, $this->diff($dataset, $rule, $previous, $current));
                    array_push($notifications, ...$this->capped($dataset, $rule, $found));
                    $renamed[] = [$rule, $this->renamed($previous, $current)];
                }

                $nextRules[$type] = $this->mergeBaseline($previous ?? [], $current, $now);
            }

            // Publish first: if storing fails, the baseline is not advanced
            // and the next sync finds the same change again.
            $created = $this->center->publish($sessionToken, $notifications);

            foreach ($renamed as [$rule, $records]) {
                $this->rekeyNotifications($sessionToken, $dataset, $rule, $records);
            }

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
     * @return array<string, Observation>
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

            $observed[$recordKey] ??= ['value' => $value, 'record' => $record, 'aliases' => $this->aliasKeys($rule, $record, $recordKey)];
        }

        return $observed;
    }

    /**
     * The keys the record went by before (`aliases`), other than its own.
     *
     * @param  NotifyRule  $rule
     * @param  array<string, mixed>  $record
     * @return list<string>
     */
    private function aliasKeys(array $rule, array $record, string $recordKey): array
    {
        $keys = [];

        foreach (array_key_exists('aliases', $rule) ? (is_array($rule['aliases']) ? $rule['aliases'] : []) : self::DEFAULT_ALIASES as $paths) {
            $key = is_array($paths) ? PortalPayloadRecords::recordKey(array_values(array_filter($paths, 'is_string')), $record) : null;

            if ($key !== null && $key !== $recordKey && ! in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * The key the baseline knows a record by: its own, else the first alias
     * it went by.
     *
     * @param  array<string, BaselineEntry>  $previous
     * @param  list<string>  $aliases
     */
    private function knownKey(array $previous, string $recordKey, array $aliases): ?string
    {
        foreach ([$recordKey, ...$aliases] as $key) {
            if (array_key_exists($key, $previous)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * What the baseline knows about a record (see knownKey()).
     *
     * @param  array<string, BaselineEntry>  $previous
     * @param  list<string>  $aliases
     * @return BaselineEntry|null
     */
    private function previousEntry(array $previous, string $recordKey, array $aliases): ?array
    {
        $key = $this->knownKey($previous, $recordKey, $aliases);

        return $key === null ? null : $previous[$key];
    }

    /**
     * Records the baseline only knows under an alias: the key they went by
     * then => their key and record now.
     *
     * @param  array<string, BaselineEntry>  $previous
     * @param  array<string, Observation>  $current
     * @return array<string, array{key: string, record: array<string, mixed>}>
     */
    private function renamed(array $previous, array $current): array
    {
        $renamed = [];

        foreach ($current as $recordKey => ['record' => $record, 'aliases' => $aliases]) {
            $recordKey = (string) $recordKey;
            $knownKey = $this->knownKey($previous, $recordKey, $aliases);

            if ($knownKey !== null && $knownKey !== $recordKey) {
                $renamed[$knownKey] = ['key' => $recordKey, 'record' => $record];
            }
        }

        return $renamed;
    }

    /**
     * A notification sent under a record's old key follows the record to its
     * new key, link included: a link that only resolves the old key stops
     * working once the record changes again (an announcement's wording-based
     * id after staff edit its body). An announcement is also linked by
     * notifications of other types (deadline reminders): they follow too.
     *
     * @param  NotifyRule  $rule
     * @param  array<string, array{key: string, record: array<string, mixed>}>  $renamed
     */
    private function rekeyNotifications(string $sessionToken, string $dataset, array $rule, array $renamed): void
    {
        if ($renamed === []) {
            return;
        }

        $ownerHash = $this->owner->key($sessionToken);

        foreach ($renamed as $oldKey => ['key' => $newKey, 'record' => $record]) {
            $this->center->rekey(
                $ownerHash,
                $dataset,
                $rule['type'],
                (string) $oldKey,
                $newKey,
                isset($rule['url']) ? $this->renderUrl($rule['url'], $record) : null
            );
        }

        if ($dataset === PortalAnnouncementIds::DATASET) {
            $this->center->rekeyAnnouncements($ownerHash, array_combine(
                array_map('strval', array_keys($renamed)),
                array_column($renamed, 'key')
            ));
        }
    }

    /**
     * @param  NotifyRule  $rule
     * @param  array<string, BaselineEntry>  $previous
     * @param  array<string, Observation>  $current
     * @return list<array{type: string, dataset: string, record_key: string, dedupe_key: string, title: string, body: string|null, url: string|null, urgency: string}>
     */
    private function diff(string $dataset, array $rule, array $previous, array $current): array
    {
        $notifications = [];

        foreach ($current as $recordKey => ['value' => $new, 'record' => $record, 'aliases' => $aliases]) {
            // PHP turns numeric keys ("5102") into integers.
            $recordKey = (string) $recordKey;
            $entry = $this->previousEntry($previous, $recordKey, $aliases);
            $known = $entry !== null;
            $old = $known ? $entry[0] : null;

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
                $identity = [$dataset, $rule['type'], $recordKey, json_encode($old), $known ? $entry[1] : '', json_encode($new)];
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

        return $notifications;
    }

    /**
     * Drops added records that read exactly like a recent notification of
     * the same type (`repeat_window_days`): staff re-posting an announcement
     * (or changing its title) gives it a new id but tells the student
     * nothing new.
     * Title and body both count, so two different notices under one generic
     * title are still both announced.
     *
     * @param  NotifyRule  $rule
     * @param  list<array{type: string, dataset: string, record_key: string, dedupe_key: string, title: string, body: string|null, url: string|null, urgency: string}>  $notifications
     * @return list<array{type: string, dataset: string, record_key: string, dedupe_key: string, title: string, body: string|null, url: string|null, urgency: string}>
     */
    private function withoutRepeats(string $sessionToken, array $rule, array $notifications): array
    {
        $days = (int) ($rule['repeat_window_days'] ?? 0);

        if ($days <= 0 || $rule['on'] !== 'added' || $notifications === []) {
            return $notifications;
        }

        $seen = PortalNotification::query()
            ->forOwner($this->owner->key($sessionToken))
            ->where('type', $rule['type'])
            ->where('created_at', '>=', now()->subDays($days))
            ->get(['title', 'body'])
            ->mapWithKeys(fn (PortalNotification $notification): array => [$this->readsAs($notification->title, $notification->body) => true])
            ->all();
        $kept = [];

        foreach ($notifications as $notification) {
            // Stored titles are cut at 255 characters (PortalNotificationCenter).
            $readsAs = $this->readsAs(mb_substr($notification['title'], 0, 255), $notification['body']);

            if (! isset($seen[$readsAs])) {
                $seen[$readsAs] = true;
                $kept[] = $notification;
            }
        }

        return $kept;
    }

    private function readsAs(string $title, ?string $body): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $title."\n".($body ?? ''))));
    }

    /**
     * At most `max_per_sync` per rule: many changes at once is more likely
     * a parser change than news, so the first few are announced and the
     * baseline absorbs the rest, summed up in one `overflow` notification
     * when the rule has one (a semester's grades can land together).
     *
     * @param  NotifyRule  $rule
     * @param  list<array{type: string, dataset: string, record_key: string, dedupe_key: string, title: string, body: string|null, url: string|null, urgency: string}>  $notifications
     * @return list<array{type: string, dataset: string, record_key: string|null, dedupe_key: string, title: string, body: string|null, url: string|null, urgency: string}>
     */
    private function capped(string $dataset, array $rule, array $notifications): array
    {
        $limit = max(0, (int) ($rule['max_per_sync'] ?? config('portal.notifications.max_per_sync', 5)));

        if (count($notifications) <= $limit) {
            return $notifications;
        }

        Log::info('Portal snapshot notifications capped', [
            'dataset' => $dataset,
            'type' => $rule['type'],
            'found' => count($notifications),
            'kept' => $limit,
        ]);

        $kept = array_slice($notifications, 0, $limit);
        $rest = array_slice($notifications, $limit);
        $overflow = $rule['overflow'] ?? null;

        if (! is_array($overflow) || ! is_string($overflow['title'] ?? null)) {
            return $kept;
        }

        $kept[] = [
            'type' => $rule['type'],
            'dataset' => $dataset,
            'record_key' => null,
            'dedupe_key' => hash('sha256', implode("\0", [$dataset, $rule['type'], 'overflow', ...array_column($rest, 'dedupe_key')])),
            'title' => str_replace(':count', (string) count($rest), $overflow['title']),
            'body' => Str::limit(implode(' · ', array_column($rest, 'title')), 500),
            'url' => is_string($overflow['url'] ?? null) ? $overflow['url'] : null,
            'urgency' => (string) ($rule['urgency'] ?? 'info'),
        ];

        return $kept;
    }

    /**
     * A record found under an alias carries its history over to its key;
     * the alias entry stays as well, so a copy still keyed the old way (a
     * page cached before the change) is not new either. Every alias of a
     * record seen now is remembered with it: an announcement first seen
     * under its stable id that falls back to its wording-based id (a second
     * one with the same title, day and unit) is still known, while the new
     * one's own wording-based id is not.
     *
     * @param  array<string, BaselineEntry>  $previous
     * @param  array<string, Observation>  $current
     * @return array<string, BaselineEntry>
     */
    private function mergeBaseline(array $previous, array $current, string $now): array
    {
        $merged = [];

        foreach ($current as $recordKey => ['value' => $value, 'aliases' => $aliases]) {
            $entry = $this->previousEntry($previous, (string) $recordKey, $aliases);
            $unchanged = $entry !== null && $entry[0] === $value;
            $merged[$recordKey] = [$value, $unchanged ? $entry[1] : $now];
        }

        foreach ($current as $recordKey => ['aliases' => $aliases]) {
            foreach ($aliases as $alias) {
                $merged[$alias] ??= $merged[$recordKey];
            }
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

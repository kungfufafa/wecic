<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Notebook;
use App\Models\PortalArchivedFile;
use App\Models\PortalArchiveVersion;
use App\Models\PortalNotification;
use App\Models\PortalOfflineAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Moves everything MyCIC keeps for a student from an owner key made with an
 * earlier secret to the current one (see PortalDataOwner), so the archive,
 * archived files, offline access, notifications and notebooks survive a
 * change of the owner secret instead of being orphaned.
 *
 * A row the current key already has an equivalent of (the same unique key)
 * is dropped instead of moved: it was stored before the student logged in
 * under the current key, so the current one is newer. Only a background
 * sync opt-out carries over, so a student who switched it off is not synced
 * again because of the move.
 */
class PortalOwnerRekeyer
{
    /**
     * @return int Rows moved or dropped.
     */
    public function rekey(string $from, string $to): int
    {
        if ($from === $to) {
            return 0;
        }

        return DB::transaction(fn (): int => $this->moveOfflineAccount($from, $to)
            + $this->moveUnique(PortalArchiveVersion::class, ['dataset', 'params_hash', 'content_hash', 'sighting'], $from, $to)
            + $this->moveUnique(PortalArchivedFile::class, ['source_url'], $from, $to)
            + $this->moveUnique(PortalNotification::class, ['dedupe_key'], $from, $to)
            + Notebook::query()->where('owner_hash', $from)->toBase()->update(['owner_hash' => $to]));
    }

    private function moveOfflineAccount(string $from, string $to): int
    {
        $legacy = PortalOfflineAccount::query()->where('owner_hash', $from)->toBase()->first(['id', 'background_sync_disabled_at']);

        if ($legacy === null) {
            return 0;
        }

        $current = PortalOfflineAccount::query()->where('owner_hash', $to)->toBase()->first(['id', 'background_sync_disabled_at']);

        if ($current === null) {
            return PortalOfflineAccount::query()->whereKey($legacy->id)->toBase()->update(['owner_hash' => $to]);
        }

        if ($legacy->background_sync_disabled_at !== null && $current->background_sync_disabled_at === null) {
            PortalOfflineAccount::query()->whereKey($current->id)->toBase()->update([
                'background_sync_disabled_at' => $legacy->background_sync_disabled_at,
                'credentials' => null,
            ]);
        }

        return PortalOfflineAccount::query()->whereKey($legacy->id)->toBase()->delete();
    }

    /**
     * @param  class-string<Model>  $model
     * @param  list<string>  $columns  The table's unique key besides `owner_hash`.
     */
    private function moveUnique(string $model, array $columns, string $from, string $to): int
    {
        $legacy = $model::query()->where('owner_hash', $from)->toBase()->get(['id', ...$columns]);

        if ($legacy->isEmpty()) {
            return 0;
        }

        $taken = $model::query()->where('owner_hash', $to)->toBase()->get($columns)
            ->mapWithKeys(fn (object $row): array => [$this->signature($row, $columns) => true]);
        $duplicates = $legacy
            ->filter(fn (object $row): bool => $taken->has($this->signature($row, $columns)))
            ->pluck('id')
            ->all();

        if ($duplicates !== []) {
            $model::query()->whereKey($duplicates)->delete();
        }

        return count($duplicates) + $model::query()->where('owner_hash', $from)->toBase()->update(['owner_hash' => $to]);
    }

    /**
     * @param  list<string>  $columns
     */
    private function signature(object $row, array $columns): string
    {
        return implode("\0", array_map(static fn (string $column): string => (string) ($row->{$column} ?? ''), $columns));
    }
}

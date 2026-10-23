<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Archive versions recorded before PortalDataArchive::observedMoment()
 * existed stored the moment a build began (`now()->toISOString()`) as UTC
 * wall-clock time, hours off the app-timezone wall-clock time every other
 * column (and accept/reject/dismiss) uses. This moves those values to the
 * app timezone; correct values are left alone, so running it again, or with
 * a UTC app timezone, changes nothing.
 *
 * Only record() wrote these values, and every record() write sets
 * `last_seen_at` to the moment the build began:
 * - `first_seen_at` is set once, when record() creates the row, moments
 *   before `created_at`: it is off when it reads as UTC just before
 *   `created_at` and not as app-timezone time.
 * - `last_seen_at` is off when it reads that way against `updated_at` (the
 *   row's last write was record(), not an accept, reject or dismiss, which
 *   stamp `resolved_at` with `updated_at`), or when it reads as earlier than
 *   the repaired `first_seen_at`, or equals it.
 * - `trusted_at` comes from record() or from accept(), which stamps
 *   `resolved_at` with the same value and is always right. A record() value
 *   is off when `last_seen_at` is (every record() write came before the
 *   fix), or when it equals `first_seen_at` or reads as earlier than it.
 */
return new class extends Migration
{
    /**
     * Seconds a write may come before (clock skew) or after (a slow build)
     * the moment the build began.
     */
    private const EARLIEST = -120;

    private const LATEST = 1800;

    /**
     * Seconds `resolved_at` and `updated_at` of one accept, reject or dismiss may differ.
     */
    private const SAME_WRITE = 5;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('portal_archive_versions')) {
            return;
        }

        $timezone = (string) config('app.timezone', 'UTC');

        DB::table('portal_archive_versions')
            ->select(['id', 'first_seen_at', 'last_seen_at', 'trusted_at', 'resolved_at', 'created_at', 'updated_at'])
            ->chunkById(500, function ($rows) use ($timezone): void {
                foreach ($rows as $row) {
                    $repaired = $this->repaired($row, $timezone);

                    if ($repaired !== []) {
                        DB::table('portal_archive_versions')->where('id', $row->id)->update($repaired);
                    }
                }
            });
    }

    /**
     * Reverse the migrations.
     *
     * The repaired times are the right ones; they are not put back.
     */
    public function down(): void {}

    /**
     * @return array<string, string> The columns to rewrite, as app-timezone wall-clock time.
     */
    private function repaired(object $row, string $timezone): array
    {
        $first = $this->text($row->first_seen_at);
        $last = $this->text($row->last_seen_at);
        $trusted = $this->text($row->trusted_at);
        $resolved = $this->text($row->resolved_at);
        $created = $this->local($this->text($row->created_at), $timezone);
        $updated = $this->local($this->text($row->updated_at), $timezone);

        $closedByUpdate = $resolved !== null && $updated !== null
            && abs($updated->getTimestamp() - $this->local($resolved, $timezone)->getTimestamp()) <= self::SAME_WRITE;
        $lastByUpdate = $last !== null && ! $closedByUpdate && $this->storedAsUtc($last, $updated, $timezone);
        $firstUtc = $first !== null && ($lastByUpdate || $this->storedAsUtc($first, $created, $timezone));
        $firstRepaired = $firstUtc ? $this->fromUtc($first, $timezone) : null;
        $lastUtc = $last !== null && ($lastByUpdate || ($firstRepaired !== null && $this->beforeOrSame($last, $first, $firstRepaired, $timezone)));
        $trustedUtc = $trusted !== null
            && $trusted !== $resolved
            && ($lastUtc || ($firstRepaired !== null && $this->beforeOrSame($trusted, $first, $firstRepaired, $timezone)));

        return array_filter([
            'first_seen_at' => $firstRepaired?->format('Y-m-d H:i:s'),
            'last_seen_at' => $lastUtc ? $this->fromUtc($last, $timezone)->format('Y-m-d H:i:s') : null,
            'trusted_at' => $trustedUtc ? $this->fromUtc($trusted, $timezone)->format('Y-m-d H:i:s') : null,
        ]);
    }

    /**
     * Whether `$value` is UTC wall-clock time written at `$written`: read as
     * UTC it lies just before the write, read as app-timezone time it does
     * not (so in a UTC app timezone, or an ambiguous one, nothing is off).
     */
    private function storedAsUtc(string $value, ?CarbonImmutable $written, string $timezone): bool
    {
        return $written !== null
            && $this->justBefore($this->fromUtc($value, $timezone), $written)
            && ! $this->justBefore($this->local($value, $timezone), $written);
    }

    /**
     * A value equal to the original `first_seen_at`, or earlier than its
     * repaired time, cannot be right: nothing was seen before it was first seen.
     */
    private function beforeOrSame(string $value, string $first, CarbonImmutable $firstRepaired, string $timezone): bool
    {
        return $value === $first || $this->local($value, $timezone)->lessThan($firstRepaired);
    }

    private function justBefore(CarbonImmutable $moment, CarbonImmutable $written): bool
    {
        $seconds = $written->getTimestamp() - $moment->getTimestamp();

        return $seconds >= self::EARLIEST && $seconds <= self::LATEST;
    }

    private function fromUtc(string $value, string $timezone): CarbonImmutable
    {
        return CarbonImmutable::parse($value, 'UTC')->setTimezone($timezone);
    }

    /**
     * @return ($value is null ? null : CarbonImmutable)
     */
    private function local(?string $value, string $timezone): ?CarbonImmutable
    {
        return $value !== null ? CarbonImmutable::parse($value, $timezone) : null;
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
};

<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The notebook's model could not answer. The message is safe to show to the
 * student; details stay in the log.
 */
class NotebookAiException extends RuntimeException
{
    public const REASON_REJECTED = 'rejected';

    public const REASON_TIMED_OUT = 'timed_out';

    public const REASON_TOO_LONG = 'too_long';

    public const REASON_BLOCKED = 'blocked';

    public const REASON_EMPTY = 'empty';

    public const REASON_DISABLED = 'disabled';

    public const REASON_NOT_CONFIGURED = 'not_configured';

    public const REASON_MISCONFIGURED = 'misconfigured';

    /**
     * What a misconfiguration is about: the API key (or the project behind
     * it), or the model settings (model id, thinking budget).
     */
    public const CAUSE_KEY = 'key';

    public const CAUSE_MODEL = 'model';

    /**
     * Failures that come with a full model answer, which was paid for
     * although it could not be used.
     */
    private const ANSWERED_REASONS = [self::REASON_TOO_LONG, self::REASON_BLOCKED, self::REASON_EMPTY];

    /**
     * Failures that only the server's settings can fix.
     */
    private const CONFIGURATION_REASONS = [self::REASON_DISABLED, self::REASON_NOT_CONFIGURED, self::REASON_MISCONFIGURED];

    /**
     * Why the model failed, when a caller words it differently (a source
     * that is too long to read, rather than a question that took too long).
     */
    private ?string $reason = null;

    public function is(string $reason): bool
    {
        return $this->reason === $reason;
    }

    /**
     * Whether the AI is off or set up wrong on the server, as opposed to a
     * problem with this one request.
     */
    public function concernsConfiguration(): bool
    {
        return in_array($this->reason, self::CONFIGURATION_REASONS, true);
    }

    /**
     * Whether the model did answer (cut off, withheld, or unreadable), as
     * opposed to never answering (busy, unreachable, misconfigured).
     */
    public function modelAnswered(): bool
    {
        return in_array($this->reason, self::ANSWERED_REASONS, true);
    }

    public static function notConfigured(): self
    {
        return self::because(self::REASON_NOT_CONFIGURED, 'Fitur AI Notebook belum diaktifkan di server WeCIC (GEMINI_API_KEY belum diisi).', 503);
    }

    /**
     * The notebook's AI is switched off on the server (NOTEBOOK_ENABLED).
     */
    public static function disabled(): self
    {
        return self::because(self::REASON_DISABLED, 'Fitur AI Notebook dimatikan di server WeCIC.', 503);
    }

    public static function unavailable(): self
    {
        return new self('Layanan AI sedang sibuk atau tidak bisa dihubungi. Coba lagi beberapa saat.', 503);
    }

    /**
     * The key or the model settings are wrong; retrying will not help.
     *
     * @param  self::CAUSE_*  $cause
     */
    public static function misconfigured(string $cause = self::CAUSE_MODEL): self
    {
        return self::because(self::REASON_MISCONFIGURED, $cause === self::CAUSE_KEY
            ? 'Fitur AI Notebook sedang tidak tersedia karena kunci API Gemini di server WeCIC ditolak. Hubungi pengelola WeCIC.'
            : 'Fitur AI Notebook sedang tidak tersedia karena pengaturan model AI di server WeCIC ditolak (model tidak dikenali, sudah tidak tersedia, atau pengaturannya tidak cocok). Hubungi pengelola WeCIC.', 503);
    }

    /**
     * The model refused the request itself (too large, or data it cannot read).
     */
    public static function rejected(): self
    {
        return self::because(self::REASON_REJECTED, 'Permintaan ini tidak bisa diproses model AI. Coba lagi dengan lebih sedikit sumber atau pertanyaan yang lebih singkat.', 422);
    }

    /**
     * The model did not finish within the time it was given.
     */
    public static function timedOut(): self
    {
        return self::because(self::REASON_TIMED_OUT, 'Model AI tidak selesai dalam batas waktu. Coba lagi dengan lebih sedikit sumber atau pertanyaan yang lebih singkat.', 503);
    }

    /**
     * A structured output was cut off before it was complete.
     */
    public static function tooLong(): self
    {
        return self::because(self::REASON_TOO_LONG, 'Hasilnya terlalu panjang untuk dibuat sekaligus. Centang lebih sedikit sumber, lalu coba lagi.', 502);
    }

    public static function blocked(): self
    {
        return self::because(self::REASON_BLOCKED, 'Permintaan ini ditolak oleh filter keamanan model AI. Coba ubah pertanyaannya.', 422);
    }

    public static function emptyAnswer(): self
    {
        return self::because(self::REASON_EMPTY, 'Model AI tidak memberi jawaban. Coba lagi.', 502);
    }

    /**
     * The model spent its whole output limit (thinking, mostly) and stopped
     * before writing anything, even with a larger limit.
     */
    public static function outputExhausted(): self
    {
        return self::because(self::REASON_TOO_LONG, 'Model AI kehabisan batas panjang jawaban sebelum sempat menjawab. Persempit pertanyaan atau centang lebih sedikit sumber, lalu coba lagi.', 502);
    }

    public static function dailyLimitReached(): self
    {
        return new self('Batas penggunaan Notebook hari ini sudah tercapai. Coba lagi besok.', 429);
    }

    private static function because(string $reason, string $message, int $code): self
    {
        $exception = new self($message, $code);
        $exception->reason = $reason;

        return $exception;
    }
}

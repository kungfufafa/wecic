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
    public static function notConfigured(): self
    {
        return new self('Fitur AI Notebook belum diaktifkan di server MyCIC (GEMINI_API_KEY belum diisi).', 503);
    }

    public static function unavailable(): self
    {
        return new self('Layanan AI sedang sibuk atau tidak bisa dihubungi. Coba lagi beberapa saat.', 503);
    }

    public static function blocked(): self
    {
        return new self('Permintaan ini ditolak oleh filter keamanan model AI. Coba ubah pertanyaannya.', 422);
    }

    public static function emptyAnswer(): self
    {
        return new self('Model AI tidak memberi jawaban. Coba lagi.', 502);
    }

    public static function dailyLimitReached(): self
    {
        return new self('Batas penggunaan Notebook hari ini sudah tercapai. Coba lagi besok.', 429);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Mark notifications as read: the listed `ids`, or everything with `all`
 * (up to `up_to`, the newest id the page has shown).
 */
class PortalNotificationReadRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            /**
             * Id notifikasi yang ditandai sudah dibaca.
             *
             * @example [120, 118]
             */
            'ids' => ['required_without:all', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'min:1'],
            /**
             * Tandai semua notifikasi sudah dibaca.
             *
             * @example true
             */
            'all' => ['sometimes', 'accepted'],
            /**
             * Dengan `all`: hanya notifikasi sampai id ini (id terbesar yang sudah tampil), agar notifikasi
             * yang baru masuk dan belum terlihat tidak ikut ditandai.
             *
             * @example 120
             */
            'up_to' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ids.required_without' => 'Pilih notifikasi yang ingin ditandai, atau kirim all=true.',
            'ids.array' => 'Daftar notifikasi tidak valid.',
            'ids.min' => 'Pilih minimal satu notifikasi.',
            'ids.max' => 'Maksimal 100 notifikasi sekaligus.',
            'ids.*.integer' => 'Id notifikasi harus berupa angka.',
            'ids.*.min' => 'Id notifikasi tidak valid.',
            'all.accepted' => 'Nilai all harus true.',
            'up_to.integer' => 'Batas notifikasi harus berupa angka.',
            'up_to.min' => 'Batas notifikasi tidak valid.',
        ];
    }

    /**
     * @return list<int>|null Null means every unread notification.
     */
    public function ids(): ?array
    {
        if ($this->boolean('all')) {
            return null;
        }

        return array_values(array_unique(array_map('intval', (array) $this->validated('ids'))));
    }

    /**
     * The highest id the student has seen; null leaves newer ones included.
     */
    public function upTo(): ?int
    {
        $upTo = $this->validated('up_to');

        return $upTo === null ? null : (int) $upTo;
    }
}

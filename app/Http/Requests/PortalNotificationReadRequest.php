<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Mark notifications as read: the listed `ids`, or everything with `all`.
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
}

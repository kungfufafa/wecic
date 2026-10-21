<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Notification list paging: newest first, `before` continues from the last
 * id of the previous page.
 */
class PortalNotificationIndexRequest extends ApiFormRequest
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
             * Hanya notifikasi yang belum dibaca.
             *
             * @example true
             */
            'unread' => ['sometimes', 'boolean'],
            /**
             * Jumlah notifikasi per halaman (1–50).
             *
             * @example 30
             */
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
            /**
             * Lanjutkan dari id terakhir halaman sebelumnya.
             *
             * @example 120
             */
            'before' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'unread.boolean' => 'Filter belum dibaca harus true atau false.',
            'limit.integer' => 'Jumlah notifikasi harus berupa angka.',
            'limit.min' => 'Jumlah notifikasi minimal 1.',
            'limit.max' => 'Jumlah notifikasi maksimal 50.',
            'before.integer' => 'Penanda halaman harus berupa angka.',
            'before.min' => 'Penanda halaman minimal 1.',
        ];
    }

    public function unreadOnly(): bool
    {
        return $this->boolean('unread');
    }

    public function limit(): int
    {
        return (int) ($this->validated('limit') ?? config('portal.notifications.page_size', 30));
    }

    public function before(): ?int
    {
        $before = $this->validated('before');

        return $before === null ? null : (int) $before;
    }
}

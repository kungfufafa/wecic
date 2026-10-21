<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Announcement feed paging (F03). Without `page` the merged feed of the
 * first pages is returned; with `page` exactly that portal page.
 */
class PortalAnnouncementRequest extends ApiFormRequest
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
             * Nomor halaman portal (3 pengumuman per halaman). Kosongkan untuk feed gabungan.
             *
             * @example 4
             */
            'page' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'page.integer' => 'Nomor halaman harus berupa angka.',
            'page.min' => 'Nomor halaman minimal 1.',
            'page.max' => 'Nomor halaman maksimal 500.',
        ];
    }

    public function page(): ?int
    {
        $page = $this->validated('page');

        return $page === null ? null : (int) $page;
    }
}

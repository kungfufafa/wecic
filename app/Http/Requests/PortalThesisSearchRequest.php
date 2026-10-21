<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\ThesisRepositorySearch;

/**
 * Search, filters and title similarity over the thesis repository (F47).
 * The planned title of `similar_to` is only compared, never stored or logged.
 */
class PortalThesisSearchRequest extends ApiFormRequest
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
             * Kata kunci; semua kata harus cocok dengan judul, pembimbing, penulis, atau NIM.
             *
             * @example sistem informasi persediaan
             */
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            /**
             * Program studi (tanpa membedakan huruf besar/kecil).
             *
             * @example Sistem Informasi
             */
            'prodi' => ['sometimes', 'nullable', 'string', 'max:100'],
            /**
             * Tahun akademik `yyyy/yyyy`.
             *
             * @example 2023/2024
             */
            'ta' => ['sometimes', 'nullable', 'string', 'regex:/^\d{4}\/\d{4}$/'],
            /**
             * Nama pembimbing; gelar setelah koma dan titik diabaikan.
             *
             * @example Dosen Contoh Alfa
             */
            'pembimbing' => ['sometimes', 'nullable', 'string', 'max:150'],
            /**
             * Urutan hasil. Bawaan: `relevansi` bila ada `q`, selain itu `terbaru`.
             */
            'sort' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', ThesisRepositorySearch::SORTS)],
            /**
             * Halaman hasil.
             *
             * @example 1
             */
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            /**
             * Jumlah hasil per halaman (10–50, bawaan 20).
             *
             * @example 20
             */
            'per_page' => ['sometimes', 'integer', 'min:'.ThesisRepositorySearch::MIN_PER_PAGE, 'max:'.ThesisRepositorySearch::MAX_PER_PAGE],
            /**
             * Rencana judul untuk "Cek judul": 5 skripsi paling mirip. Tidak disimpan maupun dicatat.
             *
             * @example Sistem informasi inventaris berbasis web
             */
            'similar_to' => ['sometimes', 'nullable', 'string', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'q.string' => 'Kata kunci harus berupa teks.',
            'q.max' => 'Kata kunci maksimal 100 karakter.',
            'prodi.string' => 'Program studi harus berupa teks.',
            'prodi.max' => 'Program studi maksimal 100 karakter.',
            'ta.string' => 'Tahun akademik harus berupa teks.',
            'ta.regex' => 'Tahun akademik harus berformat yyyy/yyyy, misalnya 2023/2024.',
            'pembimbing.string' => 'Nama pembimbing harus berupa teks.',
            'pembimbing.max' => 'Nama pembimbing maksimal 150 karakter.',
            'sort.in' => 'Urutan harus terbaru, judul, atau relevansi.',
            'page.integer' => 'Nomor halaman harus berupa angka.',
            'page.min' => 'Nomor halaman minimal 1.',
            'page.max' => 'Nomor halaman maksimal 1000.',
            'per_page.integer' => 'Jumlah per halaman harus berupa angka.',
            'per_page.min' => 'Jumlah per halaman minimal '.ThesisRepositorySearch::MIN_PER_PAGE.'.',
            'per_page.max' => 'Jumlah per halaman maksimal '.ThesisRepositorySearch::MAX_PER_PAGE.'.',
            'similar_to.string' => 'Rencana judul harus berupa teks.',
            'similar_to.max' => 'Rencana judul maksimal 200 karakter.',
        ];
    }

    /**
     * @return array{q: string|null, prodi: string|null, ta: string|null, pembimbing: string|null, sort: string|null, page: int, per_page: int}
     */
    public function filters(): array
    {
        return [
            'q' => $this->text('q'),
            'prodi' => $this->text('prodi'),
            'ta' => $this->text('ta'),
            'pembimbing' => $this->text('pembimbing'),
            'sort' => $this->text('sort'),
            'page' => (int) ($this->validated('page') ?? 1),
            'per_page' => (int) ($this->validated('per_page') ?? ThesisRepositorySearch::DEFAULT_PER_PAGE),
        ];
    }

    public function similarTo(): ?string
    {
        return $this->text('similar_to');
    }

    private function text(string $key): ?string
    {
        $value = $this->validated($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}

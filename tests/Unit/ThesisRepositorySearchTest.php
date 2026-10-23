<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ThesisRepositorySearch;
use PHPUnit\Framework\TestCase;

class ThesisRepositorySearchTest extends TestCase
{
    public function test_the_program_filter_ignores_case(): void
    {
        $result = $this->search(['prodi' => 'sistem informasi']);

        $this->assertSame(['r1', 'r3', 'r6'], $this->keys($result));
        $this->assertSame('sistem informasi', $result['meta']['filters']['prodi']);
    }

    public function test_every_query_token_must_match(): void
    {
        $this->assertSame(['r1'], $this->keys($this->search(['q' => 'persediaan web'])));
        $this->assertSame([], $this->keys($this->search(['q' => 'persediaan android'])));
    }

    public function test_query_text_is_compared_lowercased_and_in_ascii(): void
    {
        $this->assertSame(['r4'], $this->keys($this->search(['q' => 'KOPERASI ÉLÉKTRONIK'])));
    }

    public function test_a_title_match_ranks_above_an_advisor_match(): void
    {
        // "alfa" is in r2's title and is the first advisor of r1 and r3.
        $result = $this->search(['q' => 'alfa']);

        $this->assertSame('relevansi', $result['meta']['sort']);
        $this->assertSame('r2', $this->keys($result)[0]);
        $this->assertSame(['r1', 'r3'], array_slice($this->keys($result), 1, 2));
    }

    public function test_the_advisor_filter_and_facet_ignore_academic_titles(): void
    {
        $facet = $this->search([])['meta']['facets']['pembimbing'];
        $alfa = array_values(array_filter($facet, static fn (array $bucket): bool => $bucket['key'] === 'dosen contoh alfa'));

        // "…, S.Kom., M.Kom." and "…, S.Kom, M.Kom" are one advisor.
        $this->assertCount(1, $alfa);
        $this->assertSame(2, $alfa[0]['count']);
        $this->assertSame(['r1', 'r3'], $this->keys($this->search(['pembimbing' => 'Dosen Contoh Alfa, M.T.'])));
    }

    public function test_an_advisor_who_earned_a_prefix_title_stays_one_advisor(): void
    {
        $records = [
            $this->record('a', 'Sistem Pakar Diagnosa', 'Sistem Informasi', '2020/2021', 'Ana Putri, M.Kom.'),
            $this->record('b', 'Aplikasi Kasir', 'Sistem Informasi', '2024/2025', 'Dr. Ana Putri, M.Kom.'),
            $this->record('c', 'Analisis Jaringan', 'Teknik Informatika', '2025/2026', 'Prof. Dr.Ir. Ana Putri, M.Kom.'),
            $this->record('d', 'Sistem Absensi', 'Teknik Informatika', '2025/2026', 'Hj. Budi Santoso, M.T.'),
        ];
        $search = new ThesisRepositorySearch;

        $filtered = $search->search($records, ['pembimbing' => 'Dr. Ana Putri, M.Kom.', 'sort' => 'judul']);
        $facet = $search->search($records, [])['meta']['facets']['pembimbing'];

        $this->assertSame(['c', 'b', 'a'], array_column($filtered['items'], 'record_key'));
        $this->assertSame(['ana putri' => 3, 'budi santoso' => 1], array_column($facet, 'count', 'key'));
        $this->assertSame('ana putri', ThesisRepositorySearch::advisorKey('Ana Putri, S.Kom., M.Kom.'));
        // A name that is nothing but a title keeps it rather than becoming empty.
        $this->assertSame('dr', ThesisRepositorySearch::advisorKey('Dr.'));
    }

    public function test_facets_count_every_other_filter_but_not_their_own(): void
    {
        $result = $this->search(['prodi' => 'Sistem Informasi', 'ta' => '2023/2024']);

        $this->assertSame(['r1'], $this->keys($result));
        // The program facet ignores the program filter: it shows what picking another program would give.
        $this->assertSame(
            ['sistem informasi' => 1, 'teknik informatika' => 1],
            array_column($result['meta']['facets']['prodi'], 'count', 'key')
        );
        $this->assertSame(['2023/2024' => 1, '2022/2023' => 1], array_column($result['meta']['facets']['ta'], 'count', 'key'));
    }

    public function test_the_academic_year_facet_is_newest_first(): void
    {
        $this->assertSame(['2023/2024', '2022/2023', '2019/2020'], array_column($this->search([])['meta']['facets']['ta'], 'key'));
    }

    public function test_pages_stop_at_the_last_page(): void
    {
        $first = $this->search(['per_page' => 10]);
        $records = [];

        for ($index = 0; $index < 25; $index++) {
            $records[] = $this->record("x{$index}", 'Judul '.$index, 'MANAJEMEN', '2020/2021', null);
        }

        $search = new ThesisRepositorySearch;
        $third = $search->search($records, ['page' => 3, 'per_page' => 10]);
        $beyond = $search->search($records, ['page' => 4, 'per_page' => 10]);

        $this->assertSame(1, $first['meta']['last_page']);
        $this->assertFalse($first['meta']['has_more']);
        $this->assertSame(3, $third['meta']['last_page']);
        $this->assertCount(5, $third['items']);
        $this->assertFalse($third['meta']['has_more']);
        $this->assertSame([], $beyond['items']);
        $this->assertSame(25, $beyond['meta']['total']);
    }

    public function test_per_page_is_bounded(): void
    {
        $search = new ThesisRepositorySearch;

        $this->assertSame(50, $search->search([], ['per_page' => 500])['meta']['per_page']);
        $this->assertSame(10, $search->search([], ['per_page' => 1])['meta']['per_page']);
        $this->assertSame(20, $search->search([], [])['meta']['per_page']);
    }

    public function test_sorting_by_newest_and_by_title(): void
    {
        // Newest year first, portal order within a year, records without a year last.
        $this->assertSame(['r1', 'r2', 'r3', 'r4', 'r5', 'r6'], $this->keys($this->search([])));
        $this->assertSame('terbaru', $this->search([])['meta']['sort']);
        $this->assertSame(['r2', 'r4', 'r3', 'r1', 'r6', 'r5'], $this->keys($this->search(['sort' => 'judul'])));
    }

    public function test_items_carry_normalized_advisors_and_the_program_key(): void
    {
        $item = $this->search(['q' => 'persediaan'])['items'][0];

        $this->assertSame([
            ['name' => 'Dosen Contoh Alfa, S.Kom., M.Kom.', 'key' => 'dosen contoh alfa'],
            ['name' => 'Dr. Dosen Contoh Beta, M.T.', 'key' => 'dosen contoh beta'],
        ], $item['advisors']);
        $this->assertSame('sistem informasi', $item['program_key']);
        $this->assertSame('files/skripsi/kolokium/r1.pdf', $item['file_path']);
    }

    public function test_similar_titles_rank_the_closest_first(): void
    {
        $similar = (new ThesisRepositorySearch)->similar($this->records(), 'Sistem Informasi Persediaan Barang Berbasis Web');

        $this->assertSame('r1', $similar[0]['record_key']);
        $this->assertGreaterThan(0.5, $similar[0]['similarity']);
        $this->assertSame((int) round($similar[0]['similarity'] * 100), $similar[0]['similarity_percent']);
        $this->assertLessThanOrEqual(ThesisRepositorySearch::SIMILAR_LIMIT, count($similar));

        for ($index = 1; $index < count($similar); $index++) {
            $this->assertLessThanOrEqual($similar[$index - 1]['similarity'], $similar[$index]['similarity']);
        }
    }

    public function test_an_identical_title_is_fully_similar_and_stopwords_alone_match_nothing(): void
    {
        $search = new ThesisRepositorySearch;

        $this->assertSame(1.0, $search->similar($this->records(), 'Rancang Bangun Aplikasi Monitoring Kehadiran Berbasis Android')[0]['similarity']);
        $this->assertSame([], $search->similar($this->records(), '   '));
    }

    public function test_keywords_skip_stopwords_and_short_words(): void
    {
        $keywords = array_column((new ThesisRepositorySearch)->keywords($this->records(), 3), 'count', 'word');

        $this->assertArrayHasKey('sistem', $keywords);
        $this->assertArrayNotHasKey('pada', $keywords);
        $this->assertArrayNotHasKey('web', $keywords);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{items: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    private function search(array $filters): array
    {
        return (new ThesisRepositorySearch)->search($this->records(), $filters);
    }

    /**
     * @param  array{items: list<array<string, mixed>>}  $result
     * @return list<string>
     */
    private function keys(array $result): array
    {
        return array_column($result['items'], 'record_key');
    }

    /**
     * Synthetic repository records (K14), in portal order
     *
     * @return list<array<string, mixed>>
     */
    private function records(): array
    {
        return [
            $this->record('r1', 'SISTEM INFORMASI PERSEDIAAN BARANG BERBASIS WEB PADA TOKO CONTOH', 'SISTEM INFORMASI', '2023/2024', 'Dosen Contoh Alfa, S.Kom., M.Kom.', 'Dr. Dosen Contoh Beta, M.T.'),
            $this->record('r2', 'Analisis Algoritma Alfa-Beta Pada Permainan Catur', 'TEKNIK INFORMATIKA', '2023/2024', 'Dosen Contoh Gamma, M.Kom.'),
            $this->record('r3', 'Rancang Bangun Aplikasi Monitoring Kehadiran Berbasis Android', 'SISTEM INFORMASI', '2022/2023', 'Dosen Contoh Alfa, S.Kom, M.Kom'),
            $this->record('r4', 'Aplikasi Koperasi Elektronik Untuk UMKM', 'MANAJEMEN INFORMATIKA', '2022/2023', 'Dosen Contoh Delta, S.E.'),
            $this->record('r5', 'Sistem Penggajian Pada CV Contoh', 'KOMPUTERISASI AKUNTANSI', '2019/2020', 'Dosen Contoh Zeta, M.Ak.'),
            $this->record('r6', 'Sistem Pakar Diagnosa Hama Padi', 'Sistem Informasi', null, null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function record(string $key, string $title, string $program, ?string $academicYear, ?string $advisor1, ?string $advisor2 = null): array
    {
        return [
            'record_key' => $key,
            'no' => (int) preg_replace('/\D/', '', $key),
            'academic_year' => $academicYear,
            'program' => $program,
            'nim' => '1900000'.preg_replace('/\D/', '', $key),
            'author' => 'MAHASISWA CONTOH '.strtoupper($key),
            'title' => $title,
            'advisor_1' => $advisor1,
            'advisor_2' => $advisor2,
            'file_path' => "files/skripsi/kolokium/{$key}.pdf",
            'page' => 1,
        ];
    }
}

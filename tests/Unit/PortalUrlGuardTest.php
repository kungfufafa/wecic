<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\PortalScrapingException;
use App\Services\PortalUrlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PortalUrlGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['portal.base_url' => 'https://my.cic.ac.id']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function allowedCampusFiles(): array
    {
        return [
            'absolute soal' => [
                'https://my.cic.ac.id/portal/files/soal/9197-UTS-tgs-591.pdf',
                'https://my.cic.ac.id/portal/files/soal/9197-UTS-tgs-591.pdf',
            ],
            'relative soal from exam page' => [
                'portal/files/soal/9197-UTS-tgs-591.pdf',
                'https://my.cic.ac.id/portal/files/soal/9197-UTS-tgs-591.pdf',
            ],
            'parent-relative modul' => [
                '../files/modul/ae614c557843b1df326cb29c57225459-3-mod-fa8.pdf',
                'https://my.cic.ac.id/portal/files/modul/ae614c557843b1df326cb29c57225459-3-mod-fa8.pdf',
            ],
            'parent-relative latihan' => [
                '../files/latihan/task.pdf',
                'https://my.cic.ac.id/portal/files/latihan/task.pdf',
            ],
            'rps with trailing space' => [
                '../files/sap/SIE053004-rps47a.pdf ',
                'https://my.cic.ac.id/portal/files/sap/SIE053004-rps47a.pdf',
            ],
            'submitted assignment' => [
                '../files/tugasmhs/8987-3-20251030030-a6b.pdf',
                'https://my.cic.ac.id/portal/files/tugasmhs/8987-3-20251030030-a6b.pdf',
            ],
            'skkm' => [
                'files/skkm/sertifikat.jpg',
                'https://my.cic.ac.id/portal/files/skkm/sertifikat.jpg',
            ],
            'student photo' => [
                '../files/fotostudent/nopic.gif',
                'https://my.cic.ac.id/portal/files/fotostudent/nopic.gif',
            ],
            'kolokium with spaces' => [
                '../files/skripsi/kolokium/TUGAS AKHIR-SIDANG-20251030030-KOLOKIUM-ab12c.pdf',
                'https://my.cic.ac.id/portal/files/skripsi/kolokium/TUGAS%20AKHIR-SIDANG-20251030030-KOLOKIUM-ab12c.pdf',
            ],
            'absolute kolokium with encoded spaces' => [
                'https://my.cic.ac.id/portal/files/skripsi/kolokium/TUGAS%20AKHIR-SIDANG-20251030030-KOLOKIUM-ab12c.pdf',
                'https://my.cic.ac.id/portal/files/skripsi/kolokium/TUGAS%20AKHIR-SIDANG-20251030030-KOLOKIUM-ab12c.pdf',
            ],
            'root SOP' => [
                'https://my.cic.ac.id/portal/files/SOP_Mekanisme_proses_penyelesaian_tugas_akhir.pdf',
                'https://my.cic.ac.id/portal/files/SOP_Mekanisme_proses_penyelesaian_tugas_akhir.pdf',
            ],
            'root Panduan' => [
                '../files/Panduan_Her-registrasi-KRS_dan_Perwalian.pdf',
                'https://my.cic.ac.id/portal/files/Panduan_Her-registrasi-KRS_dan_Perwalian.pdf',
            ],
            'fragment is dropped' => [
                'portal/files/soal/9197-UTS-tgs-591.pdf#page=2',
                'https://my.cic.ac.id/portal/files/soal/9197-UTS-tgs-591.pdf',
            ],
            'dot-segments that stay inside files' => [
                'files/modul/../soal/./9197-UTS-tgs-591.pdf',
                'https://my.cic.ac.id/portal/files/soal/9197-UTS-tgs-591.pdf',
            ],
        ];
    }

    #[DataProvider('allowedCampusFiles')]
    public function test_campus_file_url_serves_static_files_under_portal_files(string $reference, string $expectedUrl): void
    {
        $this->assertSame($expectedUrl, PortalUrlGuard::campusFileUrl($reference));
        $this->assertSame($expectedUrl, PortalUrlGuard::assertCampusFileUrl($reference));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rejectedReferences(): array
    {
        return [
            'absolute jobdel' => ['https://my.cic.ac.id/portal/sipandai/?mod=sipandai&act=jobdel&id=30095&idx=29833&pertemuan=6'],
            'absolute jobdelujian' => ['https://my.cic.ac.id/portal/sipandai/?mod=sipandai&act=jobdelujian&id=9184'],
            'absolute kesesuaian' => ['https://my.cic.ac.id/portal/?mod=student&act=kesesuaian&id=1&jawab=1'],
            'absolute logout' => ['https://my.cic.ac.id/portal/?mod=logout'],
            'absolute file with query' => ['https://my.cic.ac.id/portal/files/soal/a.pdf?mod=logout'],
            'relative file with empty query' => ['files/soal/a.pdf?'],
            'relative traversal to sipandai' => ['files/../sipandai/?mod=sipandai&act=jobdel&id=1'],
            'relative traversal without query' => ['files/../sipandai/index.php'],
            'percent-encoded traversal' => ['files/%2e%2e/sipandai/index.php'],
            'mixed-case percent-encoded traversal' => ['files/soal/%2E%2E/%2e%2E/sipandai/'],
            'encoded slash traversal' => ['files/soal/..%2f..%2fsipandai%2findex.php'],
            'double-encoded traversal' => ['files/%252e%252e/sipandai/index.php'],
            'backslash traversal' => ['files\\..\\sipandai\\index.php'],
            'encoded backslash traversal' => ['files/soal/..%5c..%5csipandai%5cindex.php'],
            'absolute traversal out of files' => ['https://my.cic.ac.id/portal/files/../sipandai/index.php'],
            'absolute encoded traversal out of files' => ['https://my.cic.ac.id/portal/files/%2e%2e/sipandai/index.php'],
            'absolute url outside portal files' => ['https://my.cic.ac.id/files/soal/a.pdf'],
            'external host' => ['https://evil.example/portal/files/soal/a.pdf'],
            'userinfo host confusion' => ['https://my.cic.ac.id@evil.example/portal/files/soal/a.pdf'],
            'explicit port' => ['https://my.cic.ac.id:8443/portal/files/soal/a.pdf'],
            'plain http' => ['http://my.cic.ac.id/portal/files/soal/a.pdf'],
            'protocol-relative external host' => ['//evil.example/portal/files/soal/a.pdf'],
            'javascript scheme' => ['javascript:alert(1)'],
            'unknown directory' => ['../files/config/database.pdf'],
            'portal php endpoint' => ['../sipandai/index.php'],
            'directory listing' => ['files/soal/'],
            'files root listing' => ['files/'],
            'root file outside SOP and Panduan' => ['files/backup.pdf'],
            'server script in allowed directory' => ['files/tugasmhs/shell.php'],
            'server script used as path info' => ['files/tugasmhs/shell.php/a.pdf'],
            'dots-only segment' => ['files/soal/.../a.pdf'],
            'null byte' => ["files/soal/a.pdf\0.php"],
            'encoded null byte' => ['files/soal/a.pdf%00.php'],
            'empty' => ['   '],
        ];
    }

    #[DataProvider('rejectedReferences')]
    public function test_campus_file_url_rejects_references_outside_static_files(string $reference): void
    {
        $this->assertNull(PortalUrlGuard::campusFileUrl($reference));
    }

    public function test_assert_campus_file_url_throws_for_rejected_reference(): void
    {
        $this->expectException(PortalScrapingException::class);

        PortalUrlGuard::assertCampusFileUrl('https://my.cic.ac.id/portal/?mod=logout');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Extractors\ClassesExtractor;
use App\Services\PortalSessionManager;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class ClassesExtractorContentTypeTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_login_form_is_detected_even_when_the_page_mentions_class_words(): void
    {
        $html = <<<'HTML'
            <html><head><title>Portal Mahasiswa - Login</title></head><body>
            <p>Selamat datang, dosen dan mahasiswa CIC.</p>
            <form method="post" action="/login">
                <input type="text" name="username">
                <input type="password" name="password">
                <button type="submit">Masuk</button>
            </form>
            </body></html>
        HTML;

        $this->assertSame('login_page', $this->detectContentType($html));
    }

    public function test_pages_without_a_login_form_are_still_classified_by_their_words(): void
    {
        $html = <<<'HTML'
            <html><head><title>Detail Kelas</title></head><body>
            <p>Dosen pengampu: Budi Santoso.</p>
            <p>Mata kuliah: Basis Data.</p>
            <script>if (xhr.status >= 500) { showToast("password salah"); }</script>
            </body></html>
        HTML;

        $this->assertSame('class_info', $this->detectContentType($html));
    }

    public function test_error_title_is_detected_when_no_content_marker_matches(): void
    {
        $html = <<<'HTML'
            <html><head><title>404 Not Found</title></head><body>
            <p>Halaman tidak ditemukan.</p>
            </body></html>
        HTML;

        $this->assertSame('error_page', $this->detectContentType($html));
    }

    public function test_the_dashboardmahasiswa_breadcrumb_does_not_make_a_class_page_a_students_list(): void
    {
        $html = (string) file_get_contents(base_path('tests/Fixtures/portal/sipandai_course_detail.html'));

        $this->assertStringContainsString('act=dashboardmahasiswa', $html);
        $this->assertSame('class_info', $this->detectContentType($html));
    }

    public function test_an_error_page_with_a_table_is_still_an_error_page(): void
    {
        $html = <<<'HTML'
            <html><head><title>404 Not Found</title></head><body>
            <a href="?mod=sipandai&act=dashboardmahasiswa">Beranda</a>
            <table><tr><td>Halaman tidak ditemukan.</td></tr></table>
            </body></html>
        HTML;

        $this->assertSame('error_page', $this->detectContentType($html));
    }

    public function test_binary_pdf_payload_is_detected_as_file_download(): void
    {
        $this->assertSame('file_download', $this->detectContentType('%PDF-1.7 ...binary payload...'));
    }

    public function test_unrecognised_content_is_unknown(): void
    {
        $this->assertSame('unknown', $this->detectContentType('<html><body>ok</body></html>'));
    }

    private function detectContentType(string $html): string
    {
        $extractor = new ClassesExtractor(Mockery::mock(PortalSessionManager::class));

        $method = new ReflectionMethod(ClassesExtractor::class, 'detectContentType');

        return $method->invoke($extractor, $html);
    }
}

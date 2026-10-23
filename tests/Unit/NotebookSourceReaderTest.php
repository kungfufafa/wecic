<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\NotebookAiException;
use App\Exceptions\NotebookSourceException;
use App\Services\NotebookSourceReader;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use ZipArchive;

class NotebookSourceReaderTest extends TestCase
{
    private const GEMINI_URL = 'https://generativelanguage.googleapis.com/v1beta/models/*';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.gemini.key' => 'test-gemini-key',
            'services.gemini.model' => 'gemini-test-flash',
            'services.gemini.fallback_model' => null,
            'services.gemini.timeout' => 60,
            'services.gemini.ingest_budget' => 240,
            'services.gemini.retry_delay_ms' => 0,
        ]);
    }

    public function test_a_word_file_that_unpacks_beyond_the_limit_is_refused_before_it_is_read(): void
    {
        config(['notebook.uploads.max_unzipped_bytes' => 4096]);
        $reader = app(NotebookSourceReader::class);

        $small = $this->zip(['word/document.xml' => '<w:document><w:body><w:p><w:r><w:t>Basis data</w:t></w:r></w:p></w:body></w:document>']);
        $this->assertSame('Basis data', $reader->read($small, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'bab.docx')['pages'][0]['text']);

        $this->expectException(NotebookSourceException::class);
        $this->expectExceptionMessage('Isi dokumen ini terlalu besar untuk dibaca Notebook.');

        // Compresses to a few hundred bytes; unpacks to 1 MB.
        $reader->read($this->zip(['word/document.xml' => str_repeat('<w:p/>', 200_000)]), 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'bom.docx');
    }

    public function test_slides_are_measured_together_before_any_is_read(): void
    {
        config(['notebook.uploads.max_unzipped_bytes' => 4096]);
        $slides = [];

        foreach (range(1, 4) as $number) {
            $slides["ppt/slides/slide{$number}.xml"] = '<p:sld>'.str_repeat('<a:p/>', 300).'</p:sld>';
        }

        $this->expectException(NotebookSourceException::class);
        $this->expectExceptionMessage('Isi dokumen ini terlalu besar untuk dibaca Notebook.');

        app(NotebookSourceReader::class)->read($this->zip($slides), 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'slide.pptx');
    }

    public function test_a_pdf_transcription_may_take_the_whole_ingest_budget(): void
    {
        $timeouts = [];
        Http::fake([self::GEMINI_URL => function (Request $request, array $options) use (&$timeouts) {
            $timeouts[] = $options['timeout'] ?? null;

            return Http::response(['candidates' => [['content' => ['parts' => [['text' => "[[HALAMAN 1]]\nModul."]]], 'finishReason' => 'STOP']]]);
        }]);

        $read = app(NotebookSourceReader::class)->read('%PDF-1.4 modul', 'application/pdf', 'modul.pdf');

        $this->assertSame([['page' => 1, 'text' => 'Modul.']], $read['pages']);
        $this->assertSame([240], $timeouts);
    }

    public function test_a_pdf_too_long_to_transcribe_in_time_says_so(): void
    {
        Http::fake([self::GEMINI_URL => Factory::failedConnection('cURL error 28: Operation timed out after 240000 milliseconds')]);

        $this->expectException(NotebookSourceException::class);
        $this->expectExceptionMessage('Berkas ini terlalu panjang untuk dibaca sekaligus.');

        app(NotebookSourceReader::class)->read('%PDF-1.4 modul', 'application/pdf', 'modul.pdf');
    }

    public function test_a_pdf_that_cannot_be_reached_in_time_is_not_blamed_on_its_length(): void
    {
        Http::fake([self::GEMINI_URL => Factory::failedConnection('cURL error 28: Connection timed out after 10001 milliseconds')]);

        $this->expectException(NotebookAiException::class);
        $this->expectExceptionMessage(NotebookAiException::unavailable()->getMessage());

        app(NotebookSourceReader::class)->read('%PDF-1.4 modul', 'application/pdf', 'modul.pdf');
    }

    public function test_a_wrong_key_is_not_blamed_on_the_file(): void
    {
        Http::fake([self::GEMINI_URL => Http::response(['error' => [
            'code' => 400,
            'message' => 'API key not valid. Please pass a valid API key.',
            'status' => 'INVALID_ARGUMENT',
            'details' => [['@type' => 'type.googleapis.com/google.rpc.ErrorInfo', 'reason' => 'API_KEY_INVALID']],
        ]], 400)]);

        $this->expectException(NotebookAiException::class);
        $this->expectExceptionMessage(NotebookAiException::misconfigured(NotebookAiException::CAUSE_KEY)->getMessage());

        app(NotebookSourceReader::class)->read('%PDF-1.4 modul', 'application/pdf', 'modul.pdf');
    }

    public function test_a_transcription_stopped_for_recitation_is_marked_partial(): void
    {
        Http::fake([self::GEMINI_URL => Http::response(['candidates' => [['content' => ['parts' => [['text' => "[[HALAMAN 1]]\nBab 1."]]], 'finishReason' => 'RECITATION']]])]);

        $this->assertTrue(app(NotebookSourceReader::class)->read('%PDF-1.4 buku', 'application/pdf', 'buku.pdf')['truncated']);
    }

    public function test_a_transcription_cut_at_the_largest_output_limit_is_marked_partial(): void
    {
        config(['notebook.thinking_budget' => null]);
        Http::fake([self::GEMINI_URL => Http::response(['candidates' => [['content' => ['parts' => [['text' => "[[HALAMAN 1]]\nBab 1."]]], 'finishReason' => 'MAX_TOKENS']]])]);

        $read = app(NotebookSourceReader::class)->read('%PDF-1.4 buku', 'application/pdf', 'buku.pdf');

        $this->assertTrue($read['truncated']);
        $this->assertSame([['page' => 1, 'text' => 'Bab 1.']], $read['pages']);
        // Already at the largest limit: no second request.
        Http::assertSentCount(1);
    }

    public function test_a_transcription_cut_before_any_text_asks_for_a_shorter_file(): void
    {
        Http::fake([self::GEMINI_URL => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'Merencanakan…', 'thought' => true]]], 'finishReason' => 'MAX_TOKENS']]])]);

        $this->expectException(NotebookSourceException::class);
        $this->expectExceptionMessage('Berkas ini terlalu panjang untuk dibaca sekaligus.');

        app(NotebookSourceReader::class)->read('%PDF-1.4 buku', 'application/pdf', 'buku.pdf');
    }

    /**
     * @param  array<string, string>  $entries
     */
    private function zip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'notebook-reader-test-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);

        foreach ($entries as $entry => $contents) {
            $zip->addFromString($entry, $contents);
        }

        $zip->close();
        $contents = (string) file_get_contents($path);
        @unlink($path);

        return $contents;
    }
}

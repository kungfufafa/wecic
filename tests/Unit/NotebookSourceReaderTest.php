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
        $this->expectExceptionMessage(NotebookAiException::misconfigured(NotebookAiException::CAUSE_KEY, 'Google Gemini')->getMessage());

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

    public function test_a_born_digital_pdf_is_read_from_its_own_text_layer_without_the_model(): void
    {
        Http::fake();
        $reader = app(NotebookSourceReader::class);
        $pdf = $this->fixture('born-digital.pdf');

        $read = $reader->read($pdf, 'application/pdf', 'modul.pdf');

        $this->assertFalse($read['truncated']);
        $this->assertSame([1, 2], array_column($read['pages'], 'page'));
        $this->assertStringContainsString('Normalisasi mengurangi redundansi data dalam tabel.', $read['pages'][0]['text']);
        $this->assertStringContainsString('Bentuk normal ketiga (3NF) menghapus ketergantungan transitif.', $read['pages'][1]['text']);
        $this->assertFalse($reader->needsModel('application/pdf', 'modul.pdf', $pdf));
        // Without the contents, a PDF may still need the model.
        $this->assertTrue($reader->needsModel('application/pdf', 'modul.pdf'));
        Http::assertNothingSent();
    }

    public function test_a_scanned_pdf_without_a_text_layer_is_transcribed_by_the_model(): void
    {
        Http::fake([self::GEMINI_URL => Http::response(['candidates' => [['content' => ['parts' => [['text' => "[[HALAMAN 1]]\nHasil pindaian."]]], 'finishReason' => 'STOP']]])]);
        $reader = app(NotebookSourceReader::class);
        $pdf = $this->fixture('scanned.pdf');

        $this->assertTrue($reader->needsModel('application/pdf', 'pindaian.pdf', $pdf));
        $this->assertSame([['page' => 1, 'text' => 'Hasil pindaian.']], $reader->read($pdf, 'application/pdf', 'pindaian.pdf')['pages']);
        Http::assertSent(fn (Request $request): bool => ($request['contents'][0]['parts'][0]['inlineData']['data'] ?? null) === base64_encode($pdf));
    }

    public function test_with_the_text_layer_off_a_model_that_reads_pdfs_transcribes_every_pdf(): void
    {
        config(['notebook.uploads.pdf_text_layer' => false]);
        Http::fake([self::GEMINI_URL => Http::response(['candidates' => [['content' => ['parts' => [['text' => "[[HALAMAN 1]]\nDari model."]]], 'finishReason' => 'STOP']]])]);
        $pdf = $this->fixture('born-digital.pdf');

        $this->assertTrue(app(NotebookSourceReader::class)->needsModel('application/pdf', 'modul.pdf', $pdf));
        $this->assertSame('Dari model.', app(NotebookSourceReader::class)->read($pdf, 'application/pdf', 'modul.pdf')['pages'][0]['text']);
        Http::assertSentCount(1);
    }

    public function test_a_model_without_pdf_or_image_input_still_reads_born_digital_pdfs_and_refuses_the_rest_without_a_call(): void
    {
        $this->useLocalOpenAiCompatibleModel();
        config(['notebook.uploads.pdf_text_layer' => false]);
        Http::fake();
        $reader = app(NotebookSourceReader::class);
        $bornDigital = $this->fixture('born-digital.pdf');
        $scanned = $this->fixture('scanned.pdf');

        // The text layer is read even with the setting off: the model could not read the PDF at all.
        $this->assertNull($reader->unreadable($bornDigital, 'application/pdf', 'modul.pdf'));
        $this->assertCount(2, $reader->read($bornDigital, 'application/pdf', 'modul.pdf')['pages']);
        $this->assertSame(NotebookAiException::UNSUPPORTED_INPUT_MESSAGE, $reader->unreadable($scanned, 'application/pdf', 'pindaian.pdf'));
        $this->assertSame(NotebookAiException::UNSUPPORTED_INPUT_MESSAGE, $reader->unreadable('png', 'image/png', 'foto.png'));
        $this->assertNull($reader->unreadable('teks', 'text/plain', 'catatan.txt'));

        foreach ([[$scanned, 'application/pdf', 'pindaian.pdf'], ['png', 'image/png', 'foto.png']] as [$contents, $mime, $filename]) {
            try {
                $reader->read($contents, $mime, $filename);
                $this->fail('The file was expected to be refused.');
            } catch (NotebookSourceException $exception) {
                $this->assertSame('Model AI yang dipakai server tidak bisa membaca PDF hasil pindaian atau gambar; tempel teksnya atau unggah DOCX/PPTX.', $exception->getMessage());
            }
        }

        Http::assertNothingSent();
    }

    public function test_an_ingest_provider_keeps_transcription_on_gemini_while_another_provider_answers(): void
    {
        $this->useLocalOpenAiCompatibleModel();
        config(['notebook.ai.ingest_provider' => 'gemini']);
        Http::fake([self::GEMINI_URL => Http::response(['candidates' => [['content' => ['parts' => [['text' => "[[HALAMAN 1]]\nLewat Gemini."]]], 'finishReason' => 'STOP']]])]);
        $reader = app(NotebookSourceReader::class);

        $this->assertNull($reader->unreadable($this->fixture('scanned.pdf'), 'application/pdf', 'pindaian.pdf'));
        $this->assertSame('Lewat Gemini.', $reader->read($this->fixture('scanned.pdf'), 'application/pdf', 'pindaian.pdf')['pages'][0]['text']);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://generativelanguage.googleapis.com/'));
    }

    public function test_a_text_layer_that_lost_its_word_spaces_is_transcribed_by_the_model(): void
    {
        Http::fake([self::GEMINI_URL => Http::response(['candidates' => [['content' => ['parts' => [['text' => "[[HALAMAN 1]]\nMata kuliah ini membekali mahasiswa."]]], 'finishReason' => 'STOP']]])]);
        $reader = app(NotebookSourceReader::class);
        $pdf = $this->fixture('lost-spacing.pdf');

        $this->assertTrue($reader->needsModel('application/pdf', 'modul.pdf', $pdf));
        $this->assertSame('Mata kuliah ini membekali mahasiswa.', $reader->read($pdf, 'application/pdf', 'modul.pdf')['pages'][0]['text']);
        Http::assertSentCount(1);
    }

    public function test_a_long_word_now_and_then_keeps_the_text_layer(): void
    {
        $text = str_repeat('Mahasiswa wajib mempertanggungjawabkan tugas dan menghindari ketidakbertanggungjawaban akademik. ', 20);

        $method = new \ReflectionMethod(NotebookSourceReader::class, 'lostWordSpaces');

        $this->assertFalse($method->invoke(app(NotebookSourceReader::class), $text));
        $this->assertTrue($method->invoke(app(NotebookSourceReader::class), $text.str_repeat('membekalimahasiswadengankonseppemrosesan ', 20)));
    }

    public function test_an_image_over_the_models_image_limit_is_refused_with_its_size(): void
    {
        config([
            'notebook.ai.provider' => 'anthropic',
            'services.anthropic.key' => 'test-anthropic-key',
            'services.anthropic.model' => 'claude-opus-5-5',
        ]);
        Http::fake();
        $reader = app(NotebookSourceReader::class);
        $photo = str_repeat('x', 4 * 1024 * 1024);
        $message = 'Gambar ini terlalu besar untuk dibaca model AI (maks. 3,75 MB). Perkecil atau kompres gambarnya, lalu unggah lagi.';

        $this->assertSame($message, $reader->unreadable($photo, 'image/jpeg', 'foto.jpg'));
        $this->assertNull($reader->unreadable(str_repeat('x', 3 * 1024 * 1024), 'image/jpeg', 'foto.jpg'));

        try {
            $reader->read($photo, 'image/jpeg', 'foto.jpg');
            $this->fail('The image was expected to be refused.');
        } catch (NotebookSourceException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_a_damaged_pdf_falls_back_to_the_model(): void
    {
        Http::fake([self::GEMINI_URL => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'Isi.']]], 'finishReason' => 'STOP']]])]);
        $reader = app(NotebookSourceReader::class);

        $this->assertTrue($reader->needsModel('application/pdf', 'rusak.pdf', '%PDF-1.4 rusak'));
        $this->assertSame('Isi.', $reader->read('%PDF-1.4 rusak', 'application/pdf', 'rusak.pdf')['pages'][0]['text']);
    }

    private function useLocalOpenAiCompatibleModel(): void
    {
        config([
            'notebook.ai.provider' => 'openai',
            'services.openai_compatible.base_url' => 'http://llm.test/v1',
            'services.openai_compatible.model' => 'llama-test',
            'services.openai_compatible.pdf_input' => false,
            'services.openai_compatible.image_input' => false,
        ]);
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/notebook/'.$name));
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

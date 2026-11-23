<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\NotebookAiException;
use App\Exceptions\NotebookSourceException;
use App\Services\Ai\NotebookAiClient;
use App\Support\Ai\AiRequest;
use App\Support\Ai\AiResult;
use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Config as PdfParserConfig;
use Smalot\PdfParser\Parser as PdfParser;
use ZipArchive;

/**
 * Turns a source file into text, page by page where the format has pages.
 *
 * Word and PowerPoint files and plain text are read locally, and so is a
 * PDF with a text layer of its own (a born-digital PDF;
 * `notebook.uploads.pdf_text_layer`). A scanned PDF and an image have no
 * text to read here, so the model transcribes them once
 * (`notebook.ai.ingest_provider`, else `notebook.ai.provider`); the text is
 * kept and every later question uses only that text. A model that cannot
 * read PDFs or images (its capabilities) leaves those files unreadable,
 * with a message that says what to do instead (unreadable()).
 *
 * @phpstan-type SourcePage array{page: int|null, text: string}
 */
class NotebookSourceReader
{
    private const PAGE_MARKER = '/\[\[\s*HALAMAN\s+(\d{1,4})\s*\]\]/iu';

    private const TRANSCRIBE_PROMPT = <<<'PROMPT'
        Salin seluruh isi dokumen ini menjadi teks polos dalam bahasa aslinya, tanpa merangkum dan tanpa menambah komentar.
        Tulis penanda [[HALAMAN n]] pada baris tersendiri di awal setiap halaman (n dimulai dari 1).
        Pertahankan judul, daftar, dan urutan isi. Ubah tabel menjadi baris teks dengan pemisah " | ".
        Gambar, diagram, atau grafik dijelaskan singkat dalam tanda kurung siku, misalnya [Diagram: alur normalisasi 1NF ke 3NF].
        PROMPT;

    /**
     * Inline data is limited by the request size; a model may take less
     * (its capabilities' maxBytesFor()).
     */
    private const MAX_TRANSCRIBE_BYTES = 19 * 1024 * 1024;

    /**
     * A PDF page counts as having text with this many letters or digits;
     * a scanned page has none, or only a page number.
     */
    private const MIN_PAGE_TEXT_CHARS = 20;

    /**
     * Letters or digits a whole PDF's text layer needs to be used.
     */
    private const MIN_DOCUMENT_TEXT_CHARS = 100;

    /**
     * The share of a PDF's pages that need text for its text layer to be
     * used instead of a transcription.
     */
    private const MIN_TEXT_PAGE_SHARE = 0.5;

    /**
     * The largest share of unreadable characters (replacement characters,
     * private-use glyphs, control codes) a text layer may have: more means
     * fonts without a character map, whose text comes out as garbage.
     */
    private const MAX_GARBLED_SHARE = 0.1;

    /**
     * Letters in a row that no word reaches: an Indonesian or English word
     * stays under this (`ketidakbertanggungjawaban` is 25), words run
     * together do not.
     */
    private const RUN_TOGETHER_LETTERS = 26;

    /**
     * The largest share of words that may run together: more means a layer
     * that lost its word spaces (words placed one by one without a space
     * between them), whose words no question would match.
     */
    private const MAX_RUN_TOGETHER_SHARE = 0.05;

    /**
     * The text layer of the last PDF looked at, by its SHA-1, so checking a
     * file and reading it parse it once.
     *
     * @var array<string, list<SourcePage>|null>
     */
    private array $textLayers = [];

    public function __construct(private readonly NotebookAiClient $ai) {}

    /**
     * @return array{pages: list<SourcePage>, truncated: bool}
     */
    public function read(string $contents, string $mime, string $filename = ''): array
    {
        $type = $this->type($mime, $filename);

        return match ($type) {
            'docx' => ['pages' => $this->readDocx($contents), 'truncated' => false],
            'pptx' => ['pages' => $this->readPptx($contents), 'truncated' => false],
            'text' => ['pages' => [['page' => null, 'text' => $this->normalize($contents)]], 'truncated' => false],
            'pdf' => $this->readPdf($contents),
            'image' => $this->transcribe($contents, $mime),
            default => throw new NotebookSourceException('Format berkas ini belum bisa dibaca Notebook.'),
        };
    }

    /**
     * Whether reading this file needs the model: an image, or a PDF
     * without a text layer to read locally. Without the contents, every
     * PDF may need it.
     */
    public function needsModel(string $mime, string $filename = '', ?string $contents = null): bool
    {
        return match ($this->type($mime, $filename)) {
            'image' => true,
            'pdf' => $contents === null || $this->localPdfPages($contents) === null,
            default => false,
        };
    }

    /**
     * Why this file cannot be read with the model the server uses for
     * reading, or null when it can: an image, or a PDF without a text
     * layer, for a model that does not read them (its capabilities).
     */
    public function unreadable(string $contents, string $mime, string $filename = ''): ?string
    {
        $capabilities = $this->ai->capabilities();
        $type = $this->type($mime, $filename);

        $readable = match ($type) {
            'image' => $capabilities->imageInput,
            'pdf' => $capabilities->pdfInput || $this->localPdfPages($contents) !== null,
            default => true,
        };

        if (! $readable) {
            return NotebookAiException::UNSUPPORTED_INPUT_MESSAGE;
        }

        return $type === 'image' ? $this->tooLarge($contents, $mime) : null;
    }

    /**
     * Why this file is too large for the model to read, or null: the
     * request's inline limit, or the model's own (smaller for images on
     * some models).
     */
    private function tooLarge(string $contents, string $mime): ?string
    {
        $maxBytes = min(self::MAX_TRANSCRIBE_BYTES, $this->ai->capabilities()->maxBytesFor($mime));

        if (strlen($contents) <= $maxBytes) {
            return null;
        }

        $limit = str_replace('.', ',', rtrim(rtrim(number_format($maxBytes / (1024 * 1024), 2, '.', ''), '0'), '.'));

        return str_starts_with(strtolower($mime), 'image/')
            ? "Gambar ini terlalu besar untuk dibaca model AI (maks. {$limit} MB). Perkecil atau kompres gambarnya, lalu unggah lagi."
            : "Berkas terlalu besar untuk dibaca Notebook (maks. {$limit} MB).";
    }

    /**
     * Whether the model that reads files can be used now (configured, and
     * its settings not rejected lately).
     */
    public function modelIsUsable(): bool
    {
        return $this->ai->isUsable();
    }

    /**
     * The provider that reads files, as students read it (e.g. `Google Gemini`).
     */
    public function modelLabel(): string
    {
        return $this->ai->label();
    }

    public function type(string $mime, string $filename = ''): ?string
    {
        $mime = strtolower(trim(explode(';', $mime)[0]));
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match (true) {
            $mime === 'application/pdf' || $extension === 'pdf' => 'pdf',
            $mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' || $extension === 'docx' => 'docx',
            $mime === 'application/vnd.openxmlformats-officedocument.presentationml.presentation' || $extension === 'pptx' => 'pptx',
            in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true) => 'image',
            str_starts_with($mime, 'text/') || in_array($extension, ['txt', 'md', 'csv'], true) => 'text',
            default => null,
        };
    }

    /**
     * @return list<SourcePage>
     */
    private function readDocx(string $contents): array
    {
        $xml = $this->zipEntry($contents, 'word/document.xml');
        $paragraphs = [];

        if (preg_match_all('~<w:p[ >].*?</w:p>~s', $xml, $matches) > 0) {
            foreach ($matches[0] as $paragraph) {
                $text = $this->xmlText($paragraph, 'w:t');

                if ($text !== '') {
                    $paragraphs[] = $text;
                }
            }
        }

        return [['page' => null, 'text' => $this->normalize(implode("\n", $paragraphs))]];
    }

    /**
     * @return list<SourcePage>
     */
    private function readPptx(string $contents): array
    {
        $zip = $this->openZip($contents);
        $slides = [];

        try {
            $entries = [];
            $unzippedBytes = 0;

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = (string) $zip->getNameIndex($index);

                if (preg_match('~^ppt/slides/slide(\d+)\.xml$~', $name, $matches) === 1) {
                    $entries[(int) $matches[1]] = $index;
                    $unzippedBytes += $this->unzippedSize($zip->statIndex($index));
                }
            }

            $this->guardUnzippedSize($unzippedBytes);

            foreach ($entries as $number => $index) {
                $slides[$number] = (string) $zip->getFromIndex($index);
            }
        } finally {
            $zip->close();
        }

        ksort($slides);
        $pages = [];

        foreach ($slides as $number => $xml) {
            $lines = [];

            if (preg_match_all('~<a:p(?:\s[^>]*)?>.*?</a:p>~s', $xml, $matches) > 0) {
                foreach ($matches[0] as $paragraph) {
                    $text = $this->xmlText($paragraph, 'a:t');

                    if ($text !== '') {
                        $lines[] = $text;
                    }
                }
            }

            if ($lines !== []) {
                $pages[] = ['page' => $number, 'text' => $this->normalize(implode("\n", $lines))];
            }
        }

        return $pages;
    }

    /**
     * A PDF's own text layer when it has one worth reading, else the
     * model's transcription.
     *
     * @return array{pages: list<SourcePage>, truncated: bool}
     */
    private function readPdf(string $contents): array
    {
        $pages = $this->localPdfPages($contents);

        if ($pages !== null) {
            return ['pages' => $pages, 'truncated' => false];
        }

        return $this->transcribe($contents, 'application/pdf');
    }

    /**
     * The PDF's text layer, page by page, when it is read locally: the
     * setting allows it (or the model cannot read PDFs at all) and the
     * layer is worth reading (textLayer()). Null otherwise.
     *
     * @return list<SourcePage>|null
     */
    private function localPdfPages(string $contents): ?array
    {
        if (! (bool) config('notebook.uploads.pdf_text_layer', true) && $this->ai->capabilities()->pdfInput) {
            return null;
        }

        $key = sha1($contents);

        if (! array_key_exists($key, $this->textLayers)) {
            $this->textLayers = [$key => $this->textLayer($contents)];
        }

        return $this->textLayers[$key];
    }

    /**
     * The text a born-digital PDF carries, page by page, or null for one
     * whose text layer is missing (a scan), too thin (most pages without
     * text), garbled (fonts without a character map), without its word
     * spaces (lostWordSpaces()), or that cannot be parsed (damaged, or
     * encrypted).
     *
     * @return list<SourcePage>|null
     */
    private function textLayer(string $contents): ?array
    {
        try {
            $config = new PdfParserConfig;
            $config->setRetainImageContent(false);
            $pages = [];

            foreach ((new PdfParser([], $config))->parseContent($contents)->getPages() as $index => $page) {
                $pages[] = ['page' => $index + 1, 'text' => $this->normalize($page->getText())];
            }
        } catch (\Throwable $throwable) {
            Log::info('Notebook PDF has no readable text layer', ['error' => $throwable->getMessage()]);

            return null;
        }

        if ($pages === []) {
            return null;
        }

        $text = implode("\n", array_column($pages, 'text'));
        $letters = (int) preg_match_all('/[\p{L}\p{N}]/u', $text);
        $garbled = (int) preg_match_all('/[\x{FFFD}\x{E000}-\x{F8FF}]|[^\P{Cc}\n\t]/u', $text);
        $pagesWithText = count(array_filter(
            $pages,
            static fn (array $page): bool => preg_match_all('/[\p{L}\p{N}]/u', $page['text']) >= self::MIN_PAGE_TEXT_CHARS
        ));

        if ($letters < self::MIN_DOCUMENT_TEXT_CHARS
            || $pagesWithText < self::MIN_TEXT_PAGE_SHARE * count($pages)
            || $garbled > self::MAX_GARBLED_SHARE * max(1, mb_strlen($text))
            || $this->lostWordSpaces($text)) {
            return null;
        }

        return array_values(array_filter($pages, static fn (array $page): bool => $page['text'] !== ''));
    }

    /**
     * Whether a text layer lost its word spaces: more than a few of its
     * words are letters run together past any real word's length (e.g.
     * `membekalimahasiswadengankonsep`).
     */
    private function lostWordSpaces(string $text): bool
    {
        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_filter($words, static fn (string $word): bool => preg_match('/\p{L}/u', $word) === 1);

        if ($words === []) {
            return false;
        }

        $runTogether = count(array_filter(
            $words,
            static fn (string $word): bool => preg_match('/\p{L}{'.self::RUN_TOGETHER_LETTERS.',}/u', $word) === 1
        ));

        return $runTogether > self::MAX_RUN_TOGETHER_SHARE * count($words);
    }

    /**
     * @return array{pages: list<SourcePage>, truncated: bool}
     */
    private function transcribe(string $contents, string $mime): array
    {
        $capabilities = $this->ai->capabilities();

        if (! (str_starts_with(strtolower($mime), 'image/') ? $capabilities->imageInput : $capabilities->pdfInput)) {
            throw new NotebookSourceException(NotebookAiException::UNSUPPORTED_INPUT_MESSAGE);
        }

        $tooLarge = $this->tooLarge($contents, $mime);

        if ($tooLarge !== null) {
            throw new NotebookSourceException($tooLarge);
        }

        if (! $this->ai->isConfigured()) {
            throw NotebookAiException::notConfigured($this->ai->label());
        }

        $budget = max(30, AiRequest::ingestBudgetSeconds());

        try {
            // One long generation may use the whole budget: a dense module
            // takes well over the web request's per-attempt timeout.
            $result = $this->ai->generate(new AiRequest(
                messages: [AiRequest::user([
                    AiRequest::file($mime, base64_encode($contents)),
                    AiRequest::text(self::TRANSCRIBE_PROMPT),
                ])],
                maxOutputTokens: 65536,
                temperature: 0,
                budgetSeconds: $budget,
                attemptTimeoutSeconds: $budget,
                keepBlockedText: true,
            ));
        } catch (NotebookAiException $exception) {
            throw match (true) {
                // Out of time, or out of output before any text even at the largest limit.
                $exception->is(NotebookAiException::REASON_TIMED_OUT), $exception->is(NotebookAiException::REASON_TOO_LONG) => new NotebookSourceException('Berkas ini terlalu panjang untuk dibaca sekaligus. Pecah PDF-nya menjadi beberapa bagian, lalu unggah lagi.'),
                $exception->is(NotebookAiException::REASON_REJECTED) => new NotebookSourceException('Model AI tidak bisa membaca berkas ini. Pastikan berkasnya tidak rusak atau terkunci kata sandi.'),
                $exception->is(NotebookAiException::REASON_UNSUPPORTED_INPUT) => new NotebookSourceException($exception->getMessage()),
                default => $exception,
            };
        }

        return [
            'pages' => $this->splitPages($result->text),
            // Cut at the output limit, or withheld from some point on (e.g. where the text repeats a published work).
            'truncated' => $result->truncated || $result->stop === AiResult::STOP_BLOCKED,
        ];
    }

    /**
     * @return list<SourcePage>
     */
    private function splitPages(string $text): array
    {
        $parts = preg_split(self::PAGE_MARKER, $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
        $pages = [];
        $preamble = $this->normalize((string) array_shift($parts));

        if ($preamble !== '') {
            $pages[] = ['page' => null, 'text' => $preamble];
        }

        for ($index = 0; $index + 1 < count($parts); $index += 2) {
            $pageText = $this->normalize($parts[$index + 1]);

            if ($pageText !== '') {
                $pages[] = ['page' => (int) $parts[$index], 'text' => $pageText];
            }
        }

        return $pages;
    }

    private function zipEntry(string $contents, string $entry): string
    {
        $zip = $this->openZip($contents);

        try {
            $this->guardUnzippedSize($this->unzippedSize($zip->statName($entry)));
            $xml = $zip->getFromName($entry);
        } finally {
            $zip->close();
        }

        if (! is_string($xml)) {
            throw new NotebookSourceException('Isi dokumen tidak ditemukan. Berkas mungkin rusak.');
        }

        return $xml;
    }

    /**
     * The uncompressed size the archive declares for an entry; reading the
     * entry allocates that much at once.
     *
     * @param  array<string, mixed>|false  $stat
     */
    private function unzippedSize(array|false $stat): int
    {
        return is_array($stat) && is_int($stat['size'] ?? null) ? $stat['size'] : 0;
    }

    /**
     * A small upload can unpack to gigabytes; refuse it before it does.
     */
    private function guardUnzippedSize(int $bytes): void
    {
        if ($bytes > max(1, (int) config('notebook.uploads.max_unzipped_bytes', 50 * 1024 * 1024))) {
            throw new NotebookSourceException('Isi dokumen ini terlalu besar untuk dibaca Notebook. Pecah dokumennya menjadi beberapa berkas.');
        }
    }

    private function openZip(string $contents): ZipArchive
    {
        $path = tempnam(sys_get_temp_dir(), 'notebook-source-');

        if ($path === false || file_put_contents($path, $contents) === false) {
            throw new NotebookSourceException('Berkas tidak bisa dibuka.');
        }

        $zip = new ZipArchive;
        $opened = $zip->open($path, ZipArchive::RDONLY);
        @unlink($path);

        if ($opened !== true) {
            throw new NotebookSourceException('Berkas tidak bisa dibuka. Pastikan formatnya DOCX atau PPTX.');
        }

        return $zip;
    }

    private function xmlText(string $xml, string $tag): string
    {
        $pattern = '~<'.preg_quote($tag, '~').'(?:\s[^>]*)?>(.*?)</'.preg_quote($tag, '~').'>~s';

        if (preg_match_all($pattern, $xml, $matches) === 0) {
            return '';
        }

        return trim(html_entity_decode(implode('', $matches[1]), ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }

    private function normalize(string $text): string
    {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = (string) preg_replace("/[ \t]+/u", ' ', $text);
        $text = (string) preg_replace("/\n{3,}/u", "\n\n", $text);

        return trim($text);
    }
}

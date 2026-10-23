<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\NotebookAiException;
use App\Exceptions\NotebookSourceException;
use ZipArchive;

/**
 * Turns a source file into text, page by page where the format has pages.
 *
 * Word and PowerPoint files and plain text are read locally. PDFs and
 * images have no reader in this application, so Gemini transcribes them
 * once; the text is kept and every later question uses only that text.
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
     * Inline data is limited by the request size.
     */
    private const MAX_TRANSCRIBE_BYTES = 19 * 1024 * 1024;

    public function __construct(private readonly GeminiClient $gemini) {}

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
            'pdf', 'image' => $this->transcribe($contents, $type === 'pdf' ? 'application/pdf' : $mime),
            default => throw new NotebookSourceException('Format berkas ini belum bisa dibaca Notebook.'),
        };
    }

    /**
     * Whether reading this format needs the model (it has no local reader).
     */
    public function needsModel(string $mime, string $filename = ''): bool
    {
        return in_array($this->type($mime, $filename), ['pdf', 'image'], true);
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
     * @return array{pages: list<SourcePage>, truncated: bool}
     */
    private function transcribe(string $contents, string $mime): array
    {
        if (strlen($contents) > self::MAX_TRANSCRIBE_BYTES) {
            throw new NotebookSourceException('Berkas terlalu besar untuk dibaca Notebook (maks. 19 MB).');
        }

        if (! $this->gemini->isConfigured()) {
            throw NotebookAiException::notConfigured();
        }

        $budget = max(30, (int) config('services.gemini.ingest_budget', 240));

        try {
            // One long generation may use the whole budget: a dense module
            // takes well over the web request's per-attempt timeout.
            $result = $this->gemini->generate([[
                'role' => 'user',
                'parts' => [
                    ['inlineData' => ['mimeType' => $mime, 'data' => base64_encode($contents)]],
                    ['text' => self::TRANSCRIBE_PROMPT],
                ],
            ]], null, ['temperature' => 0, 'maxOutputTokens' => 65536], $budget, $budget);
        } catch (NotebookAiException $exception) {
            throw match (true) {
                // Out of time, or out of output before any text even at the largest limit.
                $exception->is(NotebookAiException::REASON_TIMED_OUT), $exception->is(NotebookAiException::REASON_TOO_LONG) => new NotebookSourceException('Berkas ini terlalu panjang untuk dibaca sekaligus. Pecah PDF-nya menjadi beberapa bagian, lalu unggah lagi.'),
                $exception->is(NotebookAiException::REASON_REJECTED) => new NotebookSourceException('Model AI tidak bisa membaca berkas ini. Pastikan berkasnya tidak rusak atau terkunci kata sandi.'),
                default => $exception,
            };
        }

        return [
            'pages' => $this->splitPages($result['text']),
            // Cut at the output limit, or stopped where the text repeats a published work.
            'truncated' => $result['truncated'] || $result['finish_reason'] === 'RECITATION',
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

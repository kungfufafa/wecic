<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\NotebookAiException;
use App\Models\Notebook;
use App\Models\NotebookChunk;
use App\Models\NotebookMessage;
use App\Models\NotebookSource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Answers and study material grounded in a notebook's sources.
 *
 * The excerpts sent to the model are numbered; the model cites them as
 * `[n]`, and every citation is mapped back to its source and page. Small
 * notebooks send all their text; larger ones send the excerpts that match
 * the question best (keyword scoring, no embeddings), within
 * `notebook.limits.context_chars`. The student may narrow the context to
 * chosen sources; every answer records the context it was given.
 */
class NotebookAssistant
{
    public const STUDIO_KINDS = [
        'summary' => 'Ringkasan',
        'study_guide' => 'Panduan belajar',
        'assignment_plan' => 'Kerangka tugas',
        'faq' => 'Tanya jawab (FAQ)',
        'glossary' => 'Glosarium',
        'quiz' => 'Kuis',
        'flashcards' => 'Flashcard',
        'mind_map' => 'Peta konsep',
    ];

    private const SYSTEM_INSTRUCTION = <<<'PROMPT'
        Kamu adalah asisten belajar MyCIC Notebook untuk mahasiswa Universitas Catur Insan Cendekia.
        Gunakan HANYA kutipan sumber yang diberikan. Setiap informasi dari sumber diberi rujukan nomor kutipannya dalam kurung siku, misalnya [3] atau [2][5], tepat setelah kalimatnya.
        Jangan pernah membuat nomor rujukan yang tidak ada. Jika sumber tidak membahas hal yang ditanyakan, katakan terus terang bahwa sumber belum membahasnya, lalu sarankan bagian materi atau kata kunci yang bisa dicari.
        Jawab dalam bahasa Indonesia yang jelas dan ramah (kecuali diminta bahasa lain), dengan Markdown sederhana: paragraf pendek, daftar berpoin, **tebal** untuk istilah penting, dan judul "## " bila perlu.
        Untuk soal tugas atau ujian yang dinilai, bantu memahami: jelaskan konsep, langkah, dan contoh lain, bukan jawaban siap salin.
        PROMPT;

    private const STUDIO_PROMPTS = [
        'summary' => 'Buat ringkasan menyeluruh dari semua sumber: mulai dengan gambaran umum satu paragraf, lalu bagian "## " per topik utama berisi poin-poin penting. Beri rujukan [n].',
        'study_guide' => 'Buat panduan belajar untuk persiapan ujian: "## Konsep kunci" (istilah dan penjelasan singkat), "## Penjelasan" per topik dengan contoh, "## Hal yang sering keliru", dan "## Pertanyaan latihan" (8 pertanyaan esai tanpa jawaban). Beri rujukan [n].',
        'assignment_plan' => 'Bantu mahasiswa menyusun tugasnya dengan rapi dan cepat, hanya berdasarkan sumber. Tulis: "## Pemahaman tugas" (apa yang diminta dan kriteria yang tersirat, 2–4 kalimat; bila instruksi tugas tidak ada di sumber, sebutkan asumsinya), "## Kerangka" (judul kerja, lalu bagian-bagian berurutan; tiap bagian berisi 2–4 poin yang harus dibahas, masing-masing dengan rujukan [n]), "## Konsep kunci dari materi" (5–10 konsep dengan penjelasan satu kalimat dan rujukan [n]), "## Langkah pengerjaan" (urutan kerja ringkas), "## Checklist sebelum dikumpulkan", dan "## Bagian yang belum tercakup sumber" (hal yang perlu dicari di luar sumber, jika ada). Jangan menulis isi tugas yang siap kumpul: berikan kerangka, poin, dan rujukan agar mahasiswa menulis sendiri.',
        'faq' => 'Buat 8 sampai 12 pertanyaan yang paling mungkin ditanyakan mahasiswa tentang materi ini, masing-masing sebagai judul "## " diikuti jawabannya. Beri rujukan [n].',
        'glossary' => 'Buat glosarium 10 sampai 25 istilah penting dari sumber, urut abjad. Definisi singkat dan tepat sesuai sumber.',
        'quiz' => 'Buat kuis 10 soal pilihan ganda dari sumber, masing-masing 4 pilihan dengan tepat satu jawaban benar, tingkat kesulitan beragam, dan penjelasan singkat mengapa jawabannya benar.',
        'flashcards' => 'Buat 15 flashcard: sisi depan berupa pertanyaan atau istilah singkat, sisi belakang jawaban ringkas.',
        'mind_map' => 'Buat peta konsep materi: satu topik utama, 3 sampai 7 cabang, tiap cabang punya 2 sampai 5 sub-topik singkat (maksimal 3 tingkat).',
    ];

    private const STOPWORDS = [
        'yang', 'dan', 'atau', 'di', 'ke', 'dari', 'ini', 'itu', 'untuk', 'dengan', 'pada', 'adalah', 'apa', 'apakah',
        'bagaimana', 'mengapa', 'kenapa', 'jelaskan', 'sebutkan', 'tolong', 'saya', 'aku', 'kamu', 'dalam', 'juga', 'akan',
        'bisa', 'dapat', 'tentang', 'oleh', 'sebagai', 'tidak', 'ada', 'lebih', 'secara', 'serta', 'para', 'the', 'and',
        'what', 'how', 'why', 'is', 'are', 'of', 'to', 'in', 'materi', 'contoh', 'maksud', 'arti',
    ];

    public function __construct(private readonly GeminiClient $gemini) {}

    public function isAvailable(): bool
    {
        return (bool) config('notebook.enabled', true) && $this->gemini->isConfigured();
    }

    /**
     * @param  list<int>  $sourceIds  Limit the context to these sources (empty: every ready source).
     * @return array{question: NotebookMessage, answer: NotebookMessage}
     */
    public function ask(Notebook $notebook, string $question, array $sourceIds = []): array
    {
        $this->guard($notebook, $sourceIds);
        $excerpts = $this->excerpts($notebook, $question, $sourceIds);
        $context = $this->contextOf($notebook, $excerpts, $sourceIds, null);
        $history = $notebook->messages()
            ->where('kind', 'chat')
            ->latest('id')
            ->limit(max(0, (int) config('notebook.limits.history_messages', 8)))
            ->get()
            ->reverse()
            ->values();

        $contents = $history->map(static fn (NotebookMessage $message): array => [
            'role' => $message->role === NotebookMessage::ROLE_USER ? 'user' : 'model',
            'parts' => [['text' => $message->content]],
        ])->all();
        $contents[] = [
            'role' => 'user',
            'parts' => [['text' => $this->contextBlock($excerpts)."\n\n---\nPertanyaan: ".$question]],
        ];

        $result = $this->gemini->generate($contents, self::SYSTEM_INSTRUCTION, ['temperature' => 0.3, 'maxOutputTokens' => 4096]);
        [$text, $citations] = $this->citeText($result['text'], $excerpts);

        $userMessage = $notebook->messages()->create([
            'role' => NotebookMessage::ROLE_USER,
            'kind' => 'chat',
            'content' => $question,
        ]);
        $answer = $notebook->messages()->create([
            'role' => NotebookMessage::ROLE_ASSISTANT,
            'kind' => 'chat',
            'content' => $text,
            'citations' => $citations,
            'context' => $context,
        ]);
        $notebook->touch();

        return ['question' => $userMessage, 'answer' => $answer];
    }

    /**
     * @param  list<int>  $sourceIds  Limit the context to these sources (empty: every ready source).
     * @param  string|null  $focus  What the output should centre on, e.g. the assignment instructions.
     */
    public function studio(Notebook $notebook, string $kind, array $sourceIds = [], ?string $focus = null): NotebookMessage
    {
        if (! isset(self::STUDIO_PROMPTS[$kind])) {
            throw new \InvalidArgumentException("Unknown studio output [{$kind}].");
        }

        $this->guard($notebook, $sourceIds);
        $excerpts = $this->excerpts($notebook, $focus, $sourceIds);
        $context = $this->contextOf($notebook, $excerpts, $sourceIds, $focus);
        $schema = $this->schema($kind);
        $task = self::STUDIO_PROMPTS[$kind];

        if ($focus !== null) {
            $task .= ($kind === 'assignment_plan' ? "\n\nInstruksi/fokus tugas dari mahasiswa: " : "\n\nFokus yang diminta mahasiswa: ").$focus;
        }

        $result = $this->gemini->generate([[
            'role' => 'user',
            'parts' => [['text' => $this->contextBlock($excerpts)."\n\n---\nTugas: ".$task]],
        ]], self::SYSTEM_INSTRUCTION, array_filter([
            'temperature' => $kind === 'quiz' || $kind === 'flashcards' ? 0.5 : 0.3,
            'maxOutputTokens' => 8192,
            'responseMimeType' => $schema !== null ? 'application/json' : null,
            'responseSchema' => $schema,
        ], static fn (mixed $value): bool => $value !== null));

        if ($schema !== null) {
            $data = json_decode($result['text'], true);

            if (! is_array($data)) {
                throw NotebookAiException::emptyAnswer();
            }

            $content = (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $citations = $this->citeNumbers($this->numbersIn($data), $excerpts);
        } else {
            [$content, $citations] = $this->citeText($result['text'], $excerpts);
        }

        $message = $notebook->messages()->create([
            'role' => NotebookMessage::ROLE_ASSISTANT,
            'kind' => $kind,
            'content' => $content,
            'citations' => $citations,
            'context' => $context,
            'pinned' => true,
        ]);
        $notebook->touch();

        return $message;
    }

    /**
     * Excerpts for the model, numbered from 1 in source order.
     *
     * @param  list<int>  $sourceIds
     * @return list<array{n: int, chunk: NotebookChunk, source: NotebookSource}>
     */
    public function excerpts(Notebook $notebook, ?string $query, array $sourceIds = []): array
    {
        $sources = $notebook->sources()
            ->where('status', NotebookSource::STATUS_READY)
            ->when($sourceIds !== [], static fn ($builder) => $builder->whereKey($sourceIds))
            ->orderBy('id')
            ->get()
            ->keyBy('id');
        $chunks = NotebookChunk::query()
            ->where('notebook_id', $notebook->id)
            ->whereIn('notebook_source_id', $sources->keys()->all())
            ->orderBy('notebook_source_id')
            ->orderBy('position')
            ->get();
        $budget = max(2000, (int) config('notebook.limits.context_chars', 300000));
        $selected = $chunks->sum(static fn (NotebookChunk $chunk): int => mb_strlen($chunk->content)) <= $budget
            ? $chunks
            : $this->bestChunks($chunks, $query, $budget);

        return $selected
            ->sortBy([['notebook_source_id', 'asc'], ['position', 'asc']])
            ->values()
            ->map(static fn (NotebookChunk $chunk, int $index): array => [
                'n' => $index + 1,
                'chunk' => $chunk,
                'source' => $sources->get($chunk->notebook_source_id),
            ])
            ->all();
    }

    /**
     * What the model was given, so the student can judge an answer by its
     * context: which sources, how much of their text, and whether some of
     * it was left out for size.
     *
     * @param  list<array{n: int, chunk: NotebookChunk, source: NotebookSource}>  $excerpts
     * @param  list<int>  $sourceIds
     * @return array{source_ids: list<int>, sources: list<array{id: int, title: string, meeting_number: int|null}>, total_sources: int, excerpts: int, chars: int, partial: bool, focus: string|null}
     */
    private function contextOf(Notebook $notebook, array $excerpts, array $sourceIds, ?string $focus): array
    {
        $sources = [];
        $chars = 0;

        foreach ($excerpts as $excerpt) {
            $sources[$excerpt['source']->id] ??= [
                'id' => $excerpt['source']->id,
                'title' => $excerpt['source']->title,
                'meeting_number' => $excerpt['source']->meeting_number,
            ];
            $chars += mb_strlen($excerpt['chunk']->content);
        }

        $readySourceIds = $notebook->sources()->where('status', NotebookSource::STATUS_READY)->pluck('id')->all();
        $available = NotebookChunk::query()
            ->where('notebook_id', $notebook->id)
            ->whereIn('notebook_source_id', $sourceIds !== [] ? array_intersect($sourceIds, $readySourceIds) : $readySourceIds)
            ->count();

        return [
            'source_ids' => array_values(array_map('intval', array_keys($sources))),
            'sources' => array_values($sources),
            'total_sources' => count($readySourceIds),
            'excerpts' => count($excerpts),
            'chars' => $chars,
            'partial' => count($excerpts) < $available,
            'focus' => $focus !== null ? mb_substr($focus, 0, 300) : null,
        ];
    }

    /**
     * @param  Collection<int, NotebookChunk>  $chunks
     * @return Collection<int, NotebookChunk>
     */
    private function bestChunks(Collection $chunks, ?string $query, int $budget): Collection
    {
        $terms = $query !== null ? $this->terms($query) : [];
        $ranked = $terms !== [] ? $this->rank($chunks, $terms) : $this->spread($chunks);
        $selected = collect();
        $used = 0;

        foreach ($ranked as $chunk) {
            $length = mb_strlen($chunk->content);

            if ($used + $length > $budget) {
                continue;
            }

            $selected->push($chunk);
            $used += $length;
        }

        return $selected;
    }

    /**
     * Keyword relevance (BM25-like term saturation and rarity).
     *
     * @param  Collection<int, NotebookChunk>  $chunks
     * @param  list<string>  $terms
     * @return Collection<int, NotebookChunk>
     */
    private function rank(Collection $chunks, array $terms): Collection
    {
        $tokenized = $chunks->mapWithKeys(fn (NotebookChunk $chunk): array => [$chunk->id => array_count_values($this->terms($chunk->content))]);
        $total = max(1, $chunks->count());
        $scores = [];

        foreach ($terms as $term) {
            $documentFrequency = $tokenized->filter(static fn (array $counts): bool => isset($counts[$term]))->count();

            if ($documentFrequency === 0) {
                continue;
            }

            $idf = log(1 + $total / $documentFrequency);

            foreach ($tokenized as $chunkId => $counts) {
                $frequency = $counts[$term] ?? 0;

                if ($frequency > 0) {
                    $scores[$chunkId] = ($scores[$chunkId] ?? 0.0) + $idf * $frequency / ($frequency + 1.2);
                }
            }
        }

        if ($scores === []) {
            return $this->spread($chunks);
        }

        arsort($scores);
        $byId = $chunks->keyBy('id');
        $ranked = collect(array_keys($scores))->map(static fn (int $id): NotebookChunk => $byId->get($id));

        // Unmatched passages may still fill the remaining budget, spread over sources.
        return $ranked->concat($this->spread($chunks->reject(static fn (NotebookChunk $chunk): bool => isset($scores[$chunk->id]))));
    }

    /**
     * Passages taken in turn from every source, so a long source cannot
     * crowd out the others.
     *
     * @param  Collection<int, NotebookChunk>  $chunks
     * @return Collection<int, NotebookChunk>
     */
    private function spread(Collection $chunks): Collection
    {
        $bySource = $chunks->groupBy('notebook_source_id')->map(static fn (Collection $group): array => $group->values()->all())->values()->all();
        $spread = [];

        for ($round = 0; $bySource !== []; $round++) {
            foreach ($bySource as $index => $group) {
                if (! isset($group[$round])) {
                    unset($bySource[$index]);

                    continue;
                }

                $spread[] = $group[$round];
            }
        }

        return collect($spread);
    }

    /**
     * @return list<string>
     */
    private function terms(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter($words, static fn (string $word): bool => mb_strlen($word) >= 3 && ! in_array($word, self::STOPWORDS, true)));
    }

    /**
     * @param  list<array{n: int, chunk: NotebookChunk, source: NotebookSource}>  $excerpts
     */
    private function contextBlock(array $excerpts): string
    {
        $lines = ['Kutipan sumber:'];

        foreach ($excerpts as $excerpt) {
            $page = $excerpt['chunk']->page !== null ? ', hlm. '.$excerpt['chunk']->page : '';
            $lines[] = "[{$excerpt['n']}] (Sumber: {$excerpt['source']->title}{$page})\n{$excerpt['chunk']->content}";
        }

        return implode("\n\n", $lines);
    }

    /**
     * Normalise `[2, 5]` to `[2][5]`, drop numbers that are not excerpts, and
     * list what is cited.
     *
     * @param  list<array{n: int, chunk: NotebookChunk, source: NotebookSource}>  $excerpts
     * @return array{0: string, 1: list<array{n: int, source_id: int, source_title: string, page: int|null, excerpt: string}>}
     */
    private function citeText(string $text, array $excerpts): array
    {
        $valid = array_column($excerpts, 'n');
        $cited = [];

        $text = (string) preg_replace_callback('/\[(\d{1,4}(?:\s*[,;]\s*\d{1,4})*)\]/', function (array $match) use ($valid, &$cited): string {
            $numbers = array_filter(array_map('intval', preg_split('/\s*[,;]\s*/', $match[1]) ?: []), static fn (int $number): bool => in_array($number, $valid, true));

            foreach ($numbers as $number) {
                $cited[$number] = true;
            }

            return implode('', array_map(static fn (int $number): string => "[{$number}]", $numbers));
        }, $text);

        return [trim($text), $this->citeNumbers(array_keys($cited), $excerpts)];
    }

    /**
     * @param  list<int>  $numbers
     * @param  list<array{n: int, chunk: NotebookChunk, source: NotebookSource}>  $excerpts
     * @return list<array{n: int, source_id: int, source_title: string, page: int|null, excerpt: string}>
     */
    private function citeNumbers(array $numbers, array $excerpts): array
    {
        $byNumber = array_column($excerpts, null, 'n');
        $numbers = array_values(array_unique(array_filter($numbers, static fn (int $number): bool => isset($byNumber[$number]))));
        sort($numbers);

        return array_map(static fn (int $number): array => [
            'n' => $number,
            'source_id' => $byNumber[$number]['source']->id,
            'source_title' => $byNumber[$number]['source']->title,
            'page' => $byNumber[$number]['chunk']->page,
            'excerpt' => mb_strimwidth($byNumber[$number]['chunk']->content, 0, 400, '…'),
        ], $numbers);
    }

    /**
     * Excerpt numbers in a structured output's `citations` lists.
     *
     * @param  array<array-key, mixed>  $data
     * @return list<int>
     */
    private function numbersIn(array $data): array
    {
        $numbers = [];

        array_walk_recursive($data, static function (mixed $value, int|string $key) use (&$numbers): void {
            if (is_int($key) && is_int($value)) {
                $numbers[] = $value;
            }
        });

        return $numbers;
    }

    /**
     * @param  list<int>  $sourceIds
     */
    private function guard(Notebook $notebook, array $sourceIds = []): void
    {
        if (! $this->isAvailable()) {
            throw NotebookAiException::notConfigured();
        }

        if (! $notebook->sources()->where('status', NotebookSource::STATUS_READY)->exists()) {
            throw new NotebookAiException('Tambahkan minimal satu sumber yang sudah selesai dibaca dulu.', 422);
        }

        if ($sourceIds !== [] && ! $notebook->sources()->where('status', NotebookSource::STATUS_READY)->whereKey($sourceIds)->exists()) {
            throw new NotebookAiException('Sumber yang dipilih belum ada yang siap dibaca. Pilih sumber lain atau tunggu sebentar.', 422);
        }

        $key = 'notebook-ai:'.$notebook->owner_hash.':'.now()->format('Y-m-d');
        $limit = max(1, (int) config('notebook.limits.daily_requests', 60));

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            throw NotebookAiException::dailyLimitReached();
        }

        RateLimiter::hit($key, 86400);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function schema(string $kind): ?array
    {
        $citations = ['type' => 'ARRAY', 'items' => ['type' => 'INTEGER'], 'description' => 'Nomor kutipan sumber yang dipakai.'];

        return match ($kind) {
            'glossary' => [
                'type' => 'ARRAY',
                'items' => [
                    'type' => 'OBJECT',
                    'properties' => ['term' => ['type' => 'STRING'], 'definition' => ['type' => 'STRING'], 'citations' => $citations],
                    'required' => ['term', 'definition'],
                ],
            ],
            'quiz' => [
                'type' => 'ARRAY',
                'items' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'question' => ['type' => 'STRING'],
                        'options' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                        'answer_index' => ['type' => 'INTEGER', 'description' => 'Indeks (mulai 0) pilihan yang benar.'],
                        'explanation' => ['type' => 'STRING'],
                        'citations' => $citations,
                    ],
                    'required' => ['question', 'options', 'answer_index', 'explanation'],
                ],
            ],
            'flashcards' => [
                'type' => 'ARRAY',
                'items' => [
                    'type' => 'OBJECT',
                    'properties' => ['front' => ['type' => 'STRING'], 'back' => ['type' => 'STRING'], 'citations' => $citations],
                    'required' => ['front', 'back'],
                ],
            ],
            'mind_map' => [
                'type' => 'OBJECT',
                'properties' => [
                    'title' => ['type' => 'STRING'],
                    'children' => [
                        'type' => 'ARRAY',
                        'items' => [
                            'type' => 'OBJECT',
                            'properties' => [
                                'title' => ['type' => 'STRING'],
                                'children' => [
                                    'type' => 'ARRAY',
                                    'items' => [
                                        'type' => 'OBJECT',
                                        'properties' => [
                                            'title' => ['type' => 'STRING'],
                                            'children' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => ['title' => ['type' => 'STRING']], 'required' => ['title']]],
                                        ],
                                        'required' => ['title'],
                                    ],
                                ],
                            ],
                            'required' => ['title'],
                        ],
                    ],
                ],
                'required' => ['title', 'children'],
            ],
            default => null,
        };
    }
}

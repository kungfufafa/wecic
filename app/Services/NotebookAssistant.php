<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\NotebookAiException;
use App\Models\Notebook;
use App\Models\NotebookChunk;
use App\Models\NotebookMessage;
use App\Models\NotebookSource;
use App\Services\Ai\NotebookAiClient;
use App\Support\Ai\AiCapabilities;
use App\Support\Ai\AiRequest;
use App\Support\Ai\AiResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Answers and study material grounded in a notebook's sources.
 *
 * The excerpts sent to the model are numbered; the model cites them as
 * `[n]`, and every citation is mapped back to its source and page (a
 * bracketed number right after a word, like `arr[0]`, or inside code is not
 * a citation). Small notebooks send all their text; larger ones send the
 * excerpts that match the question best (keyword scoring, no embeddings), within
 * `notebook.limits.context_chars`. The student may narrow the context to
 * chosen sources; every answer records the context it was given.
 */
class NotebookAssistant
{
    /**
     * Whether the notebook's AI can answer, and why not (`aiStatus()`).
     */
    public const AI_OK = 'ok';

    public const AI_DISABLED = 'disabled';

    public const AI_MISSING_KEY = 'missing_key';

    public const AI_INVALID_KEY = 'invalid_key';

    public const AI_MODEL_MISCONFIGURED = 'model_misconfigured';

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
        Kamu adalah asisten belajar WeCIC Notebook untuk mahasiswa Universitas Catur Insan Cendekia.
        Gunakan HANYA kutipan sumber yang diberikan. Setiap informasi dari sumber diberi rujukan nomor kutipannya dalam kurung siku, misalnya [3] atau [2][5], tepat setelah kalimatnya.
        Tulis rujukan dengan satu spasi sebelum kurung siku ("… mengurangi redundansi [3].") atau sesudah titik ("… mengurangi redundansi.[3]"), jangan menempel pada kata atau angka ("redundansi[3]" tidak terbaca sebagai rujukan). Kurung siku berisi angka untuk hal lain, seperti indeks array, ditulis di dalam `kode`.
        Jangan pernah membuat nomor rujukan yang tidak ada. Jika sumber tidak membahas hal yang ditanyakan, katakan terus terang bahwa sumber belum membahasnya, lalu sarankan bagian materi atau kata kunci yang bisa dicari.
        Jawab dalam bahasa Indonesia yang jelas dan ramah (kecuali diminta bahasa lain), dengan Markdown sederhana: paragraf pendek, daftar berpoin, **tebal** untuk istilah penting, dan judul "## " bila perlu.
        Untuk soal tugas atau ujian yang dinilai, bantu memahami: jelaskan konsep, langkah, dan contoh lain, bukan jawaban siap salin.
        PROMPT;

    private const STUDIO_PROMPTS = [
        'summary' => 'Buat ringkasan menyeluruh dari semua sumber: mulai dengan gambaran umum satu paragraf, lalu bagian "## " per topik utama berisi poin-poin penting. Beri rujukan [n] dengan spasi sebelum kurung siku.',
        'study_guide' => 'Buat panduan belajar untuk persiapan ujian: "## Konsep kunci" (istilah dan penjelasan singkat), "## Penjelasan" per topik dengan contoh, "## Hal yang sering keliru", dan "## Pertanyaan latihan" (8 pertanyaan esai tanpa jawaban). Beri rujukan [n] dengan spasi sebelum kurung siku.',
        'assignment_plan' => 'Bantu mahasiswa menyusun tugasnya dengan rapi dan cepat, hanya berdasarkan sumber. Tulis: "## Pemahaman tugas" (apa yang diminta dan kriteria yang tersirat, 2–4 kalimat; bila instruksi tugas tidak ada di sumber, sebutkan asumsinya), "## Kerangka" (judul kerja, lalu bagian-bagian berurutan; tiap bagian berisi 2–4 poin yang harus dibahas, masing-masing dengan rujukan [n]), "## Konsep kunci dari materi" (5–10 konsep dengan penjelasan satu kalimat dan rujukan [n]), "## Langkah pengerjaan" (urutan kerja ringkas), "## Checklist sebelum dikumpulkan", dan "## Bagian yang belum tercakup sumber" (hal yang perlu dicari di luar sumber, jika ada). Jangan menulis isi tugas yang siap kumpul: berikan kerangka, poin, dan rujukan agar mahasiswa menulis sendiri.',
        'faq' => 'Buat 8 sampai 12 pertanyaan yang paling mungkin ditanyakan mahasiswa tentang materi ini, masing-masing sebagai judul "## " diikuti jawabannya. Beri rujukan [n] dengan spasi sebelum kurung siku.',
        'glossary' => 'Buat glosarium 10 sampai 25 istilah penting dari sumber, urut abjad. Definisi singkat dan tepat sesuai sumber.',
        'quiz' => 'Buat kuis 10 soal pilihan ganda dari sumber, masing-masing 4 pilihan dengan tepat satu jawaban benar, tingkat kesulitan beragam, dan penjelasan singkat mengapa jawabannya benar.',
        'flashcards' => 'Buat 15 flashcard: sisi depan berupa pertanyaan atau istilah singkat, sisi belakang jawaban ringkas.',
        'mind_map' => 'Buat peta konsep materi: satu topik utama, 3 sampai 7 cabang, tiap cabang punya 2 sampai 5 sub-topik singkat (maksimal 3 tingkat).',
    ];

    /**
     * A run of citations (`[3]`, `[2][5]`, `[2, 5]`) that does not follow a
     * word character or another bracket: `arr[0]` and `matrix[2][5]` are code.
     */
    private const CITATION_RUN = '/(?<![\p{L}\p{N}_\]])(?:\[\d{1,4}(?:\s*[,;]\s*\d{1,4})*\])+/u';

    /**
     * Fenced blocks (closed or not) and inline code spans.
     */
    private const CODE = '/(```.*?(?:```|$)|`[^`\n]*`)/su';

    private const TRUNCATED_ANSWER_NOTE = '*Jawaban terpotong karena terlalu panjang. Minta lanjutannya, atau persempit pertanyaanmu.*';

    /**
     * Studio outputs that are a list of items, wrapped as `{items: [...]}`
     * for the model (schema()) and stored as the bare list.
     */
    private const LIST_KINDS = ['glossary', 'quiz', 'flashcards'];

    private const TRUNCATED_STUDIO_NOTE = '*Hasil ini terpotong karena terlalu panjang. Centang lebih sedikit sumber, lalu buat ulang.*';

    private const STOPWORDS = [
        'yang', 'dan', 'atau', 'di', 'ke', 'dari', 'ini', 'itu', 'untuk', 'dengan', 'pada', 'adalah', 'apa', 'apakah',
        'bagaimana', 'mengapa', 'kenapa', 'jelaskan', 'sebutkan', 'tolong', 'saya', 'aku', 'kamu', 'dalam', 'juga', 'akan',
        'bisa', 'dapat', 'tentang', 'oleh', 'sebagai', 'tidak', 'ada', 'lebih', 'secara', 'serta', 'para', 'the', 'and',
        'what', 'how', 'why', 'is', 'are', 'of', 'to', 'in', 'materi', 'contoh', 'maksud', 'arti',
    ];

    public function __construct(private readonly NotebookAiClient $ai) {}

    public function isAvailable(): bool
    {
        return $this->aiStatus() === self::AI_OK;
    }

    /**
     * Whether the AI can answer: switched off (`notebook.enabled`), no key
     * (NotebookAiClient::isConfigured()), a key or model settings the API
     * rejected lately (NotebookAiClient::misconfiguration()), or ok.
     *
     * @return self::AI_*
     */
    public function aiStatus(): string
    {
        return match (true) {
            ! (bool) config('notebook.enabled', true) => self::AI_DISABLED,
            ! $this->ai->isConfigured() => self::AI_MISSING_KEY,
            default => match ($this->ai->misconfiguration()) {
                NotebookAiException::CAUSE_KEY => self::AI_INVALID_KEY,
                NotebookAiException::CAUSE_MODEL => self::AI_MODEL_MISCONFIGURED,
                default => self::AI_OK,
            },
        };
    }

    /**
     * @param  list<int>  $sourceIds  Limit the context to these sources (empty: every ready source).
     * @return array{question: NotebookMessage, answer: NotebookMessage}
     */
    public function ask(Notebook $notebook, string $question, array $sourceIds = []): array
    {
        $budgetKey = $this->guard($notebook, $sourceIds);
        $excerpts = $this->excerpts($notebook, $question, $sourceIds);
        $context = $this->contextOf($notebook, $excerpts, $sourceIds, null);
        $history = $notebook->messages()
            ->where('kind', 'chat')
            ->latest('id')
            ->limit(max(0, (int) config('notebook.limits.history_messages', 8)))
            ->get()
            ->reverse()
            ->values();

        // Earlier answers lose their [n]: those numbered other excerpts than this request's.
        $messages = $history->map(fn (NotebookMessage $message): array => $message->role === NotebookMessage::ROLE_USER
            ? AiRequest::user([AiRequest::text($message->content)])
            : AiRequest::assistant($this->withoutCitations($message->content))
        )->all();
        $messages[] = AiRequest::user([AiRequest::text($this->contextBlock($excerpts)."\n\n---\nPertanyaan: ".$question)]);

        // Cut even with the larger limit the client asks for once: kept, but marked as cut.
        $result = $this->charged($budgetKey, fn (): AiResult => $this->ai->generate(new AiRequest(
            messages: $messages,
            system: self::SYSTEM_INSTRUCTION,
            maxOutputTokens: 8192,
            temperature: 0.3,
        )));
        [$text, $citations] = $this->citeText($result->text, $excerpts);
        $context = [...$context, 'truncated' => $result->truncated, ...$this->usageOf($result)];

        if ($result->truncated) {
            $text .= "\n\n".self::TRUNCATED_ANSWER_NOTE;
        }

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

        $budgetKey = $this->guard($notebook, $sourceIds);
        $excerpts = $this->excerpts($notebook, $focus, $sourceIds);
        $context = $this->contextOf($notebook, $excerpts, $sourceIds, $focus);
        $schema = self::schema($kind);
        $task = self::STUDIO_PROMPTS[$kind];

        if ($focus !== null) {
            $task .= ($kind === 'assignment_plan' ? "\n\nInstruksi/fokus tugas dari mahasiswa: " : "\n\nFokus yang diminta mahasiswa: ").$focus;
        }

        [$content, $citations, $truncated, $usage] = $this->charged($budgetKey, function () use ($excerpts, $task, $kind, $schema): array {
            $result = $this->ai->generate(new AiRequest(
                messages: [AiRequest::user([AiRequest::text($this->contextBlock($excerpts)."\n\n---\nTugas: ".$task)])],
                system: self::SYSTEM_INSTRUCTION,
                maxOutputTokens: 16384,
                temperature: $kind === 'quiz' || $kind === 'flashcards' ? 0.5 : 0.3,
                jsonSchema: $schema,
            ));

            $truncated = $result->truncated;

            if ($schema === null) {
                [$content, $citations] = $this->citeText($result->text, $excerpts);

                return [$truncated ? $content."\n\n".self::TRUNCATED_STUDIO_NOTE : $content, $citations, $truncated, $this->usageOf($result)];
            }

            $data = $this->shaped($kind, $this->unwrapItems($kind, $this->decodeJson($result->text)));

            if ($data === null) {
                throw $truncated ? NotebookAiException::tooLong() : NotebookAiException::emptyAnswer();
            }

            return [(string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $this->citeNumbers($this->numbersIn($data), $excerpts), $truncated, $this->usageOf($result)];
        });

        $message = $notebook->messages()->create([
            'role' => NotebookMessage::ROLE_ASSISTANT,
            'kind' => $kind,
            'content' => $content,
            'citations' => $citations,
            'context' => [...$context, 'truncated' => $truncated, ...$usage],
            'pinned' => true,
        ]);
        $notebook->touch();

        return $message;
    }

    /**
     * Who answered and what it spent, kept in the answer's context so a
     * budget can later count tokens instead of requests.
     *
     * @return array{provider: string, model: string, usage: array{input_tokens: int|null, output_tokens: int|null, thoughts_tokens: int|null}}
     */
    private function usageOf(AiResult $result): array
    {
        return [
            'provider' => $this->ai->driver(),
            'model' => $result->model,
            'usage' => [
                'input_tokens' => $result->inputTokens,
                'output_tokens' => $result->outputTokens,
                'thoughts_tokens' => $result->thoughtsTokens,
            ],
        ];
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
            ->when($sourceIds !== [], static fn (Builder $builder): Builder => $builder->whereKey($sourceIds))
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
     *                                `truncated` (whether the output was cut at its length limit), and
     *                                who answered and what it spent (usageOf()), are added once the
     *                                model has answered.
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
     * list what is cited. Brackets in code, or right after a word, are kept
     * as written.
     *
     * @param  list<array{n: int, chunk: NotebookChunk, source: NotebookSource}>  $excerpts
     * @return array{0: string, 1: list<array{n: int, source_id: int, source_title: string, page: int|null, excerpt: string}>}
     */
    private function citeText(string $text, array $excerpts): array
    {
        $valid = array_column($excerpts, 'n');
        $cited = [];

        $text = $this->outsideCode($text, function (string $prose) use ($valid, &$cited): string {
            return (string) preg_replace_callback(self::CITATION_RUN, function (array $match) use ($valid, &$cited): string {
                preg_match_all('/\d{1,4}/', $match[0], $found);
                $numbers = array_values(array_unique(array_filter(array_map('intval', $found[0]), static fn (int $number): bool => in_array($number, $valid, true))));

                foreach ($numbers as $number) {
                    $cited[$number] = true;
                }

                return implode('', array_map(static fn (int $number): string => "[{$number}]", $numbers));
            }, $prose);
        });

        return [trim($text), $this->citeNumbers(array_keys($cited), $excerpts)];
    }

    /**
     * An earlier answer without its citation numbers.
     */
    private function withoutCitations(string $text): string
    {
        return trim($this->outsideCode($text, static fn (string $prose): string => (string) preg_replace(
            '/[ \t]+(?=[.,;:!?])/u',
            '',
            (string) preg_replace(self::CITATION_RUN, '', $prose)
        )));
    }

    /**
     * Apply `$transform` to the prose of a Markdown text, leaving code
     * spans and fenced blocks untouched.
     *
     * @param  callable(string): string  $transform
     */
    private function outsideCode(string $text, callable $transform): string
    {
        $parts = preg_split(self::CODE, $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($parts === false) {
            return $transform($text);
        }

        foreach ($parts as $index => $part) {
            if ($index % 2 === 0) {
                $parts[$index] = $transform($part);
            }
        }

        return implode('', $parts);
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
     * Run one model request against the student's daily budget. The request
     * is spent before the model is called, so requests sent at the same
     * time cannot all pass the check before any of them is charged; past
     * the budget it is given back and refused. A model that never answered
     * (busy, unreachable, timed out, misconfigured) gives it back too; an
     * answer keeps it, and so does one that came back unusable (cut off,
     * withheld, unreadable), since it was produced and paid for.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $request
     * @return TResult
     */
    private function charged(string $budgetKey, callable $request): mixed
    {
        if (RateLimiter::hit($budgetKey, 86400) > $this->dailyRequests()) {
            RateLimiter::decrement($budgetKey, 86400);

            throw NotebookAiException::dailyLimitReached();
        }

        try {
            return $request();
        } catch (\Throwable $throwable) {
            if (! $throwable instanceof NotebookAiException || ! $throwable->modelAnswered()) {
                RateLimiter::decrement($budgetKey, 86400);
            }

            throw $throwable;
        }
    }

    private function dailyRequests(): int
    {
        return max(1, (int) config('notebook.limits.daily_requests', 60));
    }

    /**
     * Check that the notebook can be asked, and return the key of the
     * student's daily budget, which charged() spends. A spent budget is
     * refused here already, before the context is gathered.
     *
     * @param  list<int>  $sourceIds
     */
    private function guard(Notebook $notebook, array $sourceIds = []): string
    {
        match ($this->aiStatus()) {
            self::AI_DISABLED => throw NotebookAiException::disabled(),
            self::AI_MISSING_KEY => throw NotebookAiException::notConfigured($this->ai->label()),
            self::AI_INVALID_KEY => throw NotebookAiException::misconfigured(NotebookAiException::CAUSE_KEY, $this->ai->label()),
            self::AI_MODEL_MISCONFIGURED => throw NotebookAiException::misconfigured(NotebookAiException::CAUSE_MODEL, $this->ai->label()),
            default => null,
        };

        if (! $notebook->sources()->where('status', NotebookSource::STATUS_READY)->exists()) {
            throw new NotebookAiException('Tambahkan minimal satu sumber yang sudah selesai dibaca dulu.', 422);
        }

        if ($sourceIds !== [] && ! $notebook->sources()->where('status', NotebookSource::STATUS_READY)->whereKey($sourceIds)->exists()) {
            throw new NotebookAiException('Sumber yang dipilih belum ada yang siap dibaca. Pilih sumber lain atau tunggu sebentar.', 422);
        }

        $key = 'notebook-ai:'.$notebook->owner_hash.':'.now()->format('Y-m-d');

        if (RateLimiter::tooManyAttempts($key, $this->dailyRequests())) {
            throw NotebookAiException::dailyLimitReached();
        }

        return $key;
    }

    /**
     * The JSON Schema a structured studio output follows, or null for one
     * written as prose. Lowercase JSON Schema with an object root, so every
     * provider's structured output takes it: a list comes wrapped as
     * `{items: [...]}`, which studio() unwraps before it is stored. Every
     * object takes no other properties and needs all of its own; citations
     * may be an empty list.
     *
     * @return array<string, mixed>|null
     */
    public static function schema(string $kind): ?array
    {
        $string = ['type' => 'string'];
        $citations = ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Nomor kutipan sumber yang dipakai.'];
        $list = static fn (array $properties): array => self::objectSchema([
            'items' => ['type' => 'array', 'items' => self::objectSchema($properties)],
        ]);
        $children = static fn (array $node): array => ['type' => 'array', 'items' => $node];
        $leaf = self::objectSchema(['title' => $string]);
        $branch = self::objectSchema(['title' => $string, 'children' => $children($leaf)]);
        $topic = self::objectSchema(['title' => $string, 'children' => $children($branch)]);

        return match ($kind) {
            'glossary' => $list(['term' => $string, 'definition' => $string, 'citations' => $citations]),
            'quiz' => $list([
                'question' => $string,
                'options' => ['type' => 'array', 'items' => $string],
                'answer_index' => ['type' => 'integer', 'description' => 'Indeks (mulai 0) pilihan yang benar.'],
                'explanation' => $string,
                'citations' => $citations,
            ]),
            'flashcards' => $list(['front' => $string, 'back' => $string, 'citations' => $citations]),
            'mind_map' => self::objectSchema(['title' => $string, 'children' => $children($topic)]),
            default => null,
        };
    }

    /**
     * An object that needs every property it lists and takes no others.
     *
     * @param  array<string, array<string, mixed>>  $properties
     * @return array<string, mixed>
     */
    private static function objectSchema(array $properties): array
    {
        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_keys($properties),
            'additionalProperties' => false,
        ];
    }

    /**
     * The JSON a structured answer holds. A model that follows the schema
     * natively answers bare JSON, read as it is: one cut short is no JSON.
     * One that only has the schema in its prompt (OpenAI-compatible
     * `json_object` or `none`) may wrap it in a code block or a sentence,
     * so the outermost `{...}` (or `[...]`) is tried next. Null when there
     * is no JSON to read.
     */
    private function decodeJson(string $text): mixed
    {
        $data = json_decode($text, true);

        if ($data !== null || $this->ai->capabilities()->structuredOutput === AiCapabilities::STRUCTURED_JSON_SCHEMA) {
            return $data;
        }

        foreach (['{' => '}', '[' => ']'] as $open => $close) {
            $start = strpos($text, $open);
            $end = strrpos($text, $close);

            if ($start !== false && $end !== false && $end > $start) {
                $data = json_decode(substr($text, $start, $end - $start + 1), true);

                if ($data !== null) {
                    return $data;
                }
            }
        }

        return null;
    }

    /**
     * The output when it has its kind's shape, else null: a mind map is an
     * object with a title, any other kind a list (unwrapItems()).
     *
     * @return array<int|string, mixed>|null
     */
    private function shaped(string $kind, mixed $data): ?array
    {
        if (! is_array($data)) {
            return null;
        }

        if (in_array($kind, self::LIST_KINDS, true)) {
            return array_is_list($data) ? $data : null;
        }

        return ! array_is_list($data) && is_string($data['title'] ?? null) ? $data : null;
    }

    /**
     * A list output as it is stored: the bare list from its `{items: [...]}`
     * wrapper (schema()). A bare list is taken as it is; a wrapper without
     * a list is no answer (null).
     */
    private function unwrapItems(string $kind, mixed $data): mixed
    {
        if (! in_array($kind, self::LIST_KINDS, true) || ! is_array($data) || array_is_list($data)) {
            return $data;
        }

        return is_array($data['items'] ?? null) && array_is_list($data['items']) ? $data['items'] : null;
    }
}

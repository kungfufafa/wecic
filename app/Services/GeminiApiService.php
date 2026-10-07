<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GeminiApiService
{
    private string $baseUrl;

    private string $defaultModel;

    private string $fallbackModel;

    private int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta'), '/');
        $this->defaultModel = (string) config('gemini.model', 'gemini-3.8-flash');
        $this->fallbackModel = (string) config('gemini.fallback_model', 'gemini-2.0-flash');
        $this->timeout = (int) config('gemini.timeout', 60);
    }

    /**
     * Resolve the active API key from parameter or config.
     */
    public function resolveApiKey(?string $customApiKey = null): ?string
    {
        $candidate = trim((string) ($customApiKey ?? config('gemini.api_key', '')));

        return $candidate !== '' ? $candidate : null;
    }

    /**
     * Discover an active working model supporting generateContent for the given API key.
     */
    public function discoverWorkingModel(string $apiKey, bool $forceRefresh = false): ?string
    {
        $cacheKey = 'gemini_active_model_'.md5($apiKey);
        $blacklistKey = 'gemini_blacklisted_models_'.md5($apiKey);
        $blacklisted = (array) Cache::get($blacklistKey, []);

        if (! $forceRefresh) {
            $cached = Cache::get($cacheKey);
            if ($cached && is_string($cached) && ! in_array($cached, $blacklisted, true) && ! str_contains($cached, '1.5-flash')) {
                return $cached;
            }
        }

        try {
            $url = "{$this->baseUrl}/models?key={$apiKey}";
            $response = Http::timeout(10)->get($url);

            if (! $response->successful()) {
                return null;
            }

            $models = (array) $response->json('models', []);
            $supported = [];

            foreach ($models as $item) {
                $methods = (array) ($item['supportedGenerationMethods'] ?? []);
                if (in_array('generateContent', $methods, true)) {
                    $name = (string) ($item['name'] ?? '');
                    $name = preg_replace('#^models/#', '', $name);
                    if ($name !== '') {
                        $supported[] = $name;
                    }
                }
            }

            // Exclude blacklisted or known retired models
            $excluded = array_merge($blacklisted, [
                'gemini-1.5-flash',
                'gemini-1.5-flash-001',
                'gemini-1.5-flash-002',
                'gemini-2.0-flash',
                'gemini-2.0-flash-001',
                'gemini-2.0-flash-lite',
                'gemini-2.5-flash',
            ]);
            $supported = array_values(array_filter($supported, fn ($m) => ! in_array($m, $excluded, true)));

            if ($supported === []) {
                return null;
            }

            // Priority order of preferred models: modern 3.x models first
            $priorityModels = [
                'gemini-3.8-flash',
                'gemini-3.0-flash',
                'gemini-3-flash',
                'gemini-3.8-pro',
                'gemini-3.0-pro',
            ];

            $chosen = null;
            foreach ($priorityModels as $priority) {
                if (in_array($priority, $supported, true)) {
                    $chosen = $priority;
                    break;
                }
            }

            if (! $chosen) {
                foreach ($supported as $modelName) {
                    if (str_contains($modelName, 'flash') && ! in_array($modelName, $excluded, true)) {
                        $chosen = $modelName;
                        break;
                    }
                }
            }

            if (! $chosen) {
                foreach ($supported as $modelName) {
                    if (str_starts_with($modelName, 'gemini-') && ! in_array($modelName, $excluded, true)) {
                        $chosen = $modelName;
                        break;
                    }
                }
            }

            $chosen ??= $supported[0] ?? null;

            if ($chosen) {
                Cache::put($cacheKey, $chosen, now()->addDay());
            }

            return $chosen;
        } catch (\Throwable $e) {
            Log::warning('Failed to auto-discover Gemini models: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Check if a Gemini API Key is configured and valid.
     *
     * @return array{valid: bool, message: string}
     */
    public function verifyApiKey(?string $apiKey = null): array
    {
        $resolvedKey = $this->resolveApiKey($apiKey);

        if (! $resolvedKey) {
            return [
                'valid' => false,
                'message' => 'API Key Gemini belum disetel. Masukkan API Key Anda di panel atau file konfigurasi .env.',
            ];
        }

        try {
            $url = "{$this->baseUrl}/models?key={$resolvedKey}";
            $response = Http::timeout(10)->get($url);

            if ($response->successful()) {
                $activeModel = $this->discoverWorkingModel($resolvedKey, true) ?? $this->defaultModel;

                return [
                    'valid' => true,
                    'message' => "API Key Gemini valid dan siap digunakan (Model: {$activeModel}).",
                ];
            }

            if ($response->status() === 400 || $response->status() === 403) {
                return [
                    'valid' => false,
                    'message' => 'API Key Gemini tidak valid atau tidak memiliki izin akses.',
                ];
            }

            return [
                'valid' => false,
                'message' => 'Gagal memverifikasi API Key (HTTP '.$response->status().').',
            ];
        } catch (\Throwable $e) {
            return [
                'valid' => false,
                'message' => 'Gagal menghubungi server Google Gemini: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Generate grounded response for study deep dive or assignment solver.
     *
     * @param  array<int, array{title: string, type?: string, content?: string, file_url?: string}>  $sources
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array{text: string, model: string, usage?: array<string, mixed>}
     */
    public function generateResponse(
        array $sources,
        array $history,
        string $userPrompt,
        ?string $actionType = null,
        ?string $customApiKey = null,
        ?string $studentContext = null
    ): array {
        $apiKey = $this->resolveApiKey($customApiKey);

        if (! $apiKey) {
            throw new RuntimeException('API Key Google Gemini belum diatur. Silakan masukkan API Key Anda untuk mulai menggunakan Notebook AI.');
        }

        $systemInstruction = $this->buildSystemInstruction($actionType, $studentContext);
        $contents = $this->buildContentsPayload($sources, $history, $userPrompt, $actionType);

        $cacheKey = 'gemini_active_model_'.md5($apiKey);
        $blacklistKey = 'gemini_blacklisted_models_'.md5($apiKey);
        $blacklisted = (array) Cache::get($blacklistKey, []);

        $retiredModels = [
            'gemini-1.5-flash',
            'gemini-1.5-flash-001',
            'gemini-1.5-flash-002',
            'gemini-2.0-flash',
            'gemini-2.0-flash-001',
            'gemini-2.0-flash-lite',
            'gemini-2.5-flash',
        ];

        $cachedModel = Cache::get($cacheKey);
        if ($cachedModel && (in_array($cachedModel, $blacklisted, true) || in_array($cachedModel, $retiredModels, true))) {
            Cache::forget($cacheKey);
            $cachedModel = null;
        }

        $attemptModel = $cachedModel ?: $this->defaultModel;
        if (in_array($attemptModel, $blacklisted, true) || in_array($attemptModel, $retiredModels, true)) {
            $attemptModel = 'gemini-3.8-flash';
        }

        $maxAttempts = 3;
        $response = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $response = $this->callGeminiApi($attemptModel, $apiKey, $systemInstruction, $contents);

            if ($response->successful()) {
                Cache::put($cacheKey, $attemptModel, now()->addDay());
                break;
            }

            $responseBody = (string) $response->body();
            $status = $response->status();

            // 1. Check if model is deprecated, retired, or not available
            $isModelError = $status === 404 ||
                str_contains($responseBody, 'not found') ||
                str_contains($responseBody, 'not supported') ||
                str_contains($responseBody, 'no longer available') ||
                str_contains($responseBody, 'Please update your code to use');

            if ($isModelError) {
                Log::info("Gemini model {$attemptModel} failed (Status: {$status}). Response: {$responseBody}");

                // Blacklist the rejected model permanently
                $blacklisted[] = $attemptModel;
                Cache::put($blacklistKey, array_values(array_unique($blacklisted)), now()->addWeek());
                Cache::forget($cacheKey);

                // Check if Google explicitly recommended a specific replacement model in the error
                $recommendedModel = null;
                if (preg_match('#use\s+(?:models[\\\\/]+)?(gemini-[a-zA-Z0-9\.\-_]+)#i', $responseBody, $match)) {
                    $recommendedModel = $match[1];
                }

                if ($recommendedModel && $recommendedModel !== $attemptModel && ! in_array($recommendedModel, $blacklisted, true)) {
                    Log::info("Auto-switching to Google-recommended model: {$recommendedModel}");
                    $attemptModel = $recommendedModel;

                    continue;
                }

                // Auto-discover another active model from Google
                $discoveredModel = $this->discoverWorkingModel($apiKey, forceRefresh: true);
                if ($discoveredModel && $discoveredModel !== $attemptModel && ! in_array($discoveredModel, $blacklisted, true)) {
                    Log::info("Discovered alternate active model {$discoveredModel}. Retrying.");
                    $attemptModel = $discoveredModel;

                    continue;
                }

                // If no alternative model found, default to gemini-3.8-flash
                if ($attemptModel !== 'gemini-3.8-flash') {
                    $attemptModel = 'gemini-3.8-flash';

                    continue;
                }

                break;
            }

            // 2. Check for temporary server overload (503 High Demand / 429 Rate Limit)
            $isOverloaded = ($status === 503 || $status === 429);
            if ($isOverloaded && $attempt < $maxAttempts) {
                Log::info("Gemini model {$attemptModel} temporarily overloaded (Status: {$status}). Retrying in 1.5s (Attempt {$attempt}/{$maxAttempts}).");
                usleep(1500000); // 1.5s backoff

                continue;
            }

            break;
        }

        if (! $response || ! $response->successful()) {
            $errorMessage = $response?->json('error.message') ?? 'Terjadi kesalahan saat menghubungi API Google Gemini.';
            if ($response?->status() === 503) {
                $errorMessage = 'Server model Google Gemini sedang mengalami lonjakan beban tinggi sementara (High Demand). Silakan coba kirim ulang dalam beberapa saat.';
            } elseif ($response?->status() === 429) {
                $errorMessage = 'Batas kuota atau laju permintaan API Gemini tercapai (Rate Limit). Silakan tunggu sebentar sebelum mencoba lagi.';
            }

            Log::error('Gemini API request failed', [
                'status' => $response?->status(),
                'error' => $response?->body(),
            ]);

            throw new RuntimeException($errorMessage);
        }

        $json = $response->json();
        $generatedText = (string) data_get($json, 'candidates.0.content.parts.0.text', '');

        if (trim($generatedText) === '') {
            $finishReason = data_get($json, 'candidates.0.finishReason');
            throw new RuntimeException("AI tidak menghasilkan teks (alasan selesai: {$finishReason}). Silakan coba pertanyaan lain.");
        }

        return [
            'text' => $generatedText,
            'model' => $attemptModel,
            'usage' => (array) ($json['usageMetadata'] ?? []),
        ];
    }

    /**
     * Execute HTTP POST call to Gemini generateContent.
     *
     * @param  array<int, array<string, mixed>>  $contents
     */
    private function callGeminiApi(
        string $model,
        string $apiKey,
        string $systemInstruction,
        array $contents
    ): Response {
        $url = "{$this->baseUrl}/models/{$model}:generateContent?key={$apiKey}";

        return Http::timeout($this->timeout)->post($url, [
            'contents' => $contents,
            'systemInstruction' => [
                'parts' => [
                    ['text' => $systemInstruction],
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.4,
                'maxOutputTokens' => 6144,
            ],
        ]);
    }

    /**
     * Build the persona and behavior instruction.
     */
    private function buildSystemInstruction(?string $actionType, ?string $studentContext): string
    {
        $instruction = "Anda adalah Asisten Akademik & AI Study Partner cerdas mahasiswa Universitas Catur Insan Cendekia (UCIC), beroperasi seperti Google NotebookLM.\n";
        $instruction .= "Fokus utama Anda:\n";
        $instruction .= "1. GROUNDING: Jadikan sumber materi kuliah, silabus, dan instruksi tugas yang dilampirkan sebagai rujukan utama.\n";
        $instruction .= "2. DEEP DIVE: Bantu mahasiswa memahami materi dengan jelas, bertahap, menggunakan analogi konkret, contoh terapan dunia nyata, dan struktur penjelasan yang runut.\n";
        $instruction .= "3. ASSIGNMENT SOLVER: Bantu mahasiswa menganalisis instruksi tugas dosen, membedah soal, merumuskan solusi bertahap, dan menyusun draf jawaban atau laporan yang berbobot, akurat, dan siap disesuaikan oleh mahasiswa untuk dikumpulkan.\n";
        $instruction .= "4. FORMATTING: Format jawaban dengan Markdown yang sangat rapi (gunakan judul ## dan ###, poin daftar, penebalan konsep penting, blok kode dengan sintaks bahasa yang sesuai jika materi pemrograman/teknis, dan tabel jika relevan).\n";
        $instruction .= "5. TONE: Bahasa Indonesia akademis, profesional, suportif, ramah, dan mendidik. Hindari jawaban setengah-setengah atau spekulasi yang melenceng dari materi.\n";

        if ($studentContext) {
            $instruction .= "\nIdentitas Mahasiswa: {$studentContext}.\n";
        }

        switch ($actionType) {
            case 'deep_dive':
                $instruction .= "\nMODUS: DEEP DIVE MATERI. Jelaskan konsep materi secara mendalam. Uraikan mengapa konsep ini penting, cara kerjanya, analogi sederhana, studi kasus aplikasi praktis, dan kesalahan umum yang sering terjadi.";
                break;
            case 'task_solve':
                $instruction .= "\nMODUS: PENGERJAAN TUGAS (ASSIGNMENT SOLVER). Analisis soal tugas dengan teliti, periksa batasan instruksi dosen, dan buat draf jawaban tugas yang lengkap, terstruktur, mendalam, dan siap diedit/dikumpulkan mahasiswa.";
                break;
            case 'task_outline':
                $instruction .= "\nMODUS: PANDUAN LANGKAH PENGERJAAN TUGAS. Bedah soal dan instruksi tugas, lalu sajikan langkah-langkah sistematis (step-by-step) untuk menyelesaikan tugas ini dengan sukses.";
                break;
            case 'summary':
                $instruction .= "\nMODUS: RINGKASAN & KEY TAKEAWAYS. Sajikan rangkuman eksekutif materi, konsep kunci beserta definisinya, dan intisari penting untuk persiapan ujian.";
                break;
            case 'quiz':
                $instruction .= "\nMODUS: KUIS PEMAHAMAN. Buat 3 hingga 5 soal latihan bervariasi (pilihan ganda dan studi kasus/analisis) berdasarkan materi yang ada, sertakan kunci jawaban dan pembahasan komprehensif di bagian bawahnya.";
                break;
            case 'task_review':
                $instruction .= "\nMODUS: REVIEW & KOREKSI JAWABAN. Evaluasi draf pengerjaan mahasiswa terhadap instruksi tugas dan materi kuliah. Berikan apresiasi, identifikasi kekurangan/celah, serta saran perbaikan konkrit.";
                break;
        }

        return $instruction;
    }

    /**
     * Build contents payload array for Gemini API.
     *
     * @param  array<int, array{title: string, type?: string, content?: string, file_url?: string}>  $sources
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array<int, array<string, mixed>>
     */
    private function buildContentsPayload(
        array $sources,
        array $history,
        string $userPrompt,
        ?string $actionType
    ): array {
        $contents = [];

        // Compile sources context into the initial grounding prompt
        $sourcesContext = "=== SUMBER MATERI & TUGAS PERKULIAHAN (GROUNDING SOURCES) ===\n";
        if ($sources === []) {
            $sourcesContext .= "Tidak ada dokumen khusus yang dilampirkan. Jawablah berdasarkan pengetahuan umum kurikulum akademik yang relevan.\n";
        } else {
            foreach ($sources as $index => $source) {
                $num = $index + 1;
                $title = $source['title'] ?? "Sumber #{$num}";
                $type = $source['type'] ?? 'materi';
                $content = trim((string) ($source['content'] ?? ''));

                $sourcesContext .= "\n[SUMBER #{$num}: {$title} (Tipe: {$type})]\n";
                if ($content !== '') {
                    $sourcesContext .= "{$content}\n";
                }
                if (! empty($source['file_url'])) {
                    $sourcesContext .= "Lampiran URL Dokumen: {$source['file_url']}\n";
                }
            }
        }
        $sourcesContext .= "=== AKHIR SUMBER MATERI & TUGAS ===\n\n";

        // Include recent history (limit to last 10 messages for prompt efficiency)
        $trimmedHistory = array_slice($history, -10);

        if ($trimmedHistory === []) {
            $fullPrompt = $sourcesContext.'Instruksi/Pertanyaan Mahasiswa: '.$userPrompt;
            $contents[] = [
                'role' => 'user',
                'parts' => [
                    ['text' => $fullPrompt],
                ],
            ];
        } else {
            // First message contains sources context
            $firstHistoryItem = $trimmedHistory[0];
            $contents[] = [
                'role' => $firstHistoryItem['role'] === 'model' ? 'model' : 'user',
                'parts' => [
                    ['text' => $sourcesContext."Riwayat diskusi:\n".$firstHistoryItem['content']],
                ],
            ];

            for ($i = 1; $i < count($trimmedHistory); $i++) {
                $item = $trimmedHistory[$i];
                $contents[] = [
                    'role' => $item['role'] === 'model' ? 'model' : 'user',
                    'parts' => [
                        ['text' => $item['content']],
                    ],
                ];
            }

            // Current prompt
            $contents[] = [
                'role' => 'user',
                'parts' => [
                    ['text' => $userPrompt],
                ],
            ];
        }

        return $contents;
    }
}

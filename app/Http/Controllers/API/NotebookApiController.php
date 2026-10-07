<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Requests\ChatNotebookRequest;
use App\Http\Requests\SaveDraftRequest;
use App\Http\Requests\StoreNotebookRequest;
use App\Http\Requests\UpdateNotebookRequest;
use App\Models\Notebook;
use App\Models\NotebookMessage;
use App\Services\GeminiApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class NotebookApiController extends CampusApiController
{
    public function __construct(
        private readonly GeminiApiService $geminiService
    ) {}

    /**
     * Get active student username from session.
     */
    protected function currentUsername(): string
    {
        $info = $this->userInfo();
        if (is_array($info) && ! empty($info['username'])) {
            return (string) $info['username'];
        }

        return 'mahasiswa';
    }

    /**
     * Build student contextual string for AI prompt.
     */
    protected function studentContext(): ?string
    {
        $info = $this->userInfo();
        if (is_array($info)) {
            $name = $info['name'] ?? $info['nama'] ?? '';
            $username = $info['username'] ?? '';
            $prodi = $info['prodi'] ?? '';

            return trim("{$name} ({$username}) - Program Studi: {$prodi}");
        }

        return null;
    }

    /**
     * List all notebooks for current student.
     */
    public function index(): JsonResponse
    {
        $username = $this->currentUsername();

        $notebooks = Notebook::query()
            ->where('username', $username)
            ->withCount('messages')
            ->orderByDesc('updated_at')
            ->get();

        return $this->statusSuccess('Daftar notebook berhasil dimuat.', [
            'notebooks' => $notebooks,
            'has_default_api_key' => $this->geminiService->resolveApiKey() !== null,
        ]);
    }

    /**
     * Create or retrieve an existing notebook.
     */
    public function store(StoreNotebookRequest $request): JsonResponse
    {
        $username = $this->currentUsername();
        $validated = $request->validated();

        $meetingId = $validated['meeting_id'] ?? null;

        // If meeting_id is supplied, look for existing notebook for this meeting and username
        if ($meetingId) {
            $existing = Notebook::query()
                ->where('username', $username)
                ->where('meeting_id', (string) $meetingId)
                ->first();

            if ($existing) {
                // Update sources if new sources provided
                if (! empty($validated['sources'])) {
                    $existing->update([
                        'sources' => $validated['sources'],
                        'title' => $validated['title'] ?: $existing->title,
                    ]);
                }

                $existing->load('messages');

                return $this->statusSuccess('Notebook pertemuan ditemukan.', [
                    'notebook' => $existing,
                ]);
            }
        }

        $notebook = Notebook::create([
            'username' => $username,
            'title' => $validated['title'],
            'course_id' => $validated['course_id'] ?? null,
            'course_name' => $validated['course_name'] ?? null,
            'meeting_id' => $validated['meeting_id'] ?? null,
            'meeting_number' => $validated['meeting_number'] ?? null,
            'sources' => $validated['sources'] ?? [],
            'draft_content' => $validated['draft_content'] ?? null,
            'notes' => $validated['notes'] ?? [],
        ]);

        $notebook->load('messages');

        return $this->statusSuccess('Notebook berhasil dibuat.', [
            'notebook' => $notebook,
        ], statusCode: 201);
    }

    /**
     * Show notebook detail with full message thread.
     */
    public function show(int $id): JsonResponse
    {
        $username = $this->currentUsername();

        $notebook = Notebook::query()
            ->where('username', $username)
            ->where('id', $id)
            ->with('messages')
            ->first();

        if (! $notebook) {
            return response()->json([
                'status' => 'error',
                'message' => 'Notebook tidak ditemukan.',
            ], 404);
        }

        return $this->statusSuccess('Detail notebook berhasil dimuat.', [
            'notebook' => $notebook,
            'has_default_api_key' => $this->geminiService->resolveApiKey() !== null,
        ]);
    }

    /**
     * Update notebook attributes.
     */
    public function update(int $id, UpdateNotebookRequest $request): JsonResponse
    {
        $username = $this->currentUsername();

        $notebook = Notebook::query()
            ->where('username', $username)
            ->where('id', $id)
            ->first();

        if (! $notebook) {
            return response()->json([
                'status' => 'error',
                'message' => 'Notebook tidak ditemukan.',
            ], 404);
        }

        $notebook->update($request->validated());

        return $this->statusSuccess('Notebook berhasil diperbarui.', [
            'notebook' => $notebook,
        ]);
    }

    /**
     * Delete notebook and messages.
     */
    public function destroy(int $id): JsonResponse
    {
        $username = $this->currentUsername();

        $notebook = Notebook::query()
            ->where('username', $username)
            ->where('id', $id)
            ->first();

        if (! $notebook) {
            return response()->json([
                'status' => 'error',
                'message' => 'Notebook tidak ditemukan.',
            ], 404);
        }

        $notebook->delete();

        return $this->statusSuccess('Notebook berhasil dihapus.');
    }

    /**
     * Save draft assignment content directly.
     */
    public function saveDraft(int $id, SaveDraftRequest $request): JsonResponse
    {
        $username = $this->currentUsername();

        $notebook = Notebook::query()
            ->where('username', $username)
            ->where('id', $id)
            ->first();

        if (! $notebook) {
            return response()->json([
                'status' => 'error',
                'message' => 'Notebook tidak ditemukan.',
            ], 404);
        }

        $notebook->update([
            'draft_content' => $request->validated('draft_content'),
        ]);

        return $this->statusSuccess('Draf berhasil disimpan.', [
            'updated_at' => $notebook->updated_at?->toIso8601String(),
        ]);
    }

    /**
     * Send chat prompt or trigger studio action to AI.
     */
    public function chat(int $id, ChatNotebookRequest $request): JsonResponse
    {
        $username = $this->currentUsername();

        $notebook = Notebook::query()
            ->where('username', $username)
            ->where('id', $id)
            ->first();

        if (! $notebook) {
            return response()->json([
                'status' => 'error',
                'message' => 'Notebook tidak ditemukan.',
            ], 404);
        }

        $prompt = (string) $request->validated('prompt');
        $actionType = $request->validated('action_type') ?: 'chat';
        $customApiKey = $request->validated('api_key');
        $activeSources = $request->validated('active_sources') ?: ($notebook->sources ?? []);

        // Record student's user message
        $userMessage = NotebookMessage::create([
            'notebook_id' => $notebook->id,
            'role' => 'user',
            'content' => $prompt,
            'action_type' => $actionType,
        ]);

        // Get past messages for multi-turn conversation
        $history = $notebook->messages()
            ->where('id', '!=', $userMessage->id)
            ->orderBy('id')
            ->get(['role', 'content'])
            ->toArray();

        try {
            $studentContext = $this->studentContext();
            $aiResponse = $this->geminiService->generateResponse(
                sources: $activeSources,
                history: $history,
                userPrompt: $prompt,
                actionType: $actionType,
                customApiKey: $customApiKey,
                studentContext: $studentContext
            );

            // Record AI response
            $modelMessage = NotebookMessage::create([
                'notebook_id' => $notebook->id,
                'role' => 'model',
                'content' => $aiResponse['text'],
                'action_type' => $actionType,
            ]);

            $notebook->touch();

            return $this->statusSuccess('Balasan AI berhasil dibuat.', [
                'user_message' => $userMessage,
                'message' => $modelMessage,
                'model' => $aiResponse['model'],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Verify a custom or configured Gemini API key.
     */
    public function verifyKey(Request $request): JsonResponse
    {
        $apiKey = $request->input('api_key');
        $result = $this->geminiService->verifyApiKey($apiKey);

        return response()->json([
            'status' => $result['valid'] ? 'success' : 'error',
            'message' => $result['message'],
            'valid' => $result['valid'],
        ]);
    }
}

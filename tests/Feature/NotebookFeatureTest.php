<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Notebook;
use App\Services\PortalSessionManager;
use Illuminate\Support\Facades\Http;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class NotebookFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfTokenMiddleware::class);
        $this->artisan('migrate');
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_notebook_page_requires_session(): void
    {
        $this->get('/notebook')
            ->assertRedirect('/login');
    }

    public function test_notebook_page_renders_with_session(): void
    {
        $this->mockValidCampusSession('token-notebook');

        $this->withSession(['campus_session_token' => 'token-notebook'])
            ->get('/notebook')
            ->assertOk()
            ->assertSee('Notebook AI')
            ->assertSee('Studio AI')
            ->assertSee('Lembar Draf Tugas')
            ->assertSee('Sumber Grounding');
    }

    public function test_notebook_api_store_and_index(): void
    {
        $sessionToken = 'token-notebook-api';

        $sessionData = [
            'campus_session_token' => $sessionToken,
            'campus_user_info' => [
                'username' => '202301001',
                'name' => 'Budi Santoso',
                'prodi' => 'Teknik Informatika',
            ],
        ];

        $this->mockValidCampusSession($sessionToken, 3);

        // 1. Create a notebook
        $createResponse = $this->withSession($sessionData)
            ->postJson('/api/notebook', [
                'title' => 'Pemrograman Web - P3: REST API',
                'course_id' => '8861',
                'course_name' => 'Pemrograman Web',
                'meeting_id' => '445',
                'meeting_number' => '3',
                'sources' => [
                    [
                        'title' => 'Materi REST API',
                        'type' => 'materi',
                        'content' => 'Konsep REST API, status code, JSON format',
                    ],
                ],
                'draft_content' => 'Draf awal tugas.',
            ]);

        $createResponse->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('notebook.title', 'Pemrograman Web - P3: REST API')
            ->assertJsonPath('notebook.username', '202301001');

        $notebookId = $createResponse->json('notebook.id');

        // 2. List notebooks
        $listResponse = $this->withSession($sessionData)
            ->getJson('/api/notebook');

        $listResponse->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('notebooks.0.id', $notebookId);

        // 3. Detail notebook
        $detailResponse = $this->withSession($sessionData)
            ->getJson("/api/notebook/{$notebookId}");

        $detailResponse->assertOk()
            ->assertJsonPath('notebook.id', $notebookId)
            ->assertJsonPath('notebook.draft_content', 'Draf awal tugas.');
    }

    public function test_notebook_api_save_draft(): void
    {
        $sessionData = [
            'campus_session_token' => 'token-draft',
            'campus_user_info' => ['username' => '202301002'],
        ];

        $notebook = Notebook::create([
            'username' => '202301002',
            'title' => 'Jaringan Komputer',
            'draft_content' => 'Sebelum diubah',
        ]);

        $this->mockValidCampusSession('token-draft', 1);

        $response = $this->withSession($sessionData)
            ->postJson("/api/notebook/{$notebook->id}/save-draft", [
                'draft_content' => 'Setelah diubah dan diselesaikan dengan AI',
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('message', 'Draf berhasil disimpan.');

        $this->assertDatabaseHas('notebooks', [
            'id' => $notebook->id,
            'draft_content' => 'Setelah diubah dan diselesaikan dengan AI',
        ]);
    }

    public function test_notebook_api_chat_with_gemini_mock(): void
    {
        $sessionData = [
            'campus_session_token' => 'token-chat',
            'campus_user_info' => [
                'username' => '202301003',
                'name' => 'Siti Rahma',
                'prodi' => 'Sistem Informasi',
            ],
        ];

        $notebook = Notebook::create([
            'username' => '202301003',
            'title' => 'Basis Data - Normalisasi',
            'sources' => [
                [
                    'title' => 'Materi Normalisasi 1NF ke 3NF',
                    'content' => 'Normalisasi adalah proses mendekomposisi tabel agar tidak redundan.',
                ],
            ],
        ]);

        $this->mockValidCampusSession('token-chat', 1);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => "## Konsep Normalisasi\nNormalisasi bertujuan meminimalkan anomali data."],
                            ],
                        ],
                        'finishReason' => 'STOP',
                    ],
                ],
            ], 200),
        ]);

        $response = $this->withSession($sessionData)
            ->postJson("/api/notebook/{$notebook->id}/chat", [
                'prompt' => 'Jelaskan konsep 1NF sampai 3NF dengan contoh nyata.',
                'action_type' => 'deep_dive',
                'api_key' => 'dummy-api-key',
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('message.role', 'model')
            ->assertJsonPath('message.content', "## Konsep Normalisasi\nNormalisasi bertujuan meminimalkan anomali data.");

        $this->assertDatabaseHas('notebook_messages', [
            'notebook_id' => $notebook->id,
            'role' => 'user',
            'content' => 'Jelaskan konsep 1NF sampai 3NF dengan contoh nyata.',
        ]);

        $this->assertDatabaseHas('notebook_messages', [
            'notebook_id' => $notebook->id,
            'role' => 'model',
            'action_type' => 'deep_dive',
        ]);
    }

    public function test_notebook_api_chat_retries_and_recovers_if_model_not_found(): void
    {
        $sessionData = [
            'campus_session_token' => 'token-recovery',
            'campus_user_info' => [
                'username' => '202301004',
                'name' => 'Budi Santoso',
                'prodi' => 'Teknik Informatika',
            ],
        ];

        $notebook = Notebook::create([
            'username' => '202301004',
            'title' => 'Algoritma - Sorting',
            'sources' => [
                [
                    'title' => 'Quick Sort vs Merge Sort',
                    'content' => 'Quick Sort memiliki kompleksitas rata-rata O(n log n).',
                ],
            ],
        ]);

        $this->mockValidCampusSession('token-recovery', 1);

        Http::fake([
            '*generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent*' => Http::response([
                'error' => [
                    'code' => 404,
                    'message' => 'models/gemini-3.8-flash is not found for API version v1beta, or is not supported for generateContent.',
                ],
            ], 404),
            '*generativelanguage.googleapis.com/v1beta/models?key=*' => Http::response([
                'models' => [
                    [
                        'name' => 'models/gemini-3.0-flash',
                        'displayName' => 'Gemini 3.0 Flash',
                        'supportedGenerationMethods' => ['generateContent'],
                    ],
                ],
            ], 200),
            '*generativelanguage.googleapis.com/v1beta/models/gemini-3.0-flash:generateContent*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => 'Analisis Quick Sort berhasil dibuat.'],
                            ],
                        ],
                        'finishReason' => 'STOP',
                    ],
                ],
            ], 200),
        ]);

        $response = $this->withSession($sessionData)
            ->postJson("/api/notebook/{$notebook->id}/chat", [
                'prompt' => 'Bandingkan efisiensi Quick Sort dan Merge Sort.',
                'action_type' => 'task_solve',
                'api_key' => 'custom-key',
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('model', 'gemini-3.0-flash')
            ->assertJsonPath('message.content', 'Analisis Quick Sort berhasil dibuat.');
    }

    public function test_notebook_api_chat_retries_on_temporary_503_overload(): void
    {
        $sessionData = [
            'campus_session_token' => 'token-overload',
            'campus_user_info' => [
                'username' => '202301006',
                'name' => 'Dewi Sartika',
                'prodi' => 'Sistem Informasi',
            ],
        ];

        $notebook = Notebook::create([
            'username' => '202301006',
            'title' => 'Pengantar SI',
            'sources' => [
                ['title' => 'Materi SI', 'content' => 'Definisi Sistem Informasi.'],
            ],
        ]);

        $this->mockValidCampusSession('token-overload', 1);

        Http::fake([
            '*generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent*' => Http::sequence()
                ->push([
                    'error' => [
                        'code' => 503,
                        'message' => 'This model is currently experiencing high demand. Spikes in demand are usually temporary. Please try again later.',
                    ],
                ], 503)
                ->push([
                    'candidates' => [
                        [
                            'content' => [
                                'parts' => [
                                    ['text' => 'Penjelasan Sistem Informasi berhasil dimuat setelah retry.'],
                                ],
                            ],
                            'finishReason' => 'STOP',
                        ],
                    ],
                ], 200),
        ]);

        $response = $this->withSession($sessionData)
            ->postJson("/api/notebook/{$notebook->id}/chat", [
                'prompt' => 'Apa manfaat SI?',
                'action_type' => 'chat',
                'api_key' => 'overload-key',
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('model', 'gemini-3.8-flash')
            ->assertJsonPath('message.content', 'Penjelasan Sistem Informasi berhasil dimuat setelah retry.');
    }

    public function test_notebook_api_chat_auto_switches_when_model_no_longer_available(): void
    {
        $sessionData = [
            'campus_session_token' => 'token-upgrade',
            'campus_user_info' => [
                'username' => '202301005',
                'name' => 'Ahmad Dahlan',
                'prodi' => 'Teknik Informatika',
            ],
        ];

        $notebook = Notebook::create([
            'username' => '202301005',
            'title' => 'Struktur Data - Tree',
            'sources' => [
                [
                    'title' => 'Binary Search Tree',
                    'content' => 'BST adalah pohon biner terurut.',
                ],
            ],
        ]);

        $this->mockValidCampusSession('token-upgrade', 1);

        // Simulate having old model cached
        \Illuminate\Support\Facades\Cache::put('gemini_active_model_'.md5('upgrade-key'), 'gemini-2.5-flash');

        Http::fake([
            '*generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent*' => Http::response([
                'error' => [
                    'code' => 400,
                    'message' => 'This model models/gemini-2.5-flash is no longer available to new users. Please update your code to use models/gemini-3.8-flash for the latest features and improvements.',
                ],
            ], 400),
            '*generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => 'Penjelasan BST berhasil dibuat dengan model gemini-3.8-flash.'],
                            ],
                        ],
                        'finishReason' => 'STOP',
                    ],
                ],
            ], 200),
        ]);

        $response = $this->withSession($sessionData)
            ->postJson("/api/notebook/{$notebook->id}/chat", [
                'prompt' => 'Jelaskan konsep rotasi AVL Tree.',
                'action_type' => 'deep_dive',
                'api_key' => 'upgrade-key',
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('model', 'gemini-3.8-flash')
            ->assertJsonPath('message.content', 'Penjelasan BST berhasil dibuat dengan model gemini-3.8-flash.');
    }

    public function test_notebook_api_verify_key(): void
    {
        $this->mockValidCampusSession('token-test', 1);

        Http::fake([
            '*generativelanguage.googleapis.com/v1beta/models*' => Http::response([
                'models' => [
                    [
                        'name' => 'models/gemini-3.8-flash',
                        'displayName' => 'Gemini 3.8 Flash',
                        'supportedGenerationMethods' => ['generateContent'],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->withSession(['campus_session_token' => 'token-test'])
            ->postJson('/api/notebook/verify-key', [
                'api_key' => 'valid-gemini-key',
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('valid', true);
    }

    public function test_meeting_page_includes_notebook_action_buttons(): void
    {
        $this->mockValidCampusSession('token-meeting-ai');

        $response = $this->withSession(['campus_session_token' => 'token-meeting-ai'])
            ->get('/meeting/445?courses_id=8861');

        $response->assertOk()
            ->assertSee('Buka di Notebook AI')
            ->assertSee('Pelajari dengan AI')
            ->assertSee('Kerjakan dengan AI');
    }

    private function mockValidCampusSession(string $token, int $times = 1): void
    {
        $this->instance(
            PortalSessionManager::class,
            Mockery::mock(PortalSessionManager::class, function (MockInterface $mock) use ($token, $times): void {
                $mock->shouldReceive('validateSession')
                    ->times($times)
                    ->with($token)
                    ->andReturnTrue();
            })
        );
    }
}

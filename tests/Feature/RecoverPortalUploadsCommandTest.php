<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\RecoverPortalUploadsCommand;
use App\Enums\PortalOperationStage;
use App\Http\Middleware\VerifyCsrfTokenMiddleware;
use App\Jobs\ProcessPortalAssignmentUploadJob;
use App\Services\PortalOperationTracker;
use App\Services\PortalSessionManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Uploads run in the web process right after the response; a process that
 * dies midway leaves its operation, its target lock and its file behind for
 * portal:recover-uploads.
 */
class RecoverPortalUploadsCommandTest extends TestCase
{
    private const LOCK_KEY = 'portal_upload:test-owner:assignment:9000:29833:2';

    private PortalOperationTracker $tracker;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake();
        $this->tracker = app(PortalOperationTracker::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Cache::flush();

        parent::tearDown();
    }

    public function test_an_upload_whose_process_died_is_reported_as_unverified_and_frees_its_target_and_file(): void
    {
        $lock = Cache::lock(self::LOCK_KEY, 600);
        $this->assertTrue($lock->get());
        $operationId = $this->startUpload('assignment_upload', self::LOCK_KEY, $lock->owner());
        $this->tracker->progress($operationId, PortalOperationStage::PortalUploading, 'Mengirim file tugas ke portal SIPANDAI.', 55);

        $this->travel(RecoverPortalUploadsCommand::abandonedAfterSeconds() + 1)->seconds();

        $this->artisan('portal:recover-uploads')
            ->expectsOutput('Settled 1 abandoned upload(s); deleted 1 leftover file(s).')
            ->assertSuccessful();

        $operation = $this->tracker->get($operationId);
        $this->assertSame('unverified', $operation['stage']);
        $this->assertSame(100, $operation['percent']);
        $this->assertSame('Status upload tugas belum pasti.', $operation['message']);
        $this->assertStringContainsString('Cek halaman pertemuan sebelum mengunggah ulang', (string) $operation['next_step']);
        Storage::assertMissing($this->tracker->uploadFilePath($operationId, 'pdf'));
        $this->assertTrue(Cache::lock(self::LOCK_KEY, 600)->get());
    }

    public function test_an_abandoned_exam_upload_points_the_student_to_the_exam_page(): void
    {
        $operationId = $this->startUpload('exam_upload');

        $this->travel(RecoverPortalUploadsCommand::abandonedAfterSeconds() + 1)->seconds();

        $this->artisan('portal:recover-uploads')->assertSuccessful();

        $operation = $this->tracker->get($operationId);
        $this->assertSame('unverified', $operation['stage']);
        $this->assertSame('Status upload ujian belum pasti.', $operation['message']);
        $this->assertStringContainsString('Cek halaman ujian sebelum mengunggah ulang', (string) $operation['next_step']);
    }

    public function test_an_upload_that_still_reports_progress_is_left_alone(): void
    {
        $lock = Cache::lock(self::LOCK_KEY, 600);
        $this->assertTrue($lock->get());
        $operationId = $this->startUpload('assignment_upload', self::LOCK_KEY, $lock->owner());

        $this->travel(RecoverPortalUploadsCommand::abandonedAfterSeconds() + 1)->seconds();
        $this->tracker->progress($operationId, PortalOperationStage::ValidationSucceeded, 'Portal sedang memeriksa file.', 85);

        $this->artisan('portal:recover-uploads')
            ->expectsOutput('Settled 0 abandoned upload(s); deleted 0 leftover file(s).')
            ->assertSuccessful();

        $this->assertSame('validation_succeeded', $this->tracker->get($operationId)['stage']);
        Storage::assertExists($this->tracker->uploadFilePath($operationId, 'pdf'));
        $this->assertFalse(Cache::lock(self::LOCK_KEY, 600)->get());
    }

    public function test_a_recent_upload_is_left_alone(): void
    {
        $operationId = $this->startUpload('assignment_upload');

        $this->artisan('portal:recover-uploads')->assertSuccessful();

        $this->assertSame('connecting', $this->tracker->get($operationId)['stage']);
        Storage::assertExists($this->tracker->uploadFilePath($operationId, 'pdf'));
    }

    public function test_a_finished_upload_keeps_its_outcome_while_its_leftover_file_and_lock_go(): void
    {
        $lock = Cache::lock(self::LOCK_KEY, 600);
        $this->assertTrue($lock->get());
        $operationId = $this->startUpload('assignment_upload', self::LOCK_KEY, $lock->owner());
        $this->tracker->progress($operationId, PortalOperationStage::Completed, 'Tugas terkirim ke portal.', 100);

        $this->travel(RecoverPortalUploadsCommand::abandonedAfterSeconds() + 1)->seconds();

        $this->artisan('portal:recover-uploads')
            ->expectsOutput('Settled 0 abandoned upload(s); deleted 1 leftover file(s).')
            ->assertSuccessful();

        $this->assertSame('completed', $this->tracker->get($operationId)['stage']);
        Storage::assertMissing($this->tracker->uploadFilePath($operationId, 'pdf'));
        $this->assertTrue(Cache::lock(self::LOCK_KEY, 600)->get());
    }

    public function test_a_newer_upload_of_the_same_target_keeps_its_lock(): void
    {
        $operationId = $this->startUpload('assignment_upload', self::LOCK_KEY, 'owner-of-the-dead-upload');
        $newerLock = Cache::lock(self::LOCK_KEY, 600);
        $this->assertTrue($newerLock->get());

        $this->travel(RecoverPortalUploadsCommand::abandonedAfterSeconds() + 1)->seconds();

        $this->artisan('portal:recover-uploads')->assertSuccessful();

        $this->assertSame('unverified', $this->tracker->get($operationId)['stage']);
        $this->assertFalse(Cache::lock(self::LOCK_KEY, 600)->get());
    }

    public function test_old_files_without_a_known_operation_are_deleted(): void
    {
        Storage::put('portal-uploads/20k9HUOSspffP2V0vVWu0BKJkhtFMUiCdtCOsgCK.pdf', 'pdf');
        Storage::put('portal-uploads/'.Str::uuid().'.pdf', 'pdf');
        Storage::put('portal-uploads/.gitignore', '*');

        $this->travel(RecoverPortalUploadsCommand::abandonedAfterSeconds() + 1)->seconds();
        Storage::put('portal-uploads/fresh.pdf', 'pdf');
        touch(Storage::path('portal-uploads/fresh.pdf'), now()->getTimestamp());

        $this->artisan('portal:recover-uploads')
            ->expectsOutput('Settled 0 abandoned upload(s); deleted 2 leftover file(s).')
            ->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            ['portal-uploads/.gitignore', 'portal-uploads/fresh.pdf'],
            Storage::files('portal-uploads')
        );
    }

    public function test_the_upload_endpoint_leaves_what_the_command_needs_when_the_job_never_runs(): void
    {
        Bus::fake();
        $this->withoutMiddleware(VerifyCsrfTokenMiddleware::class);
        $this->instance(
            PortalSessionManager::class,
            Mockery::mock(PortalSessionManager::class, function (MockInterface $mock): void {
                $mock->shouldReceive('validateSession')->with('token-123')->andReturnTrue();
            })
        );

        $response = $this->withSession(['campus_session_token' => 'token-123'])
            ->post('/api/assignment', [
                'courses_id' => 9000,
                'id' => 29833,
                'pertemuan' => 2,
                'attachment' => UploadedFile::fake()->create('task.pdf', 128, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(202);

        Bus::assertDispatchedAfterResponse(ProcessPortalAssignmentUploadJob::class);
        $operationId = (string) $response->json('data.operation_id');
        $storedFile = 'portal-uploads/'.$operationId.'.pdf';
        Storage::assertExists($storedFile);

        $this->travel(RecoverPortalUploadsCommand::abandonedAfterSeconds() + 1)->seconds();

        $this->artisan('portal:recover-uploads')->assertSuccessful();

        $this->assertSame('unverified', $this->tracker->get($operationId)['stage']);
        Storage::assertMissing($storedFile);
        $this->assertTrue(Cache::lock((string) $this->tracker->get($operationId)['upload']['lock_key'], 600)->get());
    }

    private function startUpload(string $type, ?string $lockKey = null, ?string $lockOwner = null): string
    {
        $operationId = (string) Str::uuid();
        $storedFile = $this->tracker->uploadFilePath($operationId, 'pdf');

        $this->tracker->start($operationId, $type, 'laravel-session', 'Upload diterima, menyiapkan pengiriman.', [
            'file' => $storedFile,
            'lock_key' => $lockKey,
            'lock_owner' => $lockOwner,
        ]);
        Storage::put($storedFile, 'pdf-content');

        return $operationId;
    }
}

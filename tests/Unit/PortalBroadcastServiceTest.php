<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\PortalOperationStage;
use App\Events\PortalDataInvalidated;
use App\Events\PortalSyncStatusUpdated;
use App\Events\PortalUploadProgressed;
use App\Services\PortalBroadcastService;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PortalBroadcastServiceTest extends TestCase
{
    public function test_data_invalidation_payload_never_contains_raw_campus_token(): void
    {
        Event::fake();

        $service = app(PortalBroadcastService::class);
        $service->dataInvalidated(
            sessionId: 'laravel-session-id',
            campusSessionToken: 'raw-secret-campus-token',
            cacheKeys: ['/api/dashboard'],
            syncKeys: ['dashboard-overview'],
            message: 'Dashboard diperbarui.'
        );

        Event::assertDispatched(PortalDataInvalidated::class, function (PortalDataInvalidated $event): bool {
            $payload = $event->broadcastWith();

            return ($payload['cache_keys'] ?? []) === ['/api/dashboard']
                && ($payload['sync_keys'] ?? []) === ['dashboard-overview']
                && ! str_contains(json_encode($payload), 'raw-secret-campus-token')
                && isset($payload['session_fingerprint']);
        });
    }

    /**
     * @return array<string, array{0: PortalOperationStage, 1: string}>
     */
    public static function stageUrgencies(): array
    {
        return [
            'unverified upload' => [PortalOperationStage::Unverified, 'warning'],
            'failed upload' => [PortalOperationStage::Failed, 'error'],
            'completed upload' => [PortalOperationStage::Completed, 'info'],
            'upload in progress' => [PortalOperationStage::PortalUploading, 'info'],
        ];
    }

    #[DataProvider('stageUrgencies')]
    public function test_upload_progress_urgency_follows_the_stage(PortalOperationStage $stage, string $urgency): void
    {
        Event::fake();

        app(PortalBroadcastService::class)->uploadProgress(
            'laravel-session-id',
            'raw-secret-campus-token',
            'operation-1',
            $stage,
            'Status upload tugas belum pasti.',
            nextStep: 'Cek halaman tugas sebelum mengunggah ulang.'
        );

        Event::assertDispatched(PortalUploadProgressed::class, fn (PortalUploadProgressed $event): bool => $event->broadcastWith()['urgency'] === $urgency
            && $event->broadcastWith()['status'] === $stage->value);
    }

    #[DataProvider('stageUrgencies')]
    public function test_sync_status_urgency_follows_the_stage(PortalOperationStage $stage, string $urgency): void
    {
        Event::fake();

        app(PortalBroadcastService::class)->syncStatus('laravel-session-id', 'raw-secret-campus-token', $stage, 'Status sinkronisasi.');

        Event::assertDispatched(PortalSyncStatusUpdated::class, fn (PortalSyncStatusUpdated $event): bool => $event->broadcastWith()['urgency'] === $urgency);
    }
}

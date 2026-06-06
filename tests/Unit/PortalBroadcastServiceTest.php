<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Events\PortalDataInvalidated;
use App\Services\PortalBroadcastService;
use Illuminate\Support\Facades\Event;
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
}

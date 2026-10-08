<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\PortalRealtimeEvent;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PortalSyncStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $sessionId,
        public readonly array $payload
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('portal.session.'.$this->sessionId);
    }

    public function broadcastAs(): string
    {
        return PortalRealtimeEvent::SyncStatus->value;
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}

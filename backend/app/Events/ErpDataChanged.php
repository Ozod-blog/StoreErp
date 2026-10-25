<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Minimal invalidation signal. Never broadcasts request bodies or business data. */
class ErpDataChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $path,
        public string $method,
        public ?int $actorId,
        public string $changedAt,
        public string $eventId,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('erp.sync')];
    }

    public function broadcastAs(): string
    {
        return 'erp.data.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'eventId' => $this->eventId,
            'path' => $this->path,
            'method' => $this->method,
            'actorId' => $this->actorId,
            'changedAt' => $this->changedAt,
        ];
    }
}

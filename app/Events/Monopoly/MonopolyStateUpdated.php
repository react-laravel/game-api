<?php

namespace App\Events\Monopoly;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MonopolyStateUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $roomId,
        public string $type,
        public array $state,
        public array $payload = []
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("monopoly.room.{$this->roomId}")];
    }

    public function broadcastAs(): string
    {
        return $this->type;
    }

    public function broadcastWith(): array
    {
        return [
            'state' => $this->state,
            'payload' => $this->payload,
        ];
    }
}

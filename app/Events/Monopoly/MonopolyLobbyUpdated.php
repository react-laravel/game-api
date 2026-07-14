<?php

namespace App\Events\Monopoly;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MonopolyLobbyUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public array $rooms) {}

    public function broadcastOn(): array
    {
        return [new Channel('monopoly.lobby')];
    }

    public function broadcastAs(): string
    {
        return 'rooms.updated';
    }

    public function broadcastWith(): array
    {
        return ['rooms' => $this->rooms];
    }
}

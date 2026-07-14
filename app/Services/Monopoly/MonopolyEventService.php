<?php

namespace App\Services\Monopoly;

use App\Events\Monopoly\MonopolyLobbyUpdated;
use App\Events\Monopoly\MonopolyStateUpdated;
use App\Models\Monopoly\MonopolyEvent;
use App\Models\Monopoly\MonopolyPlayer;
use App\Models\Monopoly\MonopolyRoom;

class MonopolyEventService
{
    public function __construct(private readonly MonopolyStateService $states) {}

    public function log(
        MonopolyRoom $room,
        ?MonopolyPlayer $player,
        string $type,
        string $message,
        array $payload = []
    ): MonopolyEvent {
        return MonopolyEvent::create([
            'room_id' => $room->id,
            'player_id' => $player?->id,
            'type' => $type,
            'message' => $message,
            'payload' => $payload ?: null,
        ]);
    }

    public function broadcast(MonopolyRoom $room, string $type, array $payload = []): void
    {
        broadcast(new MonopolyStateUpdated($room->id, $type, $this->states->state($room), $payload))->toOthers();
    }

    public function broadcastLobby(): void
    {
        broadcast(new MonopolyLobbyUpdated($this->states->lobbyRooms()))->toOthers();
    }
}

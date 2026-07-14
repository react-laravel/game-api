<?php

use App\Models\Monopoly\MonopolyPlayer;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('monopoly.room.{roomId}', function (User $user, int $roomId): array|false {
    $player = MonopolyPlayer::query()
        ->where('room_id', $roomId)
        ->where('user_id', $user->id)
        ->first();

    return $player ? [
        'id' => (int) $user->id,
        'name' => (string) $user->name,
        'player_id' => (int) $player->id,
    ] : false;
});

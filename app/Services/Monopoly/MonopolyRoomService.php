<?php

namespace App\Services\Monopoly;

use App\Models\Monopoly\MonopolyPlayer;
use App\Models\Monopoly\MonopolyRoom;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MonopolyRoomService
{
    public function __construct(
        private readonly MonopolyStateService $states,
        private readonly MonopolyEventService $events,
    ) {}

    public function listRooms(int $userId): array
    {
        return $this->states->listRooms($userId);
    }

    public function lobbyRooms(): array
    {
        return $this->states->lobbyRooms();
    }

    public function createRoom(User $user, string $name): MonopolyRoom
    {
        return DB::transaction(function () use ($user, $name) {
            $room = MonopolyRoom::create([
                'created_by' => $user->id,
                'name' => $name,
                'status' => 'waiting',
                'max_players' => (int) config('monopoly.max_players'),
                'config' => [
                    'initial_cash' => (int) config('monopoly.initial_cash'),
                    'start_bonus' => (int) config('monopoly.start_bonus'),
                    'max_rounds' => (int) config('monopoly.max_rounds'),
                    'max_houses_per_property' => (int) config('monopoly.max_houses_per_property'),
                    'max_houses_per_build_action' => (int) config('monopoly.max_houses_per_build_action'),
                ],
            ]);

            $this->createPlayer($room, $user->name, 'human', $user->id, true);
            $this->states->createProperties($room);
            $this->events->log($room, null, 'room.created', "{$user->name} 创建了房间");
            $this->events->broadcast($room, 'state.updated');
            $this->events->broadcastLobby();

            return $room;
        });
    }

    public function joinRoom(MonopolyRoom $room, User $user): MonopolyPlayer
    {
        return DB::transaction(function () use ($room, $user) {
            $room = $this->lockRoom($room);
            $this->assertWaiting($room);
            $this->assertCapacity($room);

            $existing = $room->players()->where('user_id', $user->id)->first();
            if ($existing) {
                return $existing;
            }

            $player = $this->createPlayer($room, $user->name, 'human', $user->id);
            $this->events->log($room, $player, 'player.joined', "{$player->name} 加入了房间");
            $this->events->broadcast($room, 'player.joined', ['player_id' => $player->id]);
            $this->events->broadcastLobby();

            return $player;
        });
    }

    public function addComputer(MonopolyRoom $room, int $userId): MonopolyPlayer
    {
        return DB::transaction(function () use ($room, $userId) {
            $room = $this->lockRoom($room);
            $this->assertHost($room, $userId);
            $this->assertWaiting($room);
            $this->assertCapacity($room);

            $number = $room->players()->where('type', 'computer')->count() + 1;
            $player = $this->createPlayer($room, "电脑 {$number}", 'computer');
            $this->events->log($room, $player, 'player.joined', "{$player->name} 加入了房间");
            $this->events->broadcast($room, 'player.joined', ['player_id' => $player->id]);
            $this->events->broadcastLobby();

            return $player;
        });
    }

    public function removeComputer(MonopolyRoom $room, int $userId, int $playerId): void
    {
        DB::transaction(function () use ($room, $userId, $playerId) {
            $room = $this->lockRoom($room);
            $this->assertHost($room, $userId);
            $this->assertWaiting($room);

            $player = $room->players()->where('type', 'computer')->findOrFail($playerId);
            $name = $player->name;
            $player->delete();
            $this->resequencePlayers($room);
            $this->events->log($room, null, 'player.left', "{$name} 离开了房间");
            $this->events->broadcast($room, 'player.left', ['player_id' => $playerId]);
            $this->events->broadcastLobby();
        });
    }

    private function createPlayer(
        MonopolyRoom $room,
        string $name,
        string $type,
        ?int $userId = null,
        bool $host = false
    ): MonopolyPlayer {
        return MonopolyPlayer::create([
            'room_id' => $room->id,
            'user_id' => $userId,
            'name' => $name,
            'type' => $type,
            'turn_order' => (int) $room->players()->max('turn_order') + ($room->players()->exists() ? 1 : 0),
            'cash' => (int) config('monopoly.initial_cash'),
            'is_host' => $host,
        ]);
    }

    private function lockRoom(MonopolyRoom $room): MonopolyRoom
    {
        return MonopolyRoom::query()->whereKey($room->id)->lockForUpdate()->firstOrFail();
    }

    private function assertWaiting(MonopolyRoom $room): void
    {
        if ($room->status !== 'waiting') {
            throw ValidationException::withMessages(['room' => '房间不在等待状态']);
        }
    }

    private function assertCapacity(MonopolyRoom $room): void
    {
        if ($room->players()->count() >= $room->max_players) {
            throw ValidationException::withMessages(['players' => '房间人数已满']);
        }
    }

    private function assertHost(MonopolyRoom $room, int $userId): void
    {
        if ($room->created_by !== $userId) {
            throw ValidationException::withMessages(['host' => '只有房主可以执行该操作']);
        }
    }

    private function resequencePlayers(MonopolyRoom $room): void
    {
        $room->players()->orderBy('turn_order')->get()->values()->each(function (MonopolyPlayer $player, int $index) {
            $player->update(['turn_order' => $index]);
        });
    }
}

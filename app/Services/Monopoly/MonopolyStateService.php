<?php

namespace App\Services\Monopoly;

use App\Models\Monopoly\MonopolyEvent;
use App\Models\Monopoly\MonopolyPlayer;
use App\Models\Monopoly\MonopolyProperty;
use App\Models\Monopoly\MonopolyRoom;

class MonopolyStateService
{
    public function listRooms(int $userId): array
    {
        return $this->rooms($userId);
    }

    public function lobbyRooms(): array
    {
        return $this->rooms();
    }

    public function state(MonopolyRoom $room): array
    {
        $this->syncProperties($room);
        $room = MonopolyRoom::with(['players.properties', 'properties.owner', 'events.player'])->findOrFail($room->id);
        $board = collect(config('monopoly.board'))->keyBy('index');
        $currentPlayer = $room->players->firstWhere('turn_order', $room->current_turn_order);

        return [
            'room' => [
                'id' => $room->id,
                'name' => $room->name,
                'status' => $room->status,
                'max_players' => $room->max_players,
                'max_rounds' => $this->maxRounds($room),
                'current_turn_order' => $room->current_turn_order,
                'round' => $room->round,
                'created_by' => $room->created_by,
            ],
            'current_player_id' => $currentPlayer?->id,
            'board' => array_values(config('monopoly.board')),
            'players' => $room->players->map(fn (MonopolyPlayer $player) => [
                'id' => $player->id,
                'user_id' => $player->user_id,
                'name' => $player->name,
                'type' => $player->type,
                'turn_order' => $player->turn_order,
                'cash' => $player->cash,
                'position' => $player->position,
                'tile_name' => $board[$player->position]['name'] ?? '',
                'is_host' => $player->is_host,
                'is_bankrupt' => $player->is_bankrupt,
                'is_in_jail' => $player->is_in_jail,
                'jail_turns' => $player->jail_turns,
                'jail_cards' => $player->jail_cards,
                'last_roll' => $player->last_roll,
                'houses_built_this_turn' => $player->houses_built_this_turn,
            ])->values()->all(),
            'properties' => $room->properties->map(fn (MonopolyProperty $property) => [
                'id' => $property->id,
                'tile_index' => $property->tile_index,
                'type' => $property->type,
                'name' => $property->name,
                'price' => $property->price,
                'base_rent' => $property->base_rent,
                'current_rent' => $this->rent($room, $property),
                'house_price' => $property->house_price,
                'owner_player_id' => $property->owner_player_id,
                'owner_name' => $property->owner?->name,
                'houses' => $property->houses,
            ])->values()->all(),
            'events' => $room->events->take(40)->reverse()->values()->map(fn (MonopolyEvent $event) => [
                'id' => $event->id,
                'type' => $event->type,
                'message' => $event->message,
                'player_id' => $event->player_id,
                'payload' => $event->payload,
                'created_at' => $event->created_at?->toISOString(),
            ])->all(),
        ];
    }

    public function createProperties(MonopolyRoom $room): void
    {
        foreach (config('monopoly.board') as $tile) {
            if (! in_array($tile['type'], ['city', 'rail', 'air'], true)) {
                continue;
            }

            MonopolyProperty::updateOrCreate(
                [
                    'room_id' => $room->id,
                    'tile_index' => $tile['index'],
                ],
                [
                    'type' => $tile['type'],
                    'name' => $tile['name'],
                    'price' => $tile['price'],
                    'base_rent' => $tile['rent'],
                    'house_price' => $tile['house_price'] ?? 0,
                ]
            );
        }
    }

    public function rent(MonopolyRoom $room, MonopolyProperty $property): int
    {
        if ($property->type === 'city') {
            return (int) floor(($property->price + ($property->house_price * $property->houses)) * 0.2);
        }

        $ownedCount = $room->properties()
            ->where('type', $property->type)
            ->where('owner_player_id', $property->owner_player_id)
            ->count();

        return $property->base_rent * max(1, $ownedCount);
    }

    public function netWorth(MonopolyPlayer $player): int
    {
        $assets = MonopolyProperty::query()
            ->where('owner_player_id', $player->id)
            ->get()
            ->sum(fn (MonopolyProperty $property) => $property->price + ($property->house_price * $property->houses));

        return $player->cash + (int) $assets;
    }

    public function maxRounds(MonopolyRoom $room): int
    {
        return max(1, (int) ($room->config['max_rounds'] ?? config('monopoly.max_rounds')));
    }

    public function formatAmount(int $amount): string
    {
        if ($amount >= 1_000_000) {
            return rtrim(rtrim(number_format($amount / 1_000_000, 1), '0'), '.').'M';
        }

        return (int) floor($amount / 1_000).'K';
    }

    public function tile(int $position): array
    {
        return collect(config('monopoly.board'))->firstWhere('index', $position) ?? config('monopoly.board')[0];
    }

    public function lockRoom(MonopolyRoom $room): MonopolyRoom
    {
        return MonopolyRoom::query()->whereKey($room->id)->lockForUpdate()->firstOrFail();
    }

    public function freshRoom(MonopolyRoom $room): MonopolyRoom
    {
        return MonopolyRoom::findOrFail($room->id);
    }

    public function freshPlayer(MonopolyPlayer $player): MonopolyPlayer
    {
        return MonopolyPlayer::findOrFail($player->id);
    }

    public function freshProperty(MonopolyProperty $property): MonopolyProperty
    {
        return MonopolyProperty::findOrFail($property->id);
    }

    public function playerForUser(MonopolyRoom $room, int $userId): MonopolyPlayer
    {
        return $room->players()->where('user_id', $userId)->firstOrFail();
    }

    private function rooms(?int $userId = null): array
    {
        return MonopolyRoom::query()
            ->withCount('players')
            ->whereIn('status', ['waiting', 'playing'])
            ->latest()
            ->limit(30)
            ->get()
            ->map(fn (MonopolyRoom $room) => [
                'id' => $room->id,
                'name' => $room->name,
                'status' => $room->status,
                'players_count' => $room->players_count,
                'max_players' => $room->max_players,
                'is_member' => $userId !== null && $room->players()->where('user_id', $userId)->exists(),
                'created_at' => $room->created_at?->toISOString(),
            ])
            ->all();
    }

    private function syncProperties(MonopolyRoom $room): void
    {
        $expectedCount = collect(config('monopoly.board'))
            ->whereIn('type', ['city', 'rail', 'air'])
            ->count();

        if ($room->properties()->count() < $expectedCount) {
            $this->createProperties($room);
        }
    }
}

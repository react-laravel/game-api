<?php

namespace App\Services\Monopoly;

use App\Models\Monopoly\MonopolyPlayer;
use App\Models\Monopoly\MonopolyProperty;
use App\Models\Monopoly\MonopolyRoom;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MonopolyService
{
    public function __construct(
        private readonly MonopolyRoomService $rooms,
        private readonly MonopolyStateService $states,
        private readonly MonopolyEventService $events,
        private readonly MonopolySettlementService $settlements,
    ) {}

    public function listRooms(int $userId): array
    {
        return $this->rooms->listRooms($userId);
    }

    public function lobbyRooms(): array
    {
        return $this->rooms->lobbyRooms();
    }

    public function createRoom(User $user, string $name): MonopolyRoom
    {
        return $this->rooms->createRoom($user, $name);
    }

    public function joinRoom(MonopolyRoom $room, User $user): MonopolyPlayer
    {
        return $this->rooms->joinRoom($room, $user);
    }

    public function leaveRoom(MonopolyRoom $room, User $user): void
    {
        DB::transaction(function () use ($room, $user) {
            $room = $this->states->lockRoom($room);
            $player = $this->states->playerForUser($room, $user->id);

            if ($room->status === 'playing') {
                $this->bankrupt($player, "{$player->name} 离开对局并破产");
                $this->advanceTurnIfNeeded($room, $player);
            } else {
                $player->delete();
            }

            $this->events->log($room, $player, 'player.left', "{$player->name} 离开了房间");
            $this->events->broadcast($room, 'player.left', ['player_id' => $player->id]);
            $this->events->broadcastLobby();
        });
    }

    public function addComputer(MonopolyRoom $room, int $userId): MonopolyPlayer
    {
        return $this->rooms->addComputer($room, $userId);
    }

    public function removeComputer(MonopolyRoom $room, int $userId, int $playerId): void
    {
        $this->rooms->removeComputer($room, $userId, $playerId);
    }

    public function start(MonopolyRoom $room, int $userId): MonopolyRoom
    {
        return DB::transaction(function () use ($room, $userId) {
            $room = $this->states->lockRoom($room);
            if ($room->created_by !== $userId) {
                throw ValidationException::withMessages(['host' => '只有房主可以执行该操作']);
            }
            if ($room->status !== 'waiting') {
                throw ValidationException::withMessages(['room' => '房间不在等待状态']);
            }

            if ($room->players()->count() < 2) {
                throw ValidationException::withMessages(['players' => '至少需要 2 名玩家']);
            }

            $room->update([
                'status' => 'playing',
                'current_turn_order' => 0,
                'round' => 1,
                'started_at' => now(),
            ]);
            $this->events->log($room, null, 'game.started', '游戏开始');
            $this->events->broadcast($room, 'state.updated');
            $this->events->broadcastLobby();
            $this->runComputerTurns($room);

            return $room;
        });
    }

    public function roll(MonopolyRoom $room, int $userId): array
    {
        return DB::transaction(function () use ($room, $userId) {
            $room = $this->states->lockRoom($room);
            $player = $this->currentHumanPlayer($room, $userId);
            if ($player->last_roll !== null) {
                throw ValidationException::withMessages(['turn' => '本回合已经掷过骰子']);
            }

            $roll = random_int(1, 6);
            $this->movePlayer($room, $player, $roll);
            $landingTileType = $this->states->tile($player->position)['type'];
            $player->last_roll = $roll;
            $player->save();
            $this->resolveLanding($room, $player);
            $this->events->broadcast($room, 'dice.rolled', ['player_id' => $player->id, 'roll' => $roll]);
            $animations = [$this->rollAnimation($room, $player, $roll)];
            $player = $this->states->freshPlayer($player);
            if ($this->shouldAutoEndAfterRoll($room, $player, $landingTileType)) {
                $this->advanceTurn($room);
                $this->events->broadcast($room, 'turn.advanced');
                $animations = array_merge($animations, $this->runComputerTurns($room));
            }

            return [
                'roll' => $roll,
                'animations' => $animations,
                'state' => $this->states->state($room),
            ];
        });
    }

    public function buy(MonopolyRoom $room, int $userId): MonopolyProperty
    {
        return DB::transaction(function () use ($room, $userId) {
            $room = $this->states->lockRoom($room);
            $player = $this->currentHumanPlayer($room, $userId);
            $property = $this->buyForPlayer($room, $player);
            $this->events->broadcast($room, 'state.updated', ['property_id' => $property->id]);

            return $property;
        });
    }

    public function build(MonopolyRoom $room, int $userId, int $propertyId, int $houses): MonopolyProperty
    {
        return DB::transaction(function () use ($room, $userId, $propertyId, $houses) {
            $room = $this->states->lockRoom($room);
            $player = $this->currentHumanPlayer($room, $userId);
            if ($player->last_roll === null) {
                throw ValidationException::withMessages(['turn' => '请先掷骰子']);
            }

            $property = $room->properties()->where('type', 'city')->findOrFail($propertyId);

            if ($property->owner_player_id !== $player->id) {
                throw ValidationException::withMessages(['property' => '只能给自己的城市盖房']);
            }

            $remainingBuilds = (int) config('monopoly.max_houses_per_build_action') - $player->houses_built_this_turn;
            if ($remainingBuilds <= 0) {
                throw ValidationException::withMessages(['houses' => '本回合最多建造 2 套房']);
            }

            $houses = max(1, min($houses, (int) config('monopoly.max_houses_per_build_action'), $remainingBuilds));
            if ($property->houses + $houses > (int) config('monopoly.max_houses_per_property')) {
                throw ValidationException::withMessages(['houses' => '单个地皮最多 5 套房']);
            }

            $cost = $property->house_price * $houses;
            if ($player->cash < $cost) {
                throw ValidationException::withMessages(['cash' => '现金不足']);
            }

            $player->decrement('cash', $cost);
            $player->increment('houses_built_this_turn', $houses);
            $property->increment('houses', $houses);
            $this->events->log($room, $player, 'property.built', "{$player->name} 在 {$property->name} 建造 {$houses} 套房", [
                'property_id' => $property->id,
                'houses' => $houses,
                'cost' => $cost,
            ]);
            $this->events->broadcast($room, 'state.updated', ['property_id' => $property->id]);

            return $this->states->freshProperty($property);
        });
    }

    public function endTurn(MonopolyRoom $room, int $userId): array
    {
        return DB::transaction(function () use ($room, $userId) {
            $room = $this->states->lockRoom($room);
            $player = $this->currentHumanPlayer($room, $userId);
            if ($player->last_roll === null && ! $player->is_in_jail) {
                throw ValidationException::withMessages(['turn' => '请先掷骰子']);
            }

            $this->advanceTurn($room);
            $this->events->broadcast($room, 'turn.advanced');

            return $this->runComputerTurns($room);
        });
    }

    public function leaveJail(MonopolyRoom $room, int $userId, string $method): array
    {
        return DB::transaction(function () use ($room, $userId, $method) {
            $room = $this->states->lockRoom($room);
            $player = $this->currentHumanPlayer($room, $userId);
            if (! $player->is_in_jail) {
                throw ValidationException::withMessages(['jail' => '玩家不在监狱中']);
            }

            if ($method === 'card' && $player->jail_cards > 0) {
                $player->decrement('jail_cards');
            } elseif ($method === 'pay') {
                $this->charge($player, 100_000, "{$player->name} 支付 100K 出狱");
            } else {
                throw ValidationException::withMessages(['method' => '不能使用该出狱方式']);
            }

            $player->update(['is_in_jail' => false, 'jail_turns' => 0]);
            $this->events->log($room, $player, 'jail.left', "{$player->name} 离开监狱");
            if ($method === 'pay') {
                $this->advanceTurn($room);
                $this->events->broadcast($room, 'turn.advanced');

                return $this->runComputerTurns($room);
            } else {
                $this->events->broadcast($room, 'state.updated');
            }

            return [];
        });
    }

    public function state(MonopolyRoom $room): array
    {
        return $this->states->state($room);
    }

    private function currentHumanPlayer(MonopolyRoom $room, int $userId): MonopolyPlayer
    {
        if ($room->status !== 'playing') {
            throw ValidationException::withMessages(['room' => '游戏尚未开始']);
        }

        $player = $this->states->playerForUser($room, $userId);
        if ($player->turn_order !== $room->current_turn_order || $player->is_bankrupt) {
            throw ValidationException::withMessages(['turn' => '还没有轮到你']);
        }

        return $player;
    }

    private function movePlayer(MonopolyRoom $room, MonopolyPlayer $player, int $steps): void
    {
        if ($player->is_in_jail) {
            $player->increment('jail_turns');
            $this->events->log($room, $player, 'jail.wait', "{$player->name} 在监狱中等待");

            return;
        }

        $boardCount = count(config('monopoly.board'));
        $old = $player->position;
        $new = ($old + $steps) % $boardCount;
        $player->position = $new;

        if ($old + $steps >= $boardCount) {
            $player->cash += (int) config('monopoly.start_bonus');
            $this->events->log($room, $player, 'start.bonus', "{$player->name} 经过起点，获得 2M");
        }

        $player->save();
        $tile = $this->states->tile($new);
        $this->events->log($room, $player, 'player.moved', "{$player->name} 前进 {$steps} 格，到达 {$tile['name']}", [
            'roll' => $steps,
            'position' => $new,
        ]);
    }

    private function resolveLanding(MonopolyRoom $room, MonopolyPlayer $player): void
    {
        if ($player->is_in_jail || $player->is_bankrupt) {
            return;
        }

        $tile = $this->states->tile($player->position);
        match ($tile['type']) {
            'city', 'rail', 'air' => $this->resolvePropertyLanding($room, $player),
            'chance' => $this->drawCard($room, $player, 'chance'),
            'welfare' => $this->drawCard($room, $player, 'welfare'),
            'jail' => $this->sendToJail($room, $player, "{$player->name} 到达监狱参观区"),
            'start' => $this->grantStartLanding($room, $player),
            default => null,
        };
    }

    private function resolvePropertyLanding(MonopolyRoom $room, MonopolyPlayer $player): void
    {
        $property = $room->properties()->where('tile_index', $player->position)->firstOrFail();
        if ($property->owner_player_id === null) {
            $this->events->log($room, $player, 'property.available', "{$property->name} 可以购买，价格 {$property->price}");

            return;
        }

        if ($property->owner_player_id === $player->id) {
            $this->events->log($room, $player, 'property.owned', "{$player->name} 到达自己的 {$property->name}");

            return;
        }

        $owner = MonopolyPlayer::findOrFail($property->owner_player_id);
        $rent = $this->states->rent($room, $property);
        $paid = min($player->cash, $rent);
        $player->decrement('cash', $paid);
        $owner->increment('cash', $paid);
        $this->events->log($room, $player, 'rent.paid', "{$player->name} 向 {$owner->name} 支付 {$paid} 租金", [
            'rent' => $rent,
            'paid' => $paid,
            'property_id' => $property->id,
        ]);

        $player = $this->states->freshPlayer($player);
        if ($paid < $rent || $player->cash <= 0) {
            $this->bankrupt($player, "{$player->name} 现金不足，破产");
        }
    }

    private function buyForPlayer(MonopolyRoom $room, MonopolyPlayer $player): MonopolyProperty
    {
        $property = $room->properties()->where('tile_index', $player->position)->firstOrFail();
        if ($property->owner_player_id !== null) {
            throw ValidationException::withMessages(['property' => '该资产已经被购买']);
        }
        if ($player->cash < $property->price) {
            throw ValidationException::withMessages(['cash' => '现金不足']);
        }

        $player->decrement('cash', $property->price);
        $property->update(['owner_player_id' => $player->id]);
        $this->events->log($room, $player, 'property.bought', "{$player->name} 购买了 {$property->name}", [
            'property_id' => $property->id,
            'price' => $property->price,
        ]);

        return $this->states->freshProperty($property);
    }

    private function drawCard(MonopolyRoom $room, MonopolyPlayer $player, string $deck): void
    {
        $cards = config($deck === 'chance' ? 'monopoly.chance_cards' : 'monopoly.welfare_cards');
        $card = $cards[array_rand($cards)];
        $this->events->log($room, $player, "{$deck}.drawn", "{$player->name} 抽到 {$card['title']}：{$card['description']}", $card);

        match ($card['action']) {
            'cash' => $this->applyCash($room, $player, (int) $card['amount']),
            'move_to' => $this->moveTo($room, $player, (int) $card['position'], (bool) ($card['grant_start_bonus'] ?? false)),
            'move_steps' => $this->movePlayer($room, $player, (int) $card['steps']),
            'jail' => $this->sendToJail($room, $player, "{$player->name} 被送进监狱"),
            'jail_card' => $this->grantJailCard($room, $player),
            default => null,
        };

        if (in_array($card['action'], ['move_to', 'move_steps'], true)) {
            $this->resolveLanding($room, $player->fresh());
        }
    }

    private function applyCash(MonopolyRoom $room, MonopolyPlayer $player, int $amount): void
    {
        if ($amount >= 0) {
            $player->increment('cash', $amount);
            $this->events->log($room, $player, 'cash.received', "{$player->name} 获得 {$amount}", ['amount' => $amount]);

            return;
        }

        $this->charge($player, abs($amount), "{$player->name} 支付 ".abs($amount));
        $player = $this->states->freshPlayer($player);
        if ($player->cash <= 0) {
            $this->bankrupt($player, "{$player->name} 现金不足，破产");
        }
    }

    private function charge(MonopolyPlayer $player, int $amount, string $message): void
    {
        $player->decrement('cash', min($player->cash, $amount));
        $this->events->log($player->room()->firstOrFail(), $player, 'cash.paid', $message, ['amount' => $amount]);
    }

    private function grantJailCard(MonopolyRoom $room, MonopolyPlayer $player): void
    {
        $player->increment('jail_cards');
        $this->events->log($room, $player, 'jail.card.received', "{$player->name} 获得 1 张出狱卡");
    }

    private function moveTo(MonopolyRoom $room, MonopolyPlayer $player, int $position, bool $grantStartBonus): void
    {
        $player->position = $position;
        if ($grantStartBonus) {
            $player->cash += (int) config('monopoly.start_bonus');
        }
        $player->save();
        $this->events->log($room, $player, 'player.moved', "{$player->name} 移动到 {$this->states->tile($position)['name']}");
    }

    private function sendToJail(MonopolyRoom $room, MonopolyPlayer $player, string $message): void
    {
        $player->update([
            'position' => (int) config('monopoly.jail_position'),
            'is_in_jail' => true,
            'jail_turns' => 0,
        ]);
        $this->events->log($room, $player, 'jail.entered', $message);
    }

    private function grantStartLanding(MonopolyRoom $room, MonopolyPlayer $player): void
    {
        $this->events->log($room, $player, 'start.landed', "{$player->name} 到达起点");
    }

    private function shouldAutoEndAfterRoll(MonopolyRoom $room, MonopolyPlayer $player, string $landingTileType): bool
    {
        $room = $this->states->freshRoom($room);
        if ($room->status !== 'playing' || $room->current_turn_order !== $player->turn_order) {
            return false;
        }

        if ($player->is_bankrupt || $player->is_in_jail) {
            return true;
        }

        if (in_array($landingTileType, ['start', 'chance', 'welfare', 'jail'], true)) {
            return true;
        }

        return ! $this->hasPostRollAction($room, $player);
    }

    private function hasPostRollAction(MonopolyRoom $room, MonopolyPlayer $player): bool
    {
        if ($player->last_roll === null || $player->is_bankrupt || $player->is_in_jail) {
            return false;
        }

        $property = $room->properties()->where('tile_index', $player->position)->first();
        if (! $property) {
            return false;
        }

        if ($property->owner_player_id === null) {
            return $player->cash >= $property->price;
        }

        if ($property->owner_player_id !== $player->id || $property->type !== 'city') {
            return false;
        }

        return $property->houses < (int) config('monopoly.max_houses_per_property')
            && $player->houses_built_this_turn < (int) config('monopoly.max_houses_per_build_action')
            && $player->cash >= $property->house_price;
    }

    private function bankrupt(MonopolyPlayer $player, string $message): void
    {
        $player->update(['is_bankrupt' => true, 'cash' => 0]);
        MonopolyProperty::where('owner_player_id', $player->id)->update([
            'owner_player_id' => null,
            'houses' => 0,
        ]);
        $this->events->log($player->room()->firstOrFail(), $player, 'player.bankrupt', $message);
    }

    private function advanceTurnIfNeeded(MonopolyRoom $room, MonopolyPlayer $player): void
    {
        if ($room->current_turn_order === $player->turn_order) {
            $this->advanceTurn($room);
        }
    }

    private function advanceTurn(MonopolyRoom $room): void
    {
        $players = $room->players()->where('is_bankrupt', false)->orderBy('turn_order')->get();
        if ($players->count() <= 1) {
            $winner = $players->first();
            $room->update(['status' => 'finished', 'finished_at' => now()]);
            $this->events->log($room, $winner, 'game.finished', $winner ? "{$winner->name} 获胜" : '游戏结束', [
                'reason' => 'bankruptcy',
                'winner_player_id' => $winner?->id,
            ]);

            return;
        }

        $currentIndex = $players->search(fn (MonopolyPlayer $player) => $player->turn_order === $room->current_turn_order);
        $next = $players[($currentIndex === false ? 0 : $currentIndex + 1) % $players->count()];
        $players->each(fn (MonopolyPlayer $player) => $player->update([
            'last_roll' => null,
            'houses_built_this_turn' => 0,
        ]));
        $room->current_turn_order = $next->turn_order;
        if ($next->turn_order === 0) {
            $room->round++;
        }
        $room->save();

        if ($room->round > $this->states->maxRounds($room)) {
            $this->settlements->finishByNetWorth($room);

            return;
        }

        $this->events->log($room, $next, 'turn.advanced', "轮到 {$next->name}");
    }

    private function runComputerTurns(MonopolyRoom $room): array
    {
        $guard = 0;
        $animations = [];
        while ($guard++ < 20) {
            $room = $this->states->freshRoom($room);
            if ($room->status !== 'playing') {
                return $animations;
            }

            $player = $room->players()->where('turn_order', $room->current_turn_order)->first();
            if (! $player?->isComputer() || $player->is_bankrupt) {
                return $animations;
            }

            if ($player->is_in_jail) {
                if ($player->jail_cards > 0) {
                    $player->decrement('jail_cards');
                    $player->update(['is_in_jail' => false, 'jail_turns' => 0]);
                } elseif ($player->cash > 300_000) {
                    $this->charge($player, 100_000, "{$player->name} 支付 100K 出狱");
                    $player->update(['is_in_jail' => false, 'jail_turns' => 0]);
                }
            }

            $roll = random_int(1, 6);
            $player = $this->states->freshPlayer($player);
            $this->movePlayer($room, $player, $roll);
            $player = $this->states->freshPlayer($player);
            $player->update(['last_roll' => $roll]);
            $this->resolveLanding($room, $player);
            $this->computerBuyOrBuild($room, $this->states->freshPlayer($player));
            $this->events->broadcast($room, 'dice.rolled', ['player_id' => $player->id, 'roll' => $roll]);
            $animations[] = $this->rollAnimation($room, $player, $roll);
            $this->advanceTurn($room);
            $this->events->broadcast($room, 'turn.advanced');
        }

        return $animations;
    }

    private function rollAnimation(MonopolyRoom $room, MonopolyPlayer $player, int $roll): array
    {
        return [
            'player_id' => $player->id,
            'roll' => $roll,
            'state' => $this->states->state($room),
        ];
    }

    private function computerBuyOrBuild(MonopolyRoom $room, MonopolyPlayer $player): void
    {
        if ($player->is_bankrupt) {
            return;
        }

        $property = $room->properties()->where('tile_index', $player->position)->first();
        if ($property && $property->owner_player_id === null && $player->cash - $property->price >= 250_000) {
            $this->buyForPlayer($room, $player);

            return;
        }

        $buildTarget = $player->properties()
            ->where('type', 'city')
            ->where('houses', '<', (int) config('monopoly.max_houses_per_property'))
            ->orderByDesc('base_rent')
            ->first();

        if ($buildTarget && $player->cash - $buildTarget->house_price >= 500_000) {
            $player->decrement('cash', $buildTarget->house_price);
            $buildTarget->increment('houses');
            $this->events->log($room, $player, 'property.built', "{$player->name} 在 {$buildTarget->name} 建造 1 套房");
        }
    }
}

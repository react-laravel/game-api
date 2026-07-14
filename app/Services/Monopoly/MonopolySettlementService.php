<?php

namespace App\Services\Monopoly;

use App\Models\Monopoly\MonopolyPlayer;
use App\Models\Monopoly\MonopolyRoom;

class MonopolySettlementService
{
    public function __construct(
        private readonly MonopolyStateService $states,
        private readonly MonopolyEventService $events,
    ) {}

    public function finishByNetWorth(MonopolyRoom $room): void
    {
        $standings = $room->players()
            ->where('is_bankrupt', false)
            ->orderBy('turn_order')
            ->get()
            ->map(fn (MonopolyPlayer $player) => [
                'player' => $player,
                'net_worth' => $this->states->netWorth($player),
            ])
            ->sort(function (array $left, array $right) {
                /** @var MonopolyPlayer $leftPlayer */
                $leftPlayer = $left['player'];
                /** @var MonopolyPlayer $rightPlayer */
                $rightPlayer = $right['player'];

                return [$right['net_worth'], $rightPlayer->cash] <=> [$left['net_worth'], $leftPlayer->cash];
            })
            ->values();

        $winnerEntry = $standings->first();
        $winner = $winnerEntry['player'] ?? null;
        $winnerNetWorth = (int) ($winnerEntry['net_worth'] ?? 0);
        $room->update(['status' => 'finished', 'finished_at' => now()]);

        $this->events->log(
            $room,
            $winner,
            'game.finished',
            $winner
                ? "达到 {$this->states->maxRounds($room)} 轮，{$winner->name} 以净资产 {$this->states->formatAmount($winnerNetWorth)} 获胜"
                : '达到最大轮数，游戏结束',
            [
                'reason' => 'max_rounds',
                'max_rounds' => $this->states->maxRounds($room),
                'winner_player_id' => $winner?->id,
                'winner_net_worth' => $winner ? $winnerNetWorth : null,
                'standings' => $standings->map(function (array $standing) {
                    /** @var MonopolyPlayer $player */
                    $player = $standing['player'];

                    return [
                        'player_id' => $player->id,
                        'name' => $player->name,
                        'cash' => $player->cash,
                        'net_worth' => (int) $standing['net_worth'],
                    ];
                })->all(),
            ]
        );
    }
}

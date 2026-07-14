<?php

namespace App\Models\Monopoly;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MonopolyRoom extends Model
{
    use HasFactory;

    protected $fillable = [
        'created_by',
        'name',
        'status',
        'max_players',
        'current_turn_order',
        'round',
        'config',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'created_by' => 'integer',
        'max_players' => 'integer',
        'current_turn_order' => 'integer',
        'round' => 'integer',
        'config' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /**
     * @return HasMany<MonopolyPlayer, $this>
     */
    public function players(): HasMany
    {
        return $this->hasMany(MonopolyPlayer::class, 'room_id')->orderBy('turn_order');
    }

    /**
     * @return HasMany<MonopolyProperty, $this>
     */
    public function properties(): HasMany
    {
        return $this->hasMany(MonopolyProperty::class, 'room_id')->orderBy('tile_index');
    }

    /**
     * @return HasMany<MonopolyEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(MonopolyEvent::class, 'room_id')->latest();
    }

    /**
     * @return HasMany<MonopolyPlayer, $this>
     */
    public function activePlayers(): HasMany
    {
        return $this->players()->where('is_bankrupt', false);
    }
}

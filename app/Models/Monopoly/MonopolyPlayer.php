<?php

namespace App\Models\Monopoly;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MonopolyPlayer extends Model
{
    use HasFactory;

    protected $fillable = [
        'room_id',
        'user_id',
        'name',
        'type',
        'turn_order',
        'cash',
        'position',
        'is_host',
        'is_bankrupt',
        'is_in_jail',
        'jail_turns',
        'jail_cards',
        'last_roll',
        'houses_built_this_turn',
    ];

    protected $casts = [
        'room_id' => 'integer',
        'user_id' => 'integer',
        'turn_order' => 'integer',
        'cash' => 'integer',
        'position' => 'integer',
        'is_host' => 'boolean',
        'is_bankrupt' => 'boolean',
        'is_in_jail' => 'boolean',
        'jail_turns' => 'integer',
        'jail_cards' => 'integer',
        'last_roll' => 'integer',
        'houses_built_this_turn' => 'integer',
    ];

    /**
     * @return BelongsTo<MonopolyRoom, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(MonopolyRoom::class, 'room_id');
    }

    /**
     * @return HasMany<MonopolyProperty, $this>
     */
    public function properties(): HasMany
    {
        return $this->hasMany(MonopolyProperty::class, 'owner_player_id');
    }

    public function isComputer(): bool
    {
        return $this->type === 'computer';
    }
}

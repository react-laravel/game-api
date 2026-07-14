<?php

namespace App\Models\Monopoly;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonopolyProperty extends Model
{
    use HasFactory;

    protected $fillable = [
        'room_id',
        'tile_index',
        'type',
        'name',
        'price',
        'base_rent',
        'house_price',
        'owner_player_id',
        'houses',
    ];

    protected $casts = [
        'room_id' => 'integer',
        'tile_index' => 'integer',
        'price' => 'integer',
        'base_rent' => 'integer',
        'house_price' => 'integer',
        'owner_player_id' => 'integer',
        'houses' => 'integer',
    ];

    /**
     * @return BelongsTo<MonopolyRoom, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(MonopolyRoom::class, 'room_id');
    }

    /**
     * @return BelongsTo<MonopolyPlayer, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(MonopolyPlayer::class, 'owner_player_id');
    }
}

<?php

namespace App\Models\Monopoly;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonopolyEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'room_id',
        'player_id',
        'type',
        'message',
        'payload',
    ];

    protected $casts = [
        'room_id' => 'integer',
        'player_id' => 'integer',
        'payload' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
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
    public function player(): BelongsTo
    {
        return $this->belongsTo(MonopolyPlayer::class, 'player_id');
    }
}

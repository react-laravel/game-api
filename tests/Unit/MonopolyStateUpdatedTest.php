<?php

namespace Tests\Unit;

use App\Events\Monopoly\MonopolyStateUpdated;
use Illuminate\Broadcasting\PrivateChannel;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MonopolyStateUpdatedTest extends TestCase
{
    #[Test]
    public function room_state_is_broadcast_on_a_private_channel(): void
    {
        $channel = (new MonopolyStateUpdated(42, 'state.updated', []))->broadcastOn()[0];

        $this->assertInstanceOf(PrivateChannel::class, $channel);
        $this->assertSame('private-monopoly.room.42', $channel->name);
    }
}

<?php

namespace Tests\Feature\Controllers\Monopoly;

use App\Events\Monopoly\MonopolyLobbyUpdated;
use App\Events\Monopoly\MonopolyStateUpdated;
use App\Models\Monopoly\MonopolyPlayer;
use App\Models\Monopoly\MonopolyRoom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MonopolyControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $host;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
        $this->host = $this->identity(1, 'Host');
        $this->actingAsIdentity($this->host);
    }

    #[Test]
    public function create_room_initializes_host_player_and_properties(): void
    {
        $response = $this->postJson('/api/monopoly/rooms', ['name' => '周末大富翁']);

        $response->assertCreated()
            ->assertJsonPath('data.state.room.name', '周末大富翁')
            ->assertJsonPath('data.state.room.max_rounds', 30)
            ->assertJsonPath('data.state.players.0.cash', 8000000);

        $this->assertDatabaseHas('monopoly_rooms', ['name' => '周末大富翁']);
        $this->assertDatabaseHas('monopoly_players', [
            'user_id' => $this->host->id,
            'is_host' => true,
            'cash' => 8000000,
        ]);
        $this->assertDatabaseHas('monopoly_properties', ['name' => '罗马']);
        Event::assertDispatched(MonopolyStateUpdated::class);
        Event::assertDispatched(MonopolyLobbyUpdated::class);
    }

    #[Test]
    public function join_add_computer_and_start_game(): void
    {
        $roomId = $this->postJson('/api/monopoly/rooms', ['name' => '测试房'])->json('data.room.id');
        $guest = $this->identity(2, 'Guest');

        $this->actingAsIdentity($guest);
        $this->postJson("/api/monopoly/rooms/{$roomId}/join")->assertOk()
            ->assertJsonPath('data.player.name', 'Guest');

        $this->actingAsIdentity($this->host);
        $this->postJson("/api/monopoly/rooms/{$roomId}/computers")->assertOk()
            ->assertJsonPath('data.player.type', 'computer');

        $this->postJson("/api/monopoly/rooms/{$roomId}/start")->assertOk()
            ->assertJsonPath('data.state.room.status', 'playing');

        $this->assertDatabaseHas('monopoly_rooms', [
            'id' => $roomId,
            'status' => 'playing',
        ]);
        $this->assertDatabaseCount('monopoly_players', 3);
    }

    #[Test]
    public function non_member_cannot_read_room_state(): void
    {
        $roomId = $this->postJson('/api/monopoly/rooms', ['name' => '私人房'])->json('data.room.id');
        $this->actingAsIdentity($this->identity(2, 'Guest'));

        $this->getJson("/api/monopoly/rooms/{$roomId}")->assertForbidden();
    }

    #[Test]
    public function private_room_channel_only_authorizes_members(): void
    {
        $roomId = $this->postJson('/api/monopoly/rooms', ['name' => '频道测试'])->json('data.room.id');
        $authorizer = Broadcast::getChannels()->get('monopoly.room.{roomId}');

        $this->assertIsCallable($authorizer);
        $this->assertSame([
            'id' => 1,
            'name' => 'Host',
            'player_id' => 1,
        ], $authorizer($this->host, $roomId));
        $this->assertFalse($authorizer($this->identity(2, 'Guest'), $roomId));

        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'game-key',
            'broadcasting.connections.reverb.secret' => 'game-secret',
            'broadcasting.connections.reverb.app_id' => 'game',
            'broadcasting.connections.reverb.options.host' => '127.0.0.1',
            'broadcasting.connections.reverb.options.port' => 8082,
            'broadcasting.connections.reverb.options.scheme' => 'http',
            'broadcasting.connections.reverb.options.useTLS' => false,
        ]);
        Broadcast::setDefaultDriver('reverb');
        Broadcast::purge('reverb');
        require base_path('routes/channels.php');

        $this->postJson('/api/broadcasting/auth', [
            'channel_name' => "private-monopoly.room.{$roomId}",
            'socket_id' => '123.456',
        ])->assertOk()->assertJsonStructure(['auth']);

        $this->actingAsIdentity($this->identity(2, 'Guest'));
        $this->postJson('/api/broadcasting/auth', [
            'channel_name' => "private-monopoly.room.{$roomId}",
            'socket_id' => '123.456',
        ])->assertForbidden();
    }

    #[Test]
    public function roll_pays_start_bonus_when_passing_start(): void
    {
        $roomId = $this->postJson('/api/monopoly/rooms', ['name' => '起点测试'])->json('data.room.id');
        $this->postJson("/api/monopoly/rooms/{$roomId}/computers")->assertOk();
        $this->postJson("/api/monopoly/rooms/{$roomId}/start")->assertOk();

        $player = MonopolyPlayer::where('room_id', $roomId)->where('user_id', $this->host->id)->firstOrFail();
        $player->update(['position' => 27]);

        $this->postJson("/api/monopoly/rooms/{$roomId}/roll")->assertOk();

        $this->assertGreaterThanOrEqual(10_000_000, $player->fresh()->cash);
        $this->assertDatabaseHas('monopoly_events', [
            'room_id' => $roomId,
            'type' => 'start.bonus',
        ]);
    }

    #[Test]
    public function roll_auto_ends_turn_when_landing_has_no_follow_up_action(): void
    {
        config([
            'monopoly.board' => collect(range(0, 6))->map(fn (int $index) => [
                'index' => $index,
                'type' => 'start',
                'name' => $index === 0 ? '起点' : "空地 {$index}",
            ])->all(),
        ]);

        $roomId = $this->postJson('/api/monopoly/rooms', ['name' => '自动结束测试'])->json('data.room.id');
        $guest = $this->identity(2, 'Guest');

        $this->actingAsIdentity($guest);
        $this->postJson("/api/monopoly/rooms/{$roomId}/join")->assertOk();

        $this->actingAsIdentity($this->host);
        $this->postJson("/api/monopoly/rooms/{$roomId}/start")->assertOk();

        $guestPlayer = MonopolyPlayer::where('room_id', $roomId)->where('user_id', $guest->id)->firstOrFail();

        $this->postJson("/api/monopoly/rooms/{$roomId}/roll")
            ->assertOk()
            ->assertJsonCount(1, 'data.animations')
            ->assertJsonStructure([
                'data' => [
                    'animations' => [
                        '*' => ['player_id', 'roll', 'state'],
                    ],
                ],
            ])
            ->assertJsonPath('data.state.current_player_id', $guestPlayer->id);
    }

    #[Test]
    public function buy_and_build_respects_house_limits(): void
    {
        $roomId = $this->postJson('/api/monopoly/rooms', ['name' => '建房测试'])->json('data.room.id');
        $this->postJson("/api/monopoly/rooms/{$roomId}/computers")->assertOk();
        $this->postJson("/api/monopoly/rooms/{$roomId}/start")->assertOk();

        $player = MonopolyPlayer::where('room_id', $roomId)->where('user_id', $this->host->id)->firstOrFail();
        $player->update(['position' => 1, 'cash' => 5_000_000, 'last_roll' => 1]);

        $buy = $this->postJson("/api/monopoly/rooms/{$roomId}/buy")->assertOk();
        $propertyId = $buy->json('data.property.id');
        $buy->assertJsonPath('data.property.price', 200000)
            ->assertJsonPath('data.property.house_price', 500000);

        $this->postJson("/api/monopoly/rooms/{$roomId}/build", [
            'property_id' => $propertyId,
            'houses' => 2,
        ])->assertOk()
            ->assertJsonPath('data.property.houses', 2)
            ->assertJsonPath('data.state.players.0.houses_built_this_turn', 2)
            ->assertJsonPath('data.state.properties.0.current_rent', 240000);

        $this->postJson("/api/monopoly/rooms/{$roomId}/build", [
            'property_id' => $propertyId,
            'houses' => 2,
        ])->assertUnprocessable();

        MonopolyPlayer::where('room_id', $roomId)->where('user_id', $this->host->id)->firstOrFail()
            ->update(['houses_built_this_turn' => 0]);

        $this->postJson("/api/monopoly/rooms/{$roomId}/build", [
            'property_id' => $propertyId,
            'houses' => 2,
        ])->assertOk()->assertJsonPath('data.property.houses', 4);
    }

    #[Test]
    public function computer_turn_auto_advances_to_next_human(): void
    {
        $roomId = $this->postJson('/api/monopoly/rooms', ['name' => '人机测试'])->json('data.room.id');
        $guest = $this->identity(2, 'Guest');

        $this->postJson("/api/monopoly/rooms/{$roomId}/computers")->assertOk();
        $this->actingAsIdentity($guest);
        $this->postJson("/api/monopoly/rooms/{$roomId}/join")->assertOk();

        $this->actingAsIdentity($this->host);
        $this->postJson("/api/monopoly/rooms/{$roomId}/start")->assertOk();
        $animationResponse = $this->postJson("/api/monopoly/rooms/{$roomId}/roll")->assertOk();

        $hostPlayer = MonopolyPlayer::where('room_id', $roomId)->where('user_id', $this->host->id)->firstOrFail();
        $room = MonopolyRoom::findOrFail($roomId);
        if ($room->current_turn_order === $hostPlayer->turn_order) {
            $animationResponse = $this->postJson("/api/monopoly/rooms/{$roomId}/end-turn")->assertOk();
        }

        $this->assertNotEmpty($animationResponse->json('data.animations'));
        $animationResponse->assertJsonStructure([
            'data' => [
                'animations' => [
                    '*' => ['player_id', 'roll', 'state'],
                ],
            ],
        ]);
        Event::assertDispatched(
            MonopolyStateUpdated::class,
            fn (MonopolyStateUpdated $event) => $event->type === 'dice.rolled'
                && MonopolyPlayer::find($event->payload['player_id'] ?? null)?->type === 'computer'
        );

        $room = MonopolyRoom::findOrFail($roomId);
        $guestPlayer = MonopolyPlayer::where('room_id', $roomId)->where('user_id', $guest->id)->firstOrFail();
        $this->assertSame($guestPlayer->turn_order, $room->current_turn_order);
    }

    #[Test]
    public function paying_to_leave_jail_ends_the_turn(): void
    {
        $roomId = $this->postJson('/api/monopoly/rooms', ['name' => '出狱测试'])->json('data.room.id');
        $guest = $this->identity(2, 'Guest');

        $this->actingAsIdentity($guest);
        $this->postJson("/api/monopoly/rooms/{$roomId}/join")->assertOk();

        $this->actingAsIdentity($this->host);
        $this->postJson("/api/monopoly/rooms/{$roomId}/start")->assertOk();

        $hostPlayer = MonopolyPlayer::where('room_id', $roomId)->where('user_id', $this->host->id)->firstOrFail();
        $guestPlayer = MonopolyPlayer::where('room_id', $roomId)->where('user_id', $guest->id)->firstOrFail();
        $hostPlayer->update(['is_in_jail' => true, 'cash' => 8_000_000]);

        $this->postJson("/api/monopoly/rooms/{$roomId}/leave-jail", ['method' => 'pay'])
            ->assertOk()
            ->assertJsonPath('data.state.current_player_id', $guestPlayer->id);

        $this->assertDatabaseHas('monopoly_players', [
            'id' => $hostPlayer->id,
            'is_in_jail' => false,
            'cash' => 7_900_000,
        ]);

        $room = MonopolyRoom::findOrFail($roomId);
        $this->assertSame($guestPlayer->turn_order, $room->current_turn_order);
    }

    #[Test]
    public function game_finishes_by_net_worth_after_max_rounds(): void
    {
        config(['monopoly.max_rounds' => 1]);

        $roomId = $this->postJson('/api/monopoly/rooms', ['name' => '限时测试'])->json('data.room.id');
        $this->postJson("/api/monopoly/rooms/{$roomId}/computers")->assertOk();
        $this->postJson("/api/monopoly/rooms/{$roomId}/start")->assertOk()
            ->assertJsonPath('data.state.room.max_rounds', 1);

        $this->postJson("/api/monopoly/rooms/{$roomId}/roll")->assertOk();
        $room = MonopolyRoom::findOrFail($roomId);
        if ($room->status !== 'finished') {
            $this->postJson("/api/monopoly/rooms/{$roomId}/end-turn")->assertOk()
                ->assertJsonPath('data.state.room.status', 'finished');
        }

        $room = MonopolyRoom::findOrFail($roomId);
        $this->assertSame('finished', $room->status);
        $this->assertDatabaseHas('monopoly_events', [
            'room_id' => $roomId,
            'type' => 'game.finished',
        ]);
    }

    private function identity(int $id, string $name): User
    {
        return new User([
            'id' => $id,
            'name' => $name,
            'email' => strtolower($name).'@example.test',
            'is_admin' => false,
            'permissions' => [],
        ]);
    }

    private function actingAsIdentity(User $user): void
    {
        $this->withSession(['game_identity' => $user->toArray()]);
    }
}

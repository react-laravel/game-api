<?php

namespace App\Http\Controllers\Api\Monopoly;

use App\Http\Controllers\Controller;
use App\Models\Monopoly\MonopolyRoom;
use App\Services\Monopoly\MonopolyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MonopolyController extends Controller
{
    public function __construct(private readonly MonopolyService $service) {}

    public function index(): JsonResponse
    {
        return $this->success([
            'rooms' => $this->service->listRooms($this->getCurrentUserId()),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:40'],
        ]);

        $room = $this->service->createRoom($request->user(), $validated['name']);

        return $this->success([
            'room' => $room,
            'state' => $this->service->state($room),
        ], 'Room created', 201);
    }

    public function show(MonopolyRoom $room): JsonResponse
    {
        $this->authorizeRoomMember($room);

        return $this->success(['state' => $this->service->state($room)]);
    }

    public function join(MonopolyRoom $room, Request $request): JsonResponse
    {
        $player = $this->service->joinRoom($room, $request->user());

        return $this->success([
            'player' => $player,
            'state' => $this->service->state($room),
        ]);
    }

    public function leave(MonopolyRoom $room, Request $request): JsonResponse
    {
        $this->service->leaveRoom($room, $request->user());

        return $this->success(null, 'Left room');
    }

    public function addComputer(MonopolyRoom $room): JsonResponse
    {
        $player = $this->service->addComputer($room, $this->getCurrentUserId());

        return $this->success([
            'player' => $player,
            'state' => $this->service->state($room),
        ]);
    }

    public function removeComputer(MonopolyRoom $room, int $playerId): JsonResponse
    {
        $this->service->removeComputer($room, $this->getCurrentUserId(), $playerId);

        return $this->success(['state' => $this->service->state($room)]);
    }

    public function start(MonopolyRoom $room): JsonResponse
    {
        $this->service->start($room, $this->getCurrentUserId());

        return $this->success(['state' => $this->service->state($room)]);
    }

    public function roll(MonopolyRoom $room): JsonResponse
    {
        return $this->success($this->service->roll($room, $this->getCurrentUserId()));
    }

    public function buy(MonopolyRoom $room): JsonResponse
    {
        $property = $this->service->buy($room, $this->getCurrentUserId());

        return $this->success([
            'property' => $property,
            'state' => $this->service->state($room),
        ]);
    }

    public function build(MonopolyRoom $room, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'property_id' => ['required', 'integer', 'exists:monopoly_properties,id'],
            'houses' => ['required', 'integer', 'min:1', 'max:2'],
        ]);

        $property = $this->service->build(
            $room,
            $this->getCurrentUserId(),
            (int) $validated['property_id'],
            (int) $validated['houses']
        );

        return $this->success([
            'property' => $property,
            'state' => $this->service->state($room),
        ]);
    }

    public function endTurn(MonopolyRoom $room): JsonResponse
    {
        $animations = $this->service->endTurn($room, $this->getCurrentUserId());

        return $this->success([
            'animations' => $animations,
            'state' => $this->service->state($room),
        ]);
    }

    public function leaveJail(MonopolyRoom $room, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'method' => ['required', 'in:pay,card'],
        ]);

        $animations = $this->service->leaveJail($room, $this->getCurrentUserId(), $validated['method']);

        return $this->success([
            'animations' => $animations,
            'state' => $this->service->state($room),
        ]);
    }

    private function authorizeRoomMember(MonopolyRoom $room): void
    {
        abort_unless(
            $room->players()->where('user_id', $this->getCurrentUserId())->exists(),
            403,
            'You are not in this room'
        );
    }
}

<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\IdentitySsoClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class SsoController extends Controller
{
    public function csrf(Request $request): JsonResponse
    {
        return $this->success([
            'token' => $request->session()->token(),
        ]);
    }

    public function exchange(Request $request, IdentitySsoClient $client): JsonResponse
    {
        $validated = $request->validate([
            'ticket' => ['required', 'string', 'min:32', 'max:512'],
        ]);

        try {
            $identity = $client->exchange($validated['ticket']);
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), null, 401);
        }

        $request->session()->regenerate();
        $request->session()->put('game_identity', $identity);
        $request->session()->put('game_identity_refreshed_at', now()->timestamp);

        return $this->success($identity, '登录成功');
    }

    public function user(Request $request): JsonResponse
    {
        return $this->success($request->user()->toArray());
    }

    public function logout(Request $request): JsonResponse
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->success(null, '已退出游戏中心');
    }
}

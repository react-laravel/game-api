<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SsoAuthenticationTest extends TestCase
{
    #[Test]
    public function it_returns_the_raw_session_csrf_token_without_authentication(): void
    {
        $response = $this->getJson('/api/auth/csrf')
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['token'],
            ]);

        $token = $response->json('data.token');

        $this->assertIsString($token);
        $this->assertNotSame('', $token);
        $response->assertSessionHas('_token', $token);
    }

    #[Test]
    public function it_exchanges_a_game_ticket_for_an_independent_session(): void
    {
        config([
            'services.identity.url' => 'https://next-api.example.test',
            'services.identity.client_secret' => 'game-secret',
        ]);
        Http::fake([
            'https://next-api.example.test/api/auth/sso/exchange' => Http::response([
                'data' => [
                    'identity' => [
                        'id' => 42,
                        'name' => 'Doge',
                        'email' => 'doge@example.test',
                        'is_admin' => false,
                        'permissions' => [],
                    ],
                ],
            ]),
        ]);

        $this->postJson('/api/auth/exchange', ['ticket' => str_repeat('a', 32)])
            ->assertOk()
            ->assertJsonPath('data.id', 42)
            ->assertSessionHas('game_identity.id', 42);

        Http::assertSent(fn ($request): bool => $request->hasHeader('X-SSO-Client-Secret', 'game-secret')
            && $request['client'] === 'game'
            && $request['ticket'] === str_repeat('a', 32));

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.name', 'Doge');
    }

    #[Test]
    public function protected_routes_reject_requests_without_a_game_session(): void
    {
        $this->getJson('/api/monopoly/rooms')
            ->assertUnauthorized()
            ->assertJsonPath('message', '请先通过 DogeOW 统一登录');
    }
}

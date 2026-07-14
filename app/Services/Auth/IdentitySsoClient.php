<?php

namespace App\Services\Auth;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class IdentitySsoClient
{
    /**
     * @return array{id:int,name:string,email?:string|null,is_admin:bool,permissions:array<int,string>}
     */
    public function exchange(string $ticket): array
    {
        $endpoint = rtrim((string) config('services.identity.url'), '/').'/api/auth/sso/exchange';
        $secret = (string) config('services.identity.client_secret');

        if ($secret === '') {
            throw new RuntimeException('SSO client secret is not configured.');
        }

        try {
            $response = Http::asJson()
                ->acceptJson()
                ->withHeaders(['X-SSO-Client-Secret' => $secret])
                ->connectTimeout(3)
                ->timeout(8)
                ->post($endpoint, [
                    'client' => 'game',
                    'ticket' => $ticket,
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('统一登录服务暂时不可用。', 0, $exception);
        }

        if (! $response->successful()) {
            throw new RuntimeException(
                (string) ($response->json('message') ?: '登录票据无效或已过期。')
            );
        }

        $identity = $response->json('data.identity') ?? $response->json('identity');
        if (! is_array($identity) || ! isset($identity['id'], $identity['name'])) {
            throw new RuntimeException('统一登录服务返回了无效身份。');
        }

        return [
            'id' => (int) $identity['id'],
            'name' => (string) $identity['name'],
            'email' => isset($identity['email']) ? (string) $identity['email'] : null,
            'is_admin' => (bool) ($identity['is_admin'] ?? false),
            'permissions' => array_values(array_map('strval', $identity['permissions'] ?? [])),
        ];
    }
}

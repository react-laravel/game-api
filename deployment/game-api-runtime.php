<?php

declare(strict_types=1);

function gameRuntimePaths(): array
{
    return [
        'LARAVEL_STORAGE_PATH' => '/var/www/game-api/shared/storage',
        'APP_CONFIG_CACHE' => '/var/lib/dogeow-tests-game-api/cache/config.php',
        'APP_ROUTES_CACHE' => '/var/lib/dogeow-tests-game-api/cache/routes.php',
        'APP_EVENTS_CACHE' => '/var/lib/dogeow-tests-game-api/cache/events.php',
        'APP_PACKAGES_CACHE' => '/var/lib/dogeow-tests-game-api/cache/packages.php',
        'APP_SERVICES_CACHE' => '/var/lib/dogeow-tests-game-api/cache/services.php',
    ];
}

function gameApplication(): Illuminate\Foundation\Application
{
    $user = posix_getpwnam('dogeow-test-game-api');
    if (!$user || posix_geteuid() !== $user['uid']) {
        throw new RuntimeException('Run only as the dedicated API runtime user.');
    }
    foreach (gameRuntimePaths() as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
    $root = realpath('/var/www/game-api/current');
    if (!$root || !str_starts_with($root, '/var/www/game-api/releases/')) {
        throw new RuntimeException('The current API release is unavailable.');
    }
    if (!defined('LARAVEL_START')) {
        define('LARAVEL_START', microtime(true));
    }
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->useEnvironmentPath('/var/www/game-api/shared');
    $app->loadEnvironmentFrom('.env');

    return $app;
}

<?php

declare(strict_types=1);

require __DIR__.'/game-api-runtime.php';

try {
    umask(0077);
    $user = posix_getpwnam('dogeow-test-game-api');
    if (!$user || posix_geteuid() !== $user['uid']) {
        throw new RuntimeException('Runtime identity mismatch.');
    }
    $directory = '/var/lib/dogeow-tests-game-api/cache';
    $info = lstat($directory);
    if (!$info || !is_dir($directory) || is_link($directory)
        || $info['uid'] !== $user['uid'] || ($info['mode'] & 0077) !== 0) {
        throw new RuntimeException('Private runtime cache permissions are unsafe.');
    }
    $lock = fopen($directory.'/prepare.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Runtime preparation is already running.');
    }
    // A failed re-prepare of the same release must not leave a previous success
    // marker authorizing console jobs to use incomplete private caches.
    $marker = $directory.'/prepared-release.json';
    if (file_exists($marker) || is_link($marker)) {
        $entry = lstat($marker);
        if (!$entry || !is_file($marker) || is_link($marker) || $entry['uid'] !== $user['uid']
            || !unlink($marker)) {
            throw new RuntimeException('Prepared-release marker invalidation failed.');
        }
    }
    foreach (gameRuntimePaths() as $key => $file) {
        if ($key === 'LARAVEL_STORAGE_PATH' || !file_exists($file)) {
            continue;
        }
        $entry = lstat($file);
        if (!$entry || !is_file($file) || is_link($file) || $entry['uid'] !== $user['uid']) {
            throw new RuntimeException('Unexpected private cache entry.');
        }
        if (!unlink($file)) {
            throw new RuntimeException('Private cache reset failed.');
        }
    }
    $app = gameApplication();
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
    $migrator = $app->make('migrator');
    $repository = $migrator->getRepository();
    if (!$repository->repositoryExists()) {
        throw new RuntimeException('The database migration ledger is missing.');
    }
    $files = $migrator->getMigrationFiles(array_merge($migrator->paths(), [$app->databasePath('migrations')]));
    $pending = array_diff(array_keys($files), $repository->getRan());
    if ($pending !== []) {
        fwrite(STDERR, 'Game startup refused: administrator schema maintenance required ('.count($pending)." pending).\n");
        exit(65);
    }
    foreach (['config:cache', 'route:cache', 'event:cache', 'view:cache'] as $command) {
        $output = new Symfony\Component\Console\Output\BufferedOutput();
        if ($kernel->call($command, [], $output) !== 0) {
            throw new RuntimeException('Runtime cache generation failed.');
        }
    }
    foreach (gameRuntimePaths() as $key => $file) {
        if ($key !== 'LARAVEL_STORAGE_PATH' && is_file($file)) {
            chmod($file, 0600);
        }
    }
    $temporary = $marker.'.new';
    if (is_link($marker) || is_link($temporary)) {
        throw new RuntimeException('Unsafe prepared-release marker.');
    }
    if (file_put_contents($temporary, json_encode(['release' => realpath('/var/www/game-api/current')], JSON_THROW_ON_ERROR), LOCK_EX) === false
        || !chmod($temporary, 0600) || !rename($temporary, $marker)) {
        throw new RuntimeException('Prepared-release marker write failed.');
    }
    fwrite(STDOUT, "Game runtime preparation passed; schema unchanged and private cache owned by runtime.\n");
} catch (Throwable $error) {
    fwrite(STDERR, "Game runtime preparation failed; inspect the protected application state.\n");
    exit(1);
}

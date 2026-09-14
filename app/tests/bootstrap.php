<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// Fresh schema once per run, built by the migrations; DAMA rolls back each test.
$console = escapeshellarg(dirname(__DIR__).'/bin/console');
foreach (['doctrine:schema:drop --full-database --force', 'doctrine:migrations:migrate --no-interaction'] as $command) {
    passthru(\sprintf('%s %s %s --env=test --quiet', escapeshellarg(PHP_BINARY), $console, $command), $exitCode);
    if (0 !== $exitCode) {
        throw new RuntimeException(\sprintf('Test database setup failed: "%s" exited with %d.', $command, $exitCode));
    }
}

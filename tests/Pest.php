<?php

use AdaiasMagdiel\Erlenmeyer\App;
use AdaiasMagdiel\Erlenmeyer\Response;
use AdaiasMagdiel\Erlenmeyer\Testing\ErlenClient;

// Tests always run against the "testing" DB connection (DB_HOST_TEST, etc. in
// .env), regardless of DB_MODE in .env — Dotenv::createImmutable() (used by
// bootstrap.php) never overwrites a variable that's already set, so this wins.
putenv('DB_MODE=testing');
$_ENV['DB_MODE'] = 'testing';
putenv('ENV=testing');
$_ENV['ENV'] = 'testing';

// Deterministic key so Crypto-backed tests (SMTP/API-key encryption) don't
// depend on whatever APP_KEY (if any) is set in the developer's own .env.
putenv('APP_KEY=' . base64_encode(str_repeat('t', 32)));
$_ENV['APP_KEY'] = base64_encode(str_repeat('t', 32));

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/Support/helpers.php';

/**
 * Boots a fresh App instance with all routes registered, mirroring index.php.
 * Fresh per call so tests don't leak router/middleware state into each other.
 */
function apiApp(): App
{
    $app = new App();
    require __DIR__ . '/../routes/index.php';

    return $app;
}

/** An HTTP test client bound to a freshly booted app. */
function api(): ErlenClient
{
    return new ErlenClient(apiApp());
}

/** @return array<string, mixed> */
function json(Response $response): array
{
    return $response->getJson() ?? [];
}

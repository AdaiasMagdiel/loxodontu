<?php

use App\Controllers\Site;

require __DIR__ . '/error_handler.php';

// --- ROUTES

$app->get('/', [Site::class, 'index']);
$app->get('/dashboard', [Site::class, 'dashboard']);

// group() nests routes under a shared prefix (and, optionally, middlewares
// applied to all of them -- $app->group('/api', function () use ($app) {...},
// [$authMiddleware])). Everything registered inside the callback below is
// automatically prefixed with '/api', so this becomes GET /api/health.
$app->group('/api', function () use ($app) {
    $app->get('/health', function ($req, $res) {
        return $res->withJson(['status' => 'ok']);
    });
});

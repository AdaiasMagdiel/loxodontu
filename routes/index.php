<?php

use App\Controllers\Rest;
use App\Controllers\Site;

require __DIR__ . '/error_handler.php';

// --- ROUTES

$app->get('/', [Site::class, 'index']);
$app->get('/dashboard', [Site::class, 'dashboard']);

$app->group('/api', function () use ($app) {
    $app->get('/health', function ($req, $res) {
        return $res->withJson(['status' => 'ok']);
    });

    $app->group('/v1', function () use ($app) {
        $app->any('/[project_id]/rest/[table]', [Rest::class, 'dispatch']);
        $app->any('/[project_id]/rest/[table]/[id]', [Rest::class, 'dispatch']);
    });
});

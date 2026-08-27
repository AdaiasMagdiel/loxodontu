<?php

use App\Controllers\Auth;
use App\Controllers\Keys;
use App\Controllers\Projects;
use App\Controllers\Rest;
use App\Controllers\Site;
use App\Middleware\PlatformAuth;

require __DIR__ . '/error_handler.php';

// --- ROUTES

$app->get('/', [Site::class, 'index']);
$app->get('/dashboard', [Site::class, 'dashboard']);

$app->group('/api', function () use ($app) {
    $app->get('/health', function ($req, $res) {
        return $res->withJson(['status' => 'ok']);
    });

    $app->group('/v1', function () use ($app) {
        // Auth
        $app->post('/auth/register', [Auth::class, 'register']);
        $app->post('/auth/login', [Auth::class, 'login']);
        $app->post('/auth/logout', [Auth::class, 'logout'], [PlatformAuth::class, 'handle']);

        // Projects
        $app->get('/projects', [Projects::class, 'index'], [PlatformAuth::class, 'handle']);
        $app->post('/projects', [Projects::class, 'store'], [PlatformAuth::class, 'handle']);
        $app->get('/projects/[project_id]', [Projects::class, 'show'], [PlatformAuth::class, 'handle']);
        $app->delete('/projects/[project_id]', [Projects::class, 'destroy'], [PlatformAuth::class, 'handle']);

        // Tables
        $app->get('/projects/[project_id]/tables', [\App\Controllers\Tables::class, 'index'], [PlatformAuth::class, 'handle']);
        $app->post('/projects/[project_id]/tables', [\App\Controllers\Tables::class, 'store'], [PlatformAuth::class, 'handle']);
        $app->delete('/projects/[project_id]/tables/[table_id]', [\App\Controllers\Tables::class, 'destroy'], [PlatformAuth::class, 'handle']);

        // API Keys
        $app->get('/projects/[project_id]/keys', [Keys::class, 'index'], [PlatformAuth::class, 'handle']);
        $app->post('/projects/[project_id]/keys', [Keys::class, 'store'], [PlatformAuth::class, 'handle']);
        $app->delete('/projects/[project_id]/keys/[key_id]', [Keys::class, 'destroy'], [PlatformAuth::class, 'handle']);

        // REST passthrough
        $app->any('/[project_id]/rest/[table]', [Rest::class, 'dispatch']);
        $app->any('/[project_id]/rest/[table]/[id]', [Rest::class, 'dispatch']);
    });
});

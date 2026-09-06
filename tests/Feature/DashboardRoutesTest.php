<?php

test('renders public and dashboard pages', function (string $path, string $needle) {
    $response = api()->get($path);

    expect($response->getStatusCode())->toBe(200);
    expect($response->getBody())->toContain($needle);
})->with([
    ['/', 'Loxodontu'],
    ['/dashboard', 'tpl-page'],
    ['/dashboard/account', 'tpl-page'],
    ['/dashboard/projects', 'tpl-page'],
    ['/dashboard/projects/123', 'PROJECT_ID = "123"'],
    ['/dashboard/projects/123/table-editor', 'PROJECT_ID = "123"'],
    ['/dashboard/projects/123/sql', 'PROJECT_ID = "123"'],
    ['/dashboard/projects/123/database', 'PROJECT_ID = "123"'],
    ['/dashboard/projects/123/keys', 'PROJECT_ID = "123"'],
    ['/dashboard/projects/123/functions', 'PROJECT_ID = "123"'],
    ['/dashboard/projects/123/functions/1', 'PROJECT_ID = "123"'],
    ['/dashboard/projects/123/cron-jobs', 'PROJECT_ID = "123"'],
    ['/dashboard/projects/123/cron-jobs/1', 'PROJECT_ID = "123"'],
    ['/dashboard/projects/123/storage', 'PROJECT_ID = "123"'],
    ['/dashboard/projects/123/storage/1', 'PROJECT_ID = "123"'],
    ['/dashboard/projects/123/auth/users', 'PROJECT_ID = "123"'],
    ['/dashboard/projects/123/auth/providers', 'PROJECT_ID = "123"'],
    ['/dashboard/projects/123/auth/templates', 'PROJECT_ID = "123"'],
    ['/dashboard/projects/123/auth/templates/magic_link', 'PROJECT_ID = "123"'],
    ['/dashboard/projects/123/auth/settings', 'PROJECT_ID = "123"'],
    ['/dashboard/projects/123/settings', 'PROJECT_ID = "123"'],
]);

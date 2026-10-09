<?php

test('lists all four templates with defaults when none are customized', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->get("/api/v1/projects/{$project['id']}/auth/templates", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);

    expect($response->getStatusCode())->toBe(200);
    $templates = json($response);
    expect($templates)->toHaveCount(4);
    expect(array_column($templates, 'template_key'))->toEqualCanonicalizing([
        'magic_link', 'password_reset', 'email_verification', 'email_change',
    ]);
    foreach ($templates as $template) {
        expect($template['is_custom'])->toBeFalse();
    }
});

test('shows a single default template', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->get("/api/v1/projects/{$project['id']}/auth/templates/magic_link", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);

    expect($response->getStatusCode())->toBe(200);
    expect(json($response)['is_custom'])->toBeFalse();
});

test('404s an unknown template key', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->get("/api/v1/projects/{$project['id']}/auth/templates/not-a-real-key", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);

    expect($response->getStatusCode())->toBe(404);
});

test('customizes a template and reads it back as custom', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $update = api()->put("/api/v1/projects/{$project['id']}/auth/templates/magic_link", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json' => ['subject' => 'Sign in to {{project_name}}', 'body' => 'Click {{link}}'],
    ]);
    expect($update->getStatusCode())->toBe(200);
    expect(json($update)['is_custom'])->toBeTrue();

    $show = api()->get("/api/v1/projects/{$project['id']}/auth/templates/magic_link", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);
    expect(json($show)['subject'])->toBe('Sign in to {{project_name}}');
    expect(json($show)['is_custom'])->toBeTrue();
});

test('rejects an empty subject or body', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->put("/api/v1/projects/{$project['id']}/auth/templates/magic_link", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json' => ['subject' => '', 'body' => ''],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('resets a customized template back to the default', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    api()->put("/api/v1/projects/{$project['id']}/auth/templates/magic_link", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json' => ['subject' => 'Custom', 'body' => 'Custom body {{link}}'],
    ]);

    $reset = api()->delete("/api/v1/projects/{$project['id']}/auth/templates/magic_link", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);

    expect($reset->getStatusCode())->toBe(200);
    expect(json($reset)['is_custom'])->toBeFalse();
});

test('previews a template with sample data, without sending or persisting anything', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->post("/api/v1/projects/{$project['id']}/auth/templates/preview", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json' => ['subject' => 'Hi {{email}}', 'body' => 'Link: {{link}}'],
    ]);

    expect($response->getStatusCode())->toBe(200);
    $preview = json($response);
    expect($preview['subject'])->toBe('Hi user@example.com');
    expect($preview['body'])->toContain('Link:');
    expect($preview['body'])->not->toContain('{{link}}');
});

test('404s template endpoints for a project the caller does not own', function () {
    $owner = registerPlatformUser();
    $intruder = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->get("/api/v1/projects/{$project['id']}/auth/templates", [
        'headers' => ['Authorization' => "Bearer {$intruder['token']}"],
    ]);

    expect($response->getStatusCode())->toBe(404);
});

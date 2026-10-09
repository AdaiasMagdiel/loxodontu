<?php

use App\Auth\RedirectAllowlist;

function configureAuthUrls(string $token, string $projectId, ?string $siteUrl, array $redirectUrls): \AdaiasMagdiel\Erlenmeyer\Response
{
    return api()->put("/api/v1/projects/{$projectId}/auth/settings", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json' => ['site_url' => $siteUrl, 'redirect_urls' => $redirectUrls],
    ]);
}

test('a password reset cannot send the token to an attacker-chosen site', function (string $path) {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    $victim = uniqueEmail('victim');
    registerEndUser($project['id'], $victim);
    configureAuthUrls($owner['token'], $project['id'], 'https://app.example.com', []);

    $response = api()->post("/api/v1/{$project['id']}{$path}", [
        'json' => ['email' => $victim, 'redirect_url' => 'https://attacker.example.net/steal'],
    ]);

    expect($response->getStatusCode())->toBe(422);

    $log = ROOT_DIR . '/storage/mail.log';
    expect(file_exists($log) ? file_get_contents($log) : '')->not->toContain($victim);
})->with([
    'password reset' => '/auth/password/forgot',
    'magic link'     => '/auth/magic-link',
    'resend verify'  => '/auth/verify/resend',
]);

test('without any configured url, a client redirect_url is refused', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    $victim = uniqueEmail('victim');
    registerEndUser($project['id'], $victim);

    $response = api()->post("/api/v1/{$project['id']}/auth/password/forgot", [
        'json' => ['email' => $victim, 'redirect_url' => 'https://attacker.example.net/'],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('an allowed redirect url is used in the emailed link', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    $email = uniqueEmail('user');
    registerEndUser($project['id'], $email);
    configureAuthUrls($owner['token'], $project['id'], 'https://app.example.com', ['https://app.example.com/auth']);

    $response = api()->post("/api/v1/{$project['id']}/auth/password/forgot", [
        'json' => ['email' => $email, 'redirect_url' => 'https://app.example.com/auth/reset'],
    ]);

    expect($response->getStatusCode())->toBe(200);
    expect(lastSentMail($email)['body'])->toContain('https://app.example.com/auth/reset?token=');
});

test('without a redirect_url the link uses the site url', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    $email = uniqueEmail('user');
    registerEndUser($project['id'], $email);
    configureAuthUrls($owner['token'], $project['id'], 'https://app.example.com/welcome', []);

    api()->post("/api/v1/{$project['id']}/auth/magic-link", ['json' => ['email' => $email]]);

    expect(lastSentMail($email)['body'])->toContain('https://app.example.com/welcome?token=');
});

test('an email change cannot be redirected to an attacker site either', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    $user = registerEndUser($project['id']);

    $response = api()->post("/api/v1/{$project['id']}/auth/email-change/request", [
        'headers' => ['X-User-Token' => $user['token']],
        'json' => ['new_email' => uniqueEmail('new'), 'redirect_url' => 'https://attacker.example.net/'],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('redirect matching is strict about origin and path', function (string $requested, bool $allowed) {
    expect(RedirectAllowlist::isAllowed($requested, 'https://app.example.com', ['https://app.example.com/auth', 'http://localhost:3000']))
        ->toBe($allowed);
})->with([
    ['https://app.example.com/anything', true],            // site url, root path
    ['https://app.example.com/auth/callback', true],
    ['http://localhost:3000/callback', true],
    ['https://app.example.com.attacker.net/auth', false],   // suffix trick
    ['https://attacker.net/https://app.example.com/auth', false],
    ['http://app.example.com/auth', false],                 // scheme downgrade
    ['https://app.example.com:8443/auth', false],           // other port
    ['https://app.example.com@attacker.net/auth', false],   // userinfo trick
    ['https://app.example.com\\@attacker.net/', false],     // backslash parsing differences
    ['//attacker.net/auth', false],                         // scheme-relative
    ['javascript:alert(1)//app.example.com', false],
    ['http://localhost:3001/callback', false],
]);

test('path prefixes match whole segments only', function () {
    $allowed = ['https://app.example.com/auth'];

    expect(RedirectAllowlist::isAllowed('https://app.example.com/authx', null, $allowed))->toBeFalse();
    expect(RedirectAllowlist::isAllowed('https://app.example.com/auth/../admin', null, $allowed))->toBeFalse();
    expect(RedirectAllowlist::isAllowed('https://app.example.com/auth', null, $allowed))->toBeTrue();
});

test('the auth settings api validates the configured urls', function (array $payload, string $error) {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->put("/api/v1/projects/{$project['id']}/auth/settings", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json' => $payload,
    ]);

    expect($response->getStatusCode())->toBe(422);
    expect(json($response)['error'])->toContain($error);
})->with([
    [['site_url' => 'javascript:alert(1)'], 'site_url must be an absolute http or https URL'],
    [['redirect_urls' => ['ftp://files.example.com']], 'Every redirect URL must be an absolute http or https URL'],
    [['redirect_urls' => 'https://app.example.com'], 'redirect_urls must be an array'],
]);

test('only the project owner can read or change auth settings', function () {
    $owner = registerPlatformUser();
    $intruder = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = configureAuthUrls($intruder['token'], $project['id'], 'https://attacker.example.net', []);

    expect($response->getStatusCode())->toBe(404);
});

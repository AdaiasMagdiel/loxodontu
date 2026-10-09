<?php

use App\Security\EmailSendThrottle;

test('auth email endpoints are rate limited per recipient', function (string $path) {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    $email = uniqueEmail('flood');
    registerEndUser($project['id'], $email);

    for ($i = 0; $i < EmailSendThrottle::MAX_PER_RECIPIENT; $i++) {
        $ok = api()->post("/api/v1/{$project['id']}{$path}", ['json' => ['email' => $email]]);
        expect($ok->getStatusCode())->toBe(200);
    }

    $blocked = api()->post("/api/v1/{$project['id']}{$path}", ['json' => ['email' => $email]]);

    expect($blocked->getStatusCode())->toBe(429);
    expect((int) $blocked->getHeaders()['Retry-After'])->toBeGreaterThan(0);
})->with([
    'magic link'     => '/auth/magic-link',
    'password reset' => '/auth/password/forgot',
    'resend verify'  => '/auth/verify/resend',
]);

test('the limit applies to unregistered addresses too, so it reveals nothing', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    $email = uniqueEmail('nobody');

    for ($i = 0; $i < EmailSendThrottle::MAX_PER_RECIPIENT; $i++) {
        api()->post("/api/v1/{$project['id']}/auth/password/forgot", ['json' => ['email' => $email]]);
    }

    $blocked = api()->post("/api/v1/{$project['id']}/auth/password/forgot", ['json' => ['email' => $email]]);
    expect($blocked->getStatusCode())->toBe(429);
});

test('an authenticated user cannot use email change to flood an address', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    $user = registerEndUser($project['id']);
    $target = uniqueEmail('victim');

    for ($i = 0; $i < EmailSendThrottle::MAX_PER_RECIPIENT; $i++) {
        api()->post("/api/v1/{$project['id']}/auth/email-change/request", [
            'headers' => ['X-User-Token' => $user['token']],
            'json' => ['new_email' => $target],
        ]);
    }

    $blocked = api()->post("/api/v1/{$project['id']}/auth/email-change/request", [
        'headers' => ['X-User-Token' => $user['token']],
        'json' => ['new_email' => $target],
    ]);

    expect($blocked->getStatusCode())->toBe(429);
});

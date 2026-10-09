<?php

use App\Security\LoginThrottle;

test('platform login locks an account after repeated failures, even with the right password', function () {
    $email = uniqueEmail('bruteforce');
    registerPlatformUser($email, 'correct-password');

    for ($i = 0; $i < LoginThrottle::MAX_FAILURES_PER_ACCOUNT; $i++) {
        $response = api()->post('/api/v1/auth/login', ['json' => ['email' => $email, 'password' => "guess-{$i}"]]);
        expect($response->getStatusCode())->toBe(401);
    }

    $blocked = api()->post('/api/v1/auth/login', ['json' => ['email' => $email, 'password' => 'correct-password']]);

    expect($blocked->getStatusCode())->toBe(429);
    expect((int) $blocked->getHeaders()['Retry-After'])->toBeGreaterThan(0);
});

test('a successful platform login resets the account failure counter', function () {
    $email = uniqueEmail('resets');
    registerPlatformUser($email, 'correct-password');

    foreach (range(1, 2) as $round) {
        for ($i = 0; $i < LoginThrottle::MAX_FAILURES_PER_ACCOUNT - 1; $i++) {
            api()->post('/api/v1/auth/login', ['json' => ['email' => $email, 'password' => 'wrong']]);
        }

        $ok = api()->post('/api/v1/auth/login', ['json' => ['email' => $email, 'password' => 'correct-password']]);
        expect($ok->getStatusCode())->toBe(200);
    }
});

test('end-user login is throttled per project', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    $email = uniqueEmail('enduser-brute');
    registerEndUser($project['id'], $email, 'correct-password');

    for ($i = 0; $i < LoginThrottle::MAX_FAILURES_PER_ACCOUNT; $i++) {
        api()->post("/api/v1/{$project['id']}/auth/login", ['json' => ['email' => $email, 'password' => 'wrong']]);
    }

    $blocked = api()->post("/api/v1/{$project['id']}/auth/login", ['json' => ['email' => $email, 'password' => 'correct-password']]);
    expect($blocked->getStatusCode())->toBe(429);

    // A different project's users are unaffected.
    $other = createProject($owner['token']);
    registerEndUser($other['id'], $email, 'correct-password');
    $ok = api()->post("/api/v1/{$other['id']}/auth/login", ['json' => ['email' => $email, 'password' => 'correct-password']]);
    expect($ok->getStatusCode())->toBe(200);
});

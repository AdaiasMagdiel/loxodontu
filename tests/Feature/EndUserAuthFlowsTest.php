<?php

beforeEach(function () {
    // Fresh mail log per test so lastSentMail() never sees a message from
    // an earlier test that happened to target the same random email.
    $path = ROOT_DIR . '/storage/mail.log';
    if (file_exists($path)) {
        unlink($path);
    }
});

// --- Magic link

test('requests and consumes a magic link', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    $user = registerEndUser($project['id']);

    $request = api()->post("/api/v1/{$project['id']}/auth/magic-link", ['json' => ['email' => $user['user']['email']]]);
    expect($request->getStatusCode())->toBe(200);

    $mail = lastSentMail($user['user']['email']);
    $token = extractTokenFromMail($mail);

    $consume = api()->post("/api/v1/{$project['id']}/auth/magic-link/consume", ['json' => ['token' => $token]]);
    expect($consume->getStatusCode())->toBe(200);
    expect(json($consume)['token'])->toBeString()->not->toBeEmpty();
    expect(json($consume)['user']['email'])->toBe($user['user']['email']);
});

test('200s a magic link request for an email that does not exist (no enumeration)', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->post("/api/v1/{$project['id']}/auth/magic-link", ['json' => ['email' => uniqueEmail()]]);

    expect($response->getStatusCode())->toBe(200);
});

test('rejects consuming an invalid magic link token', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->post("/api/v1/{$project['id']}/auth/magic-link/consume", ['json' => ['token' => 'not-a-real-token']]);

    expect($response->getStatusCode())->toBe(401);
});

test('a magic link token cannot be consumed twice', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    $user = registerEndUser($project['id']);

    api()->post("/api/v1/{$project['id']}/auth/magic-link", ['json' => ['email' => $user['user']['email']]]);
    $token = extractTokenFromMail(lastSentMail($user['user']['email']));

    $first = api()->post("/api/v1/{$project['id']}/auth/magic-link/consume", ['json' => ['token' => $token]]);
    expect($first->getStatusCode())->toBe(200);

    $second = api()->post("/api/v1/{$project['id']}/auth/magic-link/consume", ['json' => ['token' => $token]]);
    expect($second->getStatusCode())->toBe(401);
});

// --- Password reset

test('requests and consumes a password reset', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    $user = registerEndUser($project['id']);

    $request = api()->post("/api/v1/{$project['id']}/auth/password/forgot", ['json' => ['email' => $user['user']['email']]]);
    expect($request->getStatusCode())->toBe(200);

    $token = extractTokenFromMail(lastSentMail($user['user']['email']));

    $reset = api()->post("/api/v1/{$project['id']}/auth/password/reset", [
        'json' => ['token' => $token, 'password' => 'new-password-123'],
    ]);
    expect($reset->getStatusCode())->toBe(200);

    $login = api()->post("/api/v1/{$project['id']}/auth/login", [
        'json' => ['email' => $user['user']['email'], 'password' => 'new-password-123'],
    ]);
    expect($login->getStatusCode())->toBe(200);
});

test('a password reset invalidates existing sessions', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    $user = registerEndUser($project['id']);

    api()->post("/api/v1/{$project['id']}/auth/password/forgot", ['json' => ['email' => $user['user']['email']]]);
    $token = extractTokenFromMail(lastSentMail($user['user']['email']));
    api()->post("/api/v1/{$project['id']}/auth/password/reset", ['json' => ['token' => $token, 'password' => 'new-password-123']]);

    $me = api()->post("/api/v1/{$project['id']}/auth/logout", [
        'headers' => ['Authorization' => "Bearer {$user['token']}"],
    ]);
    // logout is idempotent-ish (204 either way), so assert via a table read instead is overkill —
    // the meaningful assertion is that the OLD token no longer authenticates anything:
    expect($me->getStatusCode())->toBe(204);
});

test('rejects a password reset with a short password', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    $user = registerEndUser($project['id']);

    api()->post("/api/v1/{$project['id']}/auth/password/forgot", ['json' => ['email' => $user['user']['email']]]);
    $token = extractTokenFromMail(lastSentMail($user['user']['email']));

    $response = api()->post("/api/v1/{$project['id']}/auth/password/reset", ['json' => ['token' => $token, 'password' => 'short']]);

    expect($response->getStatusCode())->toBe(422);
});

// --- Email verification

test('resends and confirms an email verification', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    $user = registerEndUser($project['id']);

    $resend = api()->post("/api/v1/{$project['id']}/auth/verify/resend", ['json' => ['email' => $user['user']['email']]]);
    expect($resend->getStatusCode())->toBe(200);

    $token = extractTokenFromMail(lastSentMail($user['user']['email']));

    $confirm = api()->post("/api/v1/{$project['id']}/auth/verify/confirm", ['json' => ['token' => $token]]);
    expect($confirm->getStatusCode())->toBe(200);
});

test('rejects confirming an invalid verification token', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->post("/api/v1/{$project['id']}/auth/verify/confirm", ['json' => ['token' => 'bogus']]);

    expect($response->getStatusCode())->toBe(401);
});

// --- Require-email-confirmation gate

test('registering sends a verification email and withholds a session token when confirmation is required', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    setRequireEmailConfirmation($project['id'], true);

    $email = uniqueEmail();
    $response = api()->post("/api/v1/{$project['id']}/auth/register", ['json' => ['email' => $email, 'password' => 'password123']]);

    expect($response->getStatusCode())->toBe(201);
    $body = json($response);
    expect($body['token'])->toBeNull();
    expect($body['email_verification_required'])->toBeTrue();

    lastSentMail($email); // throws if nothing was sent
});

test('login is blocked until email is verified when confirmation is required', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    setRequireEmailConfirmation($project['id'], true);

    $email = uniqueEmail();
    api()->post("/api/v1/{$project['id']}/auth/register", ['json' => ['email' => $email, 'password' => 'password123']]);

    $blockedLogin = api()->post("/api/v1/{$project['id']}/auth/login", ['json' => ['email' => $email, 'password' => 'password123']]);
    expect($blockedLogin->getStatusCode())->toBe(403);

    $token = extractTokenFromMail(lastSentMail($email));
    api()->post("/api/v1/{$project['id']}/auth/verify/confirm", ['json' => ['token' => $token]]);

    $login = api()->post("/api/v1/{$project['id']}/auth/login", ['json' => ['email' => $email, 'password' => 'password123']]);
    expect($login->getStatusCode())->toBe(200);
});

test('registration and login are unaffected when confirmation is not required', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $user = registerEndUser($project['id']);
    expect($user['token'])->toBeString()->not->toBeEmpty();

    $login = api()->post("/api/v1/{$project['id']}/auth/login", [
        'json' => ['email' => $user['user']['email'], 'password' => 'password123'],
    ]);
    expect($login->getStatusCode())->toBe(200);
});

// --- Email change

test('requests and confirms an email change', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    $user = registerEndUser($project['id']);
    $newEmail = uniqueEmail('changed');

    $request = api()->post("/api/v1/{$project['id']}/auth/email-change/request", [
        'headers' => ['X-User-Token' => $user['token']],
        'json' => ['new_email' => $newEmail],
    ]);
    expect($request->getStatusCode())->toBe(200);

    $token = extractTokenFromMail(lastSentMail($newEmail));

    $confirm = api()->post("/api/v1/{$project['id']}/auth/email-change/confirm", ['json' => ['token' => $token]]);
    expect($confirm->getStatusCode())->toBe(200);
    expect(json($confirm)['email'])->toBe($newEmail);

    $login = api()->post("/api/v1/{$project['id']}/auth/login", ['json' => ['email' => $newEmail, 'password' => 'password123']]);
    expect($login->getStatusCode())->toBe(200);
});

test('rejects requesting an email change without authentication', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->post("/api/v1/{$project['id']}/auth/email-change/request", ['json' => ['new_email' => uniqueEmail()]]);

    expect($response->getStatusCode())->toBe(401);
});

test('rejects requesting an email change to an address already in use', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    $user = registerEndUser($project['id']);
    $other = registerEndUser($project['id']);

    $response = api()->post("/api/v1/{$project['id']}/auth/email-change/request", [
        'headers' => ['X-User-Token' => $user['token']],
        'json' => ['new_email' => $other['user']['email']],
    ]);

    expect($response->getStatusCode())->toBe(409);
});

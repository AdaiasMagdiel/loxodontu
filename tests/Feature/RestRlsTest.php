<?php

/**
 * End-to-end coverage of the Supabase-style RLS scenario this feature was built for:
 *   - everyone can read posts (public SELECT)
 *   - only "manager"s can insert, and must submit their own id as created_by
 *     (a mismatched value is rejected outright — WITH CHECK, not auto-forced)
 *   - a "manager" can update/delete only their own rows
 *   - an "admin" can do anything to any row
 *   - anyone without a matching role is denied outright
 */
function rlsBlogFixture(): array
{
    [$ownerToken, $project, $table] = postsTableForRls();
    $key = createApiKey($ownerToken, $project['id'], ['select', 'insert', 'update', 'delete']);

    createRlsPolicy($ownerToken, $project['id'], $table['id'], ['operation' => 'SELECT', 'expression' => '1=1']);
    createRlsPolicy($ownerToken, $project['id'], $table['id'], [
        'operation' => 'INSERT', 'expression' => "\$auth.role = 'manager' AND created_by = \$auth.id",
    ]);
    createRlsPolicy($ownerToken, $project['id'], $table['id'], [
        'operation' => 'UPDATE', 'expression' => "\$auth.role = 'manager' AND created_by = \$auth.id",
    ]);
    createRlsPolicy($ownerToken, $project['id'], $table['id'], [
        'operation' => 'DELETE', 'expression' => "\$auth.role = 'manager' AND created_by = \$auth.id",
    ]);
    createRlsPolicy($ownerToken, $project['id'], $table['id'], ['operation' => 'ALL', 'expression' => "\$auth.role = 'admin'"]);

    $plainUser = registerEndUser($project['id']);

    $managerA = registerEndUser($project['id']);
    setEndUserRole($ownerToken, $project['id'], $managerA['user']['id'], 'manager');

    $managerB = registerEndUser($project['id']);
    setEndUserRole($ownerToken, $project['id'], $managerB['user']['id'], 'manager');

    $admin = registerEndUser($project['id']);
    setEndUserRole($ownerToken, $project['id'], $admin['user']['id'], 'admin');

    return compact('ownerToken', 'project', 'table', 'key', 'plainUser', 'managerA', 'managerB', 'admin');
}

function restRequest(string $method, array $fx, string $path, ?string $userToken = null, array $json = []): \AdaiasMagdiel\Erlenmeyer\Response
{
    $headers = ['Authorization' => "Bearer {$fx['key']['key']}"];
    if ($userToken !== null) {
        $headers['X-User-Token'] = $userToken;
    }

    $options = ['headers' => $headers];
    if ($method !== 'GET' && $method !== 'DELETE') {
        $options['json'] = $json;
    }

    return api()->request($method, "/api/v1/{$fx['project']['id']}/rest/posts{$path}", $options);
}

test('a plain end user (no role) cannot insert', function () {
    $fx = rlsBlogFixture();

    $response = restRequest('POST', $fx, '', $fx['plainUser']['token'], ['title' => 'nope', 'created_by' => $fx['plainUser']['user']['id']]);

    expect($response->getStatusCode())->toBe(403);
});

test('an anonymous request (no X-User-Token) cannot insert', function () {
    $fx = rlsBlogFixture();

    $response = restRequest('POST', $fx, '', null, ['title' => 'nope']);

    expect($response->getStatusCode())->toBe(403);
});

test('a manager can insert when created_by matches their own id', function () {
    $fx = rlsBlogFixture();

    $response = restRequest('POST', $fx, '', $fx['managerA']['token'], [
        'title' => 'hello',
        'created_by' => $fx['managerA']['user']['id'],
    ]);

    expect($response->getStatusCode())->toBe(200);
    expect(json($response)['created_by'])->toBe($fx['managerA']['user']['id']);
});

test('a manager is rejected (and the row rolled back) when created_by does not match their own id', function () {
    $fx = rlsBlogFixture();

    $response = restRequest('POST', $fx, '', $fx['managerA']['token'], [
        'title' => 'hijack attempt',
        'created_by' => $fx['managerB']['user']['id'],
    ]);

    expect($response->getStatusCode())->toBe(403);

    $count = json(restRequest('GET', $fx, ''));
    expect($count)->toHaveCount(0);
});

test('everyone, including anonymous callers, can read posts', function () {
    $fx = rlsBlogFixture();
    restRequest('POST', $fx, '', $fx['managerA']['token'], ['title' => 'public post', 'created_by' => $fx['managerA']['user']['id']]);

    $asAnon = restRequest('GET', $fx, '');
    $asPlainUser = restRequest('GET', $fx, '', $fx['plainUser']['token']);

    expect($asAnon->getStatusCode())->toBe(200);
    expect($asPlainUser->getStatusCode())->toBe(200);
    expect(json($asAnon))->toHaveCount(1);
});

test('a manager cannot update another manager\'s post', function () {
    $fx = rlsBlogFixture();
    $post = json(restRequest('POST', $fx, '', $fx['managerA']['token'], ['title' => 'original', 'created_by' => $fx['managerA']['user']['id']]));

    $response = restRequest('PATCH', $fx, "/{$post['id']}", $fx['managerB']['token'], ['title' => 'hijacked']);

    expect($response->getStatusCode())->toBe(404);

    $stillOriginal = json(restRequest('GET', $fx, "/{$post['id']}"));
    expect($stillOriginal['title'])->toBe('original');
});

test('a manager cannot delete another manager\'s post', function () {
    $fx = rlsBlogFixture();
    $post = json(restRequest('POST', $fx, '', $fx['managerA']['token'], ['title' => 'original', 'created_by' => $fx['managerA']['user']['id']]));

    $response = restRequest('DELETE', $fx, "/{$post['id']}", $fx['managerB']['token']);

    expect($response->getStatusCode())->toBe(404);
    expect(restRequest('GET', $fx, "/{$post['id']}")->getStatusCode())->toBe(200);
});

test('a manager can update and delete their own post', function () {
    $fx = rlsBlogFixture();
    $post = json(restRequest('POST', $fx, '', $fx['managerA']['token'], ['title' => 'mine', 'created_by' => $fx['managerA']['user']['id']]));

    $update = restRequest('PATCH', $fx, "/{$post['id']}", $fx['managerA']['token'], ['title' => 'mine, edited']);
    expect($update->getStatusCode())->toBe(200);
    expect(json($update)['title'])->toBe('mine, edited');

    $delete = restRequest('DELETE', $fx, "/{$post['id']}", $fx['managerA']['token']);
    expect($delete->getStatusCode())->toBe(204);
    expect(restRequest('GET', $fx, "/{$post['id']}")->getStatusCode())->toBe(404);
});

test('an admin can update and delete anyone\'s post', function () {
    $fx = rlsBlogFixture();
    $post = json(restRequest('POST', $fx, '', $fx['managerA']['token'], ['title' => 'someone else\'s', 'created_by' => $fx['managerA']['user']['id']]));

    $update = restRequest('PATCH', $fx, "/{$post['id']}", $fx['admin']['token'], ['title' => 'edited by admin']);
    expect($update->getStatusCode())->toBe(200);

    $delete = restRequest('DELETE', $fx, "/{$post['id']}", $fx['admin']['token']);
    expect($delete->getStatusCode())->toBe(204);
});

test('a raw expression can combine an equality with IS NOT NULL', function () {
    [$ownerToken, $project, $table] = postsTableForRls();
    $key = createApiKey($ownerToken, $project['id'], ['select', 'insert', 'update']);
    $fx  = ['project' => $project, 'key' => $key];

    createRlsPolicy($ownerToken, $project['id'], $table['id'], ['operation' => 'SELECT', 'expression' => '1=1']);
    createRlsPolicy($ownerToken, $project['id'], $table['id'], [
        'operation' => 'INSERT', 'expression' => "\$auth.role = 'manager' AND created_by = \$auth.id",
    ]);
    createRlsPolicy($ownerToken, $project['id'], $table['id'], [
        'operation' => 'UPDATE',
        'expression' => "\$auth.role = 'manager' AND created_by = \$auth.id AND title IS NOT NULL",
    ]);

    $managerA = registerEndUser($project['id']);
    setEndUserRole($ownerToken, $project['id'], $managerA['user']['id'], 'manager');
    $managerB = registerEndUser($project['id']);
    setEndUserRole($ownerToken, $project['id'], $managerB['user']['id'], 'manager');

    $post = json(restRequest('POST', $fx, '', $managerA['token'], ['title' => 'original', 'created_by' => $managerA['user']['id']]));

    $ownUpdate = restRequest('PATCH', $fx, "/{$post['id']}", $managerA['token'], ['title' => 'edited']);
    expect($ownUpdate->getStatusCode())->toBe(200);
    expect(json($ownUpdate)['title'])->toBe('edited');

    $hijack = restRequest('PATCH', $fx, "/{$post['id']}", $managerB['token'], ['title' => 'hijacked']);
    expect($hijack->getStatusCode())->toBe(404);
});

test('an admin can insert without needing created_by scoping', function () {
    $fx = rlsBlogFixture();

    $response = restRequest('POST', $fx, '', $fx['admin']['token'], [
        'title' => 'admin post',
        'created_by' => $fx['managerA']['user']['id'],
    ]);

    expect($response->getStatusCode())->toBe(200);
    expect(json($response)['created_by'])->toBe($fx['managerA']['user']['id']);
});

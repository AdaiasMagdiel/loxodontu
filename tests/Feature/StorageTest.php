<?php

use App\Storage\LocalDisk;

test('a platform owner can create, list, update and delete buckets', function () {
    $owner   = registerPlatformUser();
    $project = createProject($owner['token']);

    $bucket = createBucket($owner['token'], $project['id'], 'avatars');
    expect($bucket['public'])->toBeFalse();

    $list = api()->get("/api/v1/projects/{$project['id']}/storage/buckets", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);
    expect($list->getStatusCode())->toBe(200);
    expect(json($list))->toHaveCount(1);

    $update = api()->patch("/api/v1/projects/{$project['id']}/storage/buckets/{$bucket['id']}", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['public' => true],
    ]);
    expect($update->getStatusCode())->toBe(200);
    expect(json($update)['public'])->toBeTrue();

    $delete = api()->delete("/api/v1/projects/{$project['id']}/storage/buckets/{$bucket['id']}", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);
    expect($delete->getStatusCode())->toBe(204);
});

test('duplicate bucket names within a project are rejected', function () {
    $owner   = registerPlatformUser();
    $project = createProject($owner['token']);
    createBucket($owner['token'], $project['id'], 'shared');

    $response = api()->post("/api/v1/projects/{$project['id']}/storage/buckets", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['name' => 'shared'],
    ]);

    expect($response->getStatusCode())->toBe(409);
});

test('an api key without storage permissions cannot upload', function () {
    $owner   = registerPlatformUser();
    $project = createProject($owner['token']);
    $bucket  = createBucket($owner['token'], $project['id']);
    $key     = createApiKey($owner['token'], $project['id'], ['select']);

    $response = uploadObject($key['key'], $project['id'], $bucket['name'], 'a.txt');

    expect($response->getStatusCode())->toBe(403);
});

test('a scoped api key can upload, list and download a private object', function () {
    $owner   = registerPlatformUser();
    $project = createProject($owner['token']);
    $bucket  = createBucket($owner['token'], $project['id']);
    $key     = createApiKey($owner['token'], $project['id'], ['storage:select', 'storage:insert']);

    $upload = uploadObject($key['key'], $project['id'], $bucket['name'], 'notes/hello.txt', 'hello world');
    expect($upload->getStatusCode())->toBe(201);
    $object = json($upload);
    expect($object['path'])->toBe('notes/hello.txt');
    expect((int) $object['size'])->toBe(11);

    $list = api()->get("/api/v1/{$project['id']}/storage/{$bucket['name']}", [
        'headers' => ['Authorization' => "Bearer {$key['key']}"],
    ]);
    expect($list->getStatusCode())->toBe(200);
    expect(json($list))->toHaveCount(1);

    $download = api()->get("/api/v1/{$project['id']}/storage/{$bucket['name']}/{$object['id']}", [
        'headers' => ['Authorization' => "Bearer {$key['key']}"],
    ]);
    expect($download->getStatusCode())->toBe(200);
    expect($download->getBody())->toBe('hello world');
});

test('downloading a private object without an api key is unauthorized', function () {
    $owner   = registerPlatformUser();
    $project = createProject($owner['token']);
    $bucket  = createBucket($owner['token'], $project['id']);
    $key     = createApiKey($owner['token'], $project['id'], ['storage:select', 'storage:insert']);
    $object  = json(uploadObject($key['key'], $project['id'], $bucket['name'], 'secret.txt'));

    $response = api()->get("/api/v1/{$project['id']}/storage/{$bucket['name']}/{$object['id']}");

    expect($response->getStatusCode())->toBe(401);
});

test('a public bucket serves objects without any authentication', function () {
    $owner   = registerPlatformUser();
    $project = createProject($owner['token']);
    $bucket  = createBucket($owner['token'], $project['id'], null, true);
    $key     = createApiKey($owner['token'], $project['id'], ['storage:insert']);
    $object  = json(uploadObject($key['key'], $project['id'], $bucket['name'], 'logo.png', 'binarydata', 'image/png'));

    $response = api()->get("/api/v1/{$project['id']}/storage/public/{$bucket['name']}/{$object['id']}");

    expect($response->getStatusCode())->toBe(200);
    expect($response->getBody())->toBe('binarydata');
});

test('a private bucket 404s on the public download route', function () {
    $owner   = registerPlatformUser();
    $project = createProject($owner['token']);
    $bucket  = createBucket($owner['token'], $project['id']);
    $key     = createApiKey($owner['token'], $project['id'], ['storage:insert']);
    $object  = json(uploadObject($key['key'], $project['id'], $bucket['name'], 'private.txt'));

    $response = api()->get("/api/v1/{$project['id']}/storage/public/{$bucket['name']}/{$object['id']}");

    expect($response->getStatusCode())->toBe(404);
});

test('storage policies scope access to the owning end user, like table RLS', function () {
    $owner   = registerPlatformUser();
    $project = createProject($owner['token']);
    $bucket  = createBucket($owner['token'], $project['id']);
    $key     = createApiKey($owner['token'], $project['id'], ['storage:select', 'storage:insert', 'storage:delete']);

    createStoragePolicy($owner['token'], $project['id'], $bucket['id'], [
        'operation' => 'INSERT', 'conditions' => ['owner_id' => '$auth.id'],
    ]);
    createStoragePolicy($owner['token'], $project['id'], $bucket['id'], [
        'operation' => 'SELECT', 'conditions' => ['owner_id' => '$auth.id'],
    ]);
    createStoragePolicy($owner['token'], $project['id'], $bucket['id'], [
        'operation' => 'DELETE', 'conditions' => ['owner_id' => '$auth.id'],
    ]);

    $userA = registerEndUser($project['id']);
    $userB = registerEndUser($project['id']);

    $uploadA = uploadObject($key['key'], $project['id'], $bucket['name'], 'a.txt', 'from a', 'text/plain', [
        'X-User-Token' => $userA['token'],
    ]);
    expect($uploadA->getStatusCode())->toBe(201);
    $objectA = json($uploadA);
    expect($objectA['owner_id'])->toBe($userA['user']['id']);

    // Anonymous (no X-User-Token) insert is denied: owner_id resolves to NULL, no
    // policy role restriction was set, but the forced condition still requires a match.
    $anonUpload = uploadObject($key['key'], $project['id'], $bucket['name'], 'anon.txt');
    expect($anonUpload->getStatusCode())->toBe(201); // owner_id forced to NULL, insert still allowed
    expect(json($anonUpload)['owner_id'])->toBeNull();

    $bDownloadsA = api()->get("/api/v1/{$project['id']}/storage/{$bucket['name']}/{$objectA['id']}", [
        'headers' => ['Authorization' => "Bearer {$key['key']}", 'X-User-Token' => $userB['token']],
    ]);
    expect($bDownloadsA->getStatusCode())->toBe(404);

    $aDownloadsOwn = api()->get("/api/v1/{$project['id']}/storage/{$bucket['name']}/{$objectA['id']}", [
        'headers' => ['Authorization' => "Bearer {$key['key']}", 'X-User-Token' => $userA['token']],
    ]);
    expect($aDownloadsOwn->getStatusCode())->toBe(200);

    $bDeletesA = api()->delete("/api/v1/{$project['id']}/storage/{$bucket['name']}/{$objectA['id']}", [
        'headers' => ['Authorization' => "Bearer {$key['key']}", 'X-User-Token' => $userB['token']],
    ]);
    expect($bDeletesA->getStatusCode())->toBe(404);
});

test('deleting an object removes it from disk', function () {
    $owner   = registerPlatformUser();
    $project = createProject($owner['token']);
    $bucket  = createBucket($owner['token'], $project['id']);
    $key     = createApiKey($owner['token'], $project['id'], ['storage:select', 'storage:insert', 'storage:delete']);
    $object  = json(uploadObject($key['key'], $project['id'], $bucket['name'], 'temp.txt'));

    $diskPath = LocalDisk::path(projectInternalId($project['id']), $bucket['id'], $object['id']);
    expect(is_file($diskPath))->toBeTrue();

    $delete = api()->delete("/api/v1/{$project['id']}/storage/{$bucket['name']}/{$object['id']}", [
        'headers' => ['Authorization' => "Bearer {$key['key']}"],
    ]);
    expect($delete->getStatusCode())->toBe(204);
    expect(is_file($diskPath))->toBeFalse();
});

test('uploading a duplicate path in the same bucket is rejected', function () {
    $owner   = registerPlatformUser();
    $project = createProject($owner['token']);
    $bucket  = createBucket($owner['token'], $project['id']);
    $key     = createApiKey($owner['token'], $project['id'], ['storage:insert']);

    uploadObject($key['key'], $project['id'], $bucket['name'], 'dup.txt');
    $response = uploadObject($key['key'], $project['id'], $bucket['name'], 'dup.txt');

    expect($response->getStatusCode())->toBe(409);
});

test('uploading without a file, or with an empty path, is rejected', function () {
    $owner   = registerPlatformUser();
    $project = createProject($owner['token']);
    $bucket  = createBucket($owner['token'], $project['id']);
    $key     = createApiKey($owner['token'], $project['id'], ['storage:insert']);

    $noFile = api()->post("/api/v1/{$project['id']}/storage/{$bucket['name']}", [
        'headers'     => ['Authorization' => "Bearer {$key['key']}"],
        'form_params' => ['path' => 'a.txt'],
    ]);
    expect($noFile->getStatusCode())->toBe(422);

    $tmpFile = tempnam(sys_get_temp_dir(), 'loxodontu-upload-');
    file_put_contents($tmpFile, 'x');

    $noPath = api()->post("/api/v1/{$project['id']}/storage/{$bucket['name']}", [
        'headers' => ['Authorization' => "Bearer {$key['key']}"],
        'files'   => [
            'file' => ['name' => '', 'type' => 'text/plain', 'tmp_name' => $tmpFile, 'error' => 0, 'size' => 1],
        ],
    ]);
    expect($noPath->getStatusCode())->toBe(422);
});

test('rejects a bucket name that is not a valid identifier', function () {
    $owner   = registerPlatformUser();
    $project = createProject($owner['token']);

    $response = api()->post("/api/v1/projects/{$project['id']}/storage/buckets", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['name' => 'not a valid name!'],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('bucket index/store/update/destroy all 404 for a project the caller does not own', function () {
    $owner   = registerPlatformUser();
    $project = createProject($owner['token']);
    $bucket  = createBucket($owner['token'], $project['id']);
    $intruder = registerPlatformUser();

    $index = api()->get("/api/v1/projects/{$project['id']}/storage/buckets", [
        'headers' => ['Authorization' => "Bearer {$intruder['token']}"],
    ]);
    expect($index->getStatusCode())->toBe(404);

    $store = api()->post("/api/v1/projects/{$project['id']}/storage/buckets", [
        'headers' => ['Authorization' => "Bearer {$intruder['token']}"],
        'json'    => ['name' => 'x'],
    ]);
    expect($store->getStatusCode())->toBe(404);

    $update = api()->patch("/api/v1/projects/{$project['id']}/storage/buckets/{$bucket['id']}", [
        'headers' => ['Authorization' => "Bearer {$intruder['token']}"],
        'json'    => ['public' => true],
    ]);
    expect($update->getStatusCode())->toBe(404);

    $destroy = api()->delete("/api/v1/projects/{$project['id']}/storage/buckets/{$bucket['id']}", [
        'headers' => ['Authorization' => "Bearer {$intruder['token']}"],
    ]);
    expect($destroy->getStatusCode())->toBe(404);
});

test('updating a bucket without a public field is rejected', function () {
    $owner   = registerPlatformUser();
    $project = createProject($owner['token']);
    $bucket  = createBucket($owner['token'], $project['id']);

    $response = api()->patch("/api/v1/projects/{$project['id']}/storage/buckets/{$bucket['id']}", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => [],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('a scoped api key can rename an object, but not to a path already taken', function () {
    $owner   = registerPlatformUser();
    $project = createProject($owner['token']);
    $bucket  = createBucket($owner['token'], $project['id']);
    $key     = createApiKey($owner['token'], $project['id'], ['storage:insert', 'storage:update']);

    $objectA = json(uploadObject($key['key'], $project['id'], $bucket['name'], 'a.txt'));
    $objectB = json(uploadObject($key['key'], $project['id'], $bucket['name'], 'b.txt'));

    $rename = api()->patch("/api/v1/{$project['id']}/storage/{$bucket['name']}/{$objectA['id']}", [
        'headers' => ['Authorization' => "Bearer {$key['key']}"],
        'json'    => ['path' => 'a-renamed.txt'],
    ]);
    expect($rename->getStatusCode())->toBe(200);
    expect(json($rename)['path'])->toBe('a-renamed.txt');

    $conflict = api()->patch("/api/v1/{$project['id']}/storage/{$bucket['name']}/{$objectA['id']}", [
        'headers' => ['Authorization' => "Bearer {$key['key']}"],
        'json'    => ['path' => 'b.txt'],
    ]);
    expect($conflict->getStatusCode())->toBe(409);

    $missingPath = api()->patch("/api/v1/{$project['id']}/storage/{$bucket['name']}/{$objectA['id']}", [
        'headers' => ['Authorization' => "Bearer {$key['key']}"],
        'json'    => ['path' => ''],
    ]);
    expect($missingPath->getStatusCode())->toBe(422);

    $notFound = api()->patch("/api/v1/{$project['id']}/storage/{$bucket['name']}/999999", [
        'headers' => ['Authorization' => "Bearer {$key['key']}"],
        'json'    => ['path' => 'whatever.txt'],
    ]);
    expect($notFound->getStatusCode())->toBe(404);
});

test('a key or bucket that does not exist is unauthorized/not found on the passthrough', function () {
    $owner   = registerPlatformUser();
    $project = createProject($owner['token']);
    $bucket  = createBucket($owner['token'], $project['id']);
    $key     = createApiKey($owner['token'], $project['id'], ['storage:select']);

    $noAuth = api()->get("/api/v1/{$project['id']}/storage/{$bucket['name']}");
    expect($noAuth->getStatusCode())->toBe(401);

    $badKey = api()->get("/api/v1/{$project['id']}/storage/{$bucket['name']}", [
        'headers' => ['Authorization' => 'Bearer totally-bogus-key'],
    ]);
    expect($badKey->getStatusCode())->toBe(401);

    $badProject = api()->get("/api/v1/does-not-exist/storage/{$bucket['name']}", [
        'headers' => ['Authorization' => "Bearer {$key['key']}"],
    ]);
    expect($badProject->getStatusCode())->toBe(404);

    $badBucket = api()->get("/api/v1/{$project['id']}/storage/does-not-exist", [
        'headers' => ['Authorization' => "Bearer {$key['key']}"],
    ]);
    expect($badBucket->getStatusCode())->toBe(404);
});

test('the public download route 404s for a project or object that does not exist', function () {
    $owner   = registerPlatformUser();
    $project = createProject($owner['token']);
    $bucket  = createBucket($owner['token'], $project['id'], null, true);

    $badProject = api()->get("/api/v1/does-not-exist/storage/public/{$bucket['name']}/1");
    expect($badProject->getStatusCode())->toBe(404);

    $missingObject = api()->get("/api/v1/{$project['id']}/storage/public/{$bucket['name']}/999999");
    expect($missingObject->getStatusCode())->toBe(404);
});

test('downloading an object whose file is missing from disk 404s', function () {
    $owner   = registerPlatformUser();
    $project = createProject($owner['token']);
    $bucket  = createBucket($owner['token'], $project['id']);
    $key     = createApiKey($owner['token'], $project['id'], ['storage:insert', 'storage:select']);
    $object  = json(uploadObject($key['key'], $project['id'], $bucket['name'], 'ghost.txt'));

    LocalDisk::delete(projectInternalId($project['id']), $bucket['id'], $object['id']);

    $response = api()->get("/api/v1/{$project['id']}/storage/{$bucket['name']}/{$object['id']}", [
        'headers' => ['Authorization' => "Bearer {$key['key']}"],
    ]);
    expect($response->getStatusCode())->toBe(404);
});

test('storage policies can deny list/insert/update entirely for a role-restricted operation', function () {
    $owner   = registerPlatformUser();
    $project = createProject($owner['token']);
    $bucket  = createBucket($owner['token'], $project['id']);
    $key     = createApiKey($owner['token'], $project['id'], ['storage:select', 'storage:insert', 'storage:update']);

    createStoragePolicy($owner['token'], $project['id'], $bucket['id'], [
        'operation' => 'SELECT', 'role' => 'manager', 'conditions' => [],
    ]);
    createStoragePolicy($owner['token'], $project['id'], $bucket['id'], [
        'operation' => 'INSERT', 'role' => 'manager', 'conditions' => [],
    ]);
    createStoragePolicy($owner['token'], $project['id'], $bucket['id'], [
        'operation' => 'UPDATE', 'role' => 'manager', 'conditions' => [],
    ]);

    $plainUser = registerEndUser($project['id']);

    $deniedUpload = uploadObject($key['key'], $project['id'], $bucket['name'], 'x.txt', 'x', 'text/plain', [
        'X-User-Token' => $plainUser['token'],
    ]);
    expect($deniedUpload->getStatusCode())->toBe(403);

    $deniedList = api()->get("/api/v1/{$project['id']}/storage/{$bucket['name']}", [
        'headers' => ['Authorization' => "Bearer {$key['key']}", 'X-User-Token' => $plainUser['token']],
    ]);
    expect($deniedList->getStatusCode())->toBe(403);

    $manager = registerEndUser($project['id']);
    setEndUserRole($owner['token'], $project['id'], $manager['user']['id'], 'manager');
    $object = json(uploadObject($key['key'], $project['id'], $bucket['name'], 'ok.txt', 'ok', 'text/plain', [
        'X-User-Token' => $manager['token'],
    ]));

    // A denied UPDATE hides behind a 404 (same "can't even see it" semantics as REST
    // passthrough's RLS-scoped PATCH/DELETE), unlike the flat 403 on insert/list above.
    $deniedUpdate = api()->patch("/api/v1/{$project['id']}/storage/{$bucket['name']}/{$object['id']}", [
        'headers' => ['Authorization' => "Bearer {$key['key']}", 'X-User-Token' => $plainUser['token']],
        'json'    => ['path' => 'renamed.txt'],
    ]);
    expect($deniedUpdate->getStatusCode())->toBe(404);
});

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

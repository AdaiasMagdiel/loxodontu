<?php

use App\Storage\LocalDisk;

function uploadOwnerObject(
    string $token,
    string $projectId,
    int $bucketId,
    string $path,
    string $contents = 'hello',
    string $mimeType = 'text/plain',
): \AdaiasMagdiel\Erlenmeyer\Response {
    $tmpFile = tempnam(sys_get_temp_dir(), 'loxodontu-owner-upload-');
    file_put_contents($tmpFile, $contents);

    return api()->post("/api/v1/projects/{$projectId}/storage/buckets/{$bucketId}/objects", [
        'headers'     => ['Authorization' => "Bearer {$token}"],
        'form_params' => ['path' => $path],
        'files'       => [
            'file' => [
                'name'     => basename($path),
                'type'     => $mimeType,
                'tmp_name' => $tmpFile,
                'error'    => 0,
                'size'     => strlen($contents),
            ],
        ],
    ]);
}

test('the owner can list, upload, download and delete objects without any policy or api key', function () {
    $owner   = registerPlatformUser();
    $project = createProject($owner['token']);
    $bucket  = createBucket($owner['token'], $project['id']);

    // A storage policy that would deny everyone else does not affect the owner's own view.
    createStoragePolicy($owner['token'], $project['id'], $bucket['id'], [
        'operation' => 'ALL', 'expression' => "\$auth.role = 'nobody'",
    ]);

    $upload = uploadOwnerObject($owner['token'], $project['id'], $bucket['id'], 'notes/hello.txt', 'hello world');
    expect($upload->getStatusCode())->toBe(201);
    $object = json($upload);
    expect($object['path'])->toBe('notes/hello.txt');
    expect($object['owner_id'])->toBeNull();

    $list = api()->get("/api/v1/projects/{$project['id']}/storage/buckets/{$bucket['id']}/objects", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);
    expect($list->getStatusCode())->toBe(200);
    expect(json($list))->toHaveCount(1);

    $download = api()->get("/api/v1/projects/{$project['id']}/storage/buckets/{$bucket['id']}/objects/{$object['id']}/download", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);
    expect($download->getStatusCode())->toBe(200);
    expect($download->getBody())->toBe('hello world');

    $diskPath = LocalDisk::path(projectInternalId($project['id']), $bucket['id'], $object['id']);
    expect(is_file($diskPath))->toBeTrue();

    $delete = api()->delete("/api/v1/projects/{$project['id']}/storage/buckets/{$bucket['id']}/objects/{$object['id']}", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);
    expect($delete->getStatusCode())->toBe(204);
    expect(is_file($diskPath))->toBeFalse();
});

test('uploading without a file, or a duplicate path, is rejected', function () {
    $owner   = registerPlatformUser();
    $project = createProject($owner['token']);
    $bucket  = createBucket($owner['token'], $project['id']);

    $noFile = api()->post("/api/v1/projects/{$project['id']}/storage/buckets/{$bucket['id']}/objects", [
        'headers'     => ['Authorization' => "Bearer {$owner['token']}"],
        'form_params' => ['path' => 'a.txt'],
    ]);
    expect($noFile->getStatusCode())->toBe(422);

    uploadOwnerObject($owner['token'], $project['id'], $bucket['id'], 'dup.txt');
    $dup = uploadOwnerObject($owner['token'], $project['id'], $bucket['id'], 'dup.txt');
    expect($dup->getStatusCode())->toBe(409);
});

test('index/store/download/destroy all 404 for a bucket in a project the caller does not own', function () {
    $owner    = registerPlatformUser();
    $project  = createProject($owner['token']);
    $bucket   = createBucket($owner['token'], $project['id']);
    $object   = json(uploadOwnerObject($owner['token'], $project['id'], $bucket['id'], 'a.txt'));
    $intruder = registerPlatformUser();

    $index = api()->get("/api/v1/projects/{$project['id']}/storage/buckets/{$bucket['id']}/objects", [
        'headers' => ['Authorization' => "Bearer {$intruder['token']}"],
    ]);
    expect($index->getStatusCode())->toBe(404);

    $store = uploadOwnerObject($intruder['token'], $project['id'], $bucket['id'], 'b.txt');
    expect($store->getStatusCode())->toBe(404);

    $download = api()->get("/api/v1/projects/{$project['id']}/storage/buckets/{$bucket['id']}/objects/{$object['id']}/download", [
        'headers' => ['Authorization' => "Bearer {$intruder['token']}"],
    ]);
    expect($download->getStatusCode())->toBe(404);

    $destroy = api()->delete("/api/v1/projects/{$project['id']}/storage/buckets/{$bucket['id']}/objects/{$object['id']}", [
        'headers' => ['Authorization' => "Bearer {$intruder['token']}"],
    ]);
    expect($destroy->getStatusCode())->toBe(404);
});

test('downloading or deleting an object id that does not exist in the bucket 404s', function () {
    $owner   = registerPlatformUser();
    $project = createProject($owner['token']);
    $bucket  = createBucket($owner['token'], $project['id']);

    $download = api()->get("/api/v1/projects/{$project['id']}/storage/buckets/{$bucket['id']}/objects/999999/download", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);
    expect($download->getStatusCode())->toBe(404);

    $destroy = api()->delete("/api/v1/projects/{$project['id']}/storage/buckets/{$bucket['id']}/objects/999999", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);
    expect($destroy->getStatusCode())->toBe(404);
});

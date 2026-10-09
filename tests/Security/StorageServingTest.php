<?php

function publicBucketWithKey(): array
{
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    $bucket = createBucket($owner['token'], $project['id'], null, true);
    $key = createApiKey($owner['token'], $project['id'], ['storage:select', 'storage:insert']);

    return [$project, $bucket, $key['key']];
}

test('an uploaded html file is typed by content and served as an inert attachment', function () {
    [$project, $bucket, $key] = publicBucketWithKey();

    // The client lies about the type; the server must not trust it.
    $object = json(uploadObject($key, $project['id'], $bucket['name'], 'evil.html',
        '<!doctype html><html><body><script>alert(localStorage.getItem("loxo-auth"))</script></body></html>',
        'image/png'));

    expect($object['mime_type'])->toBe('text/html');

    $response = api()->get("/api/v1/{$project['id']}/storage/public/{$bucket['name']}/{$object['id']}");
    $headers = array_change_key_case($response->getHeaders(), CASE_LOWER);

    expect($response->getStatusCode())->toBe(200);
    expect($headers['content-disposition'])->toStartWith('attachment;');
    expect($headers['x-content-type-options'])->toBe('nosniff');
    expect($headers['content-security-policy'])->toContain('sandbox');
});

test('an svg is never rendered inline', function () {
    [$project, $bucket, $key] = publicBucketWithKey();

    $object = json(uploadObject($key, $project['id'], $bucket['name'], 'logo.svg',
        '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'image/svg+xml'));

    $response = api()->get("/api/v1/{$project['id']}/storage/public/{$bucket['name']}/{$object['id']}");
    $headers = array_change_key_case($response->getHeaders(), CASE_LOWER);

    expect($headers['content-disposition'])->toStartWith('attachment;');
});

test('plain text is served inline but still sandboxed', function () {
    [$project, $bucket, $key] = publicBucketWithKey();

    $object = json(uploadObject($key, $project['id'], $bucket['name'], 'notes.txt', 'just text'));

    $response = api()->get("/api/v1/{$project['id']}/storage/public/{$bucket['name']}/{$object['id']}");
    $headers = array_change_key_case($response->getHeaders(), CASE_LOWER);

    expect($headers['content-disposition'])->toStartWith('inline;');
    expect($headers['content-type'])->toBe('text/plain; charset=utf-8');
    expect($headers['content-security-policy'])->toContain('sandbox');
});

test('object paths cannot inject headers through the download filename', function () {
    [$project, $bucket, $key] = publicBucketWithKey();

    $object = json(uploadObject($key, $project['id'], $bucket['name'], "a\"b\r\nSet-Cookie: x=1.txt", 'hi'));

    $response = api()->get("/api/v1/{$project['id']}/storage/public/{$bucket['name']}/{$object['id']}");
    $headers = array_change_key_case($response->getHeaders(), CASE_LOWER);

    expect($headers)->not->toHaveKey('set-cookie');
    expect($headers['content-disposition'])->not->toMatch('/[\r\n]/');
    expect($headers['content-disposition'])->toContain('filename="a_bSet-Cookie: x=1.txt"');
});

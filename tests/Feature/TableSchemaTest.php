<?php

use App\Database;
use App\Controllers\Tables;

// --- Renaming a table ---

test('404s renaming a table_id that belongs to a different project the caller does own', function () {
    $owner = registerPlatformUser();
    $projectA = createProject($owner['token']);
    $projectB = createProject($owner['token']);
    $table = createTable($owner['token'], $projectA['id'], 'posts', []);

    $response = api()->patch("/api/v1/projects/{$projectB['id']}/tables/{$table['id']}", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['name' => 'x'],
    ]);

    expect($response->getStatusCode())->toBe(404);
});

test('500s when the physical rename collides with an existing table', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    $table = createTable($owner['token'], $project['id'], 'posts', []);
    $collidingName = uniqueSlug('taken');

    Database::getConn('default')->exec('CREATE TABLE `' . Tables::physicalName(projectInternalId($project['id']), $collidingName) . '` (id INT)');

    $response = api()->patch("/api/v1/projects/{$project['id']}/tables/{$table['id']}", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['name' => $collidingName],
    ]);

    expect($response->getStatusCode())->toBe(500);

    // Nothing changed: the table still answers under its original name.
    $stillThere = api()->get("/api/v1/projects/{$project['id']}/tables", ['headers' => ['Authorization' => "Bearer {$owner['token']}"]]);
    expect(array_column(json($stillThere), 'name'))->toContain('posts');
});

test('renames a table, and the physical data survives the rename', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    $table = createTable($owner['token'], $project['id'], 'posts', [['name' => 'title', 'type' => 'text']]);
    $key = createApiKey($owner['token'], $project['id'], ['select', 'insert']);
    createRlsPolicy($owner['token'], $project['id'], $table['id'], ['operation' => 'ALL', 'conditions' => []]);

    api()->post("/api/v1/{$project['id']}/rest/posts", [
        'headers' => ['Authorization' => "Bearer {$key['key']}"],
        'json'    => ['title' => 'still here'],
    ]);

    $rename = api()->patch("/api/v1/projects/{$project['id']}/tables/{$table['id']}", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['name' => 'articles'],
    ]);
    expect($rename->getStatusCode())->toBe(200);
    expect(json($rename)['name'])->toBe('articles');

    $oldName = api()->get("/api/v1/{$project['id']}/rest/posts", ['headers' => ['Authorization' => "Bearer {$key['key']}"]]);
    expect($oldName->getStatusCode())->toBe(404);

    $newName = api()->get("/api/v1/{$project['id']}/rest/articles", ['headers' => ['Authorization' => "Bearer {$key['key']}"]]);
    expect($newName->getStatusCode())->toBe(200);
    expect(json($newName)[0]['title'])->toBe('still here');
});

test('renaming a table to its own current name is a no-op success', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    $table = createTable($owner['token'], $project['id'], 'posts', []);

    $response = api()->patch("/api/v1/projects/{$project['id']}/tables/{$table['id']}", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['name' => 'posts'],
    ]);

    expect($response->getStatusCode())->toBe(200);
});

test('rejects renaming a table to a name already used in the same project', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    createTable($owner['token'], $project['id'], 'posts', []);
    $table = createTable($owner['token'], $project['id'], 'comments', []);

    $response = api()->patch("/api/v1/projects/{$project['id']}/tables/{$table['id']}", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['name' => 'posts'],
    ]);

    expect($response->getStatusCode())->toBe(409);
});

test('rejects renaming a table to an invalid identifier', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    $table = createTable($owner['token'], $project['id'], 'posts', []);

    $response = api()->patch("/api/v1/projects/{$project['id']}/tables/{$table['id']}", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['name' => 'not valid!'],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('rejects renaming a table to a name too long once prefixed', function () {
    $owner = registerPlatformUser();
    $project = createProject($owner['token']);
    $table = createTable($owner['token'], $project['id'], 'posts', []);

    $response = api()->patch("/api/v1/projects/{$project['id']}/tables/{$table['id']}", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['name' => str_repeat('a', 100)],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('404s renaming a table that does not belong to the caller', function () {
    $owner = registerPlatformUser();
    $intruder = registerPlatformUser();
    $project = createProject($owner['token']);
    $table = createTable($owner['token'], $project['id'], 'posts', []);

    $response = api()->patch("/api/v1/projects/{$project['id']}/tables/{$table['id']}", [
        'headers' => ['Authorization' => "Bearer {$intruder['token']}"],
        'json'    => ['name' => 'x'],
    ]);

    expect($response->getStatusCode())->toBe(404);
});

// --- Adding a column ---

test('adds a column to an existing table, appended after the existing ones', function () {
    [$token, $project, $table] = postsTableForRls();

    $response = api()->post("/api/v1/projects/{$project['id']}/tables/{$table['id']}/columns", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json'    => ['name' => 'views', 'type' => 'integer', 'nullable' => true],
    ]);

    expect($response->getStatusCode())->toBe(201);
    $col = json($response);
    expect($col['name'])->toBe('views');
    expect($col['position'])->toBe(2); // after title (0) and created_by (1)

    $key = createApiKey($token, $project['id'], ['select', 'insert']);
    createRlsPolicy($token, $project['id'], $table['id'], ['operation' => 'ALL', 'conditions' => []]);
    $insert = api()->post("/api/v1/{$project['id']}/rest/posts", [
        'headers' => ['Authorization' => "Bearer {$key['key']}"],
        'json'    => ['title' => 'x', 'views' => 5],
    ]);
    expect($insert->getStatusCode())->toBe(200);
    expect(json($insert)['views'])->toBe(5);
});

test('rejects adding a duplicate column name', function () {
    [$token, $project, $table] = postsTableForRls();

    $response = api()->post("/api/v1/projects/{$project['id']}/tables/{$table['id']}/columns", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json'    => ['name' => 'title', 'type' => 'text'],
    ]);

    expect($response->getStatusCode())->toBe(409);
});

test('rejects adding a column named id', function () {
    [$token, $project, $table] = postsTableForRls();

    $response = api()->post("/api/v1/projects/{$project['id']}/tables/{$table['id']}/columns", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json'    => ['name' => 'id', 'type' => 'text'],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('rejects adding a column with an invalid name or type', function () {
    [$token, $project, $table] = postsTableForRls();

    $badName = api()->post("/api/v1/projects/{$project['id']}/tables/{$table['id']}/columns", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json'    => ['name' => 'not valid!', 'type' => 'text'],
    ]);
    expect($badName->getStatusCode())->toBe(422);

    $badType = api()->post("/api/v1/projects/{$project['id']}/tables/{$table['id']}/columns", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json'    => ['name' => 'ok', 'type' => 'not-a-type'],
    ]);
    expect($badType->getStatusCode())->toBe(422);
});

test('rejects adding a column with an empty name', function () {
    [$token, $project, $table] = postsTableForRls();

    $response = api()->post("/api/v1/projects/{$project['id']}/tables/{$table['id']}/columns", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json'    => ['name' => '', 'type' => 'text'],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('500s when the physical ADD COLUMN collides with a column that exists outside the tracked metadata', function () {
    [$token, $project, $table] = postsTableForRls();

    Database::getConn('default')->exec(
        'ALTER TABLE `' . Tables::physicalName(projectInternalId($project['id']), 'posts') . '` ADD COLUMN `ghost` TEXT'
    );

    $response = api()->post("/api/v1/projects/{$project['id']}/tables/{$table['id']}/columns", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json'    => ['name' => 'ghost', 'type' => 'text'],
    ]);

    expect($response->getStatusCode())->toBe(500);

    $columns = json(api()->get("/api/v1/projects/{$project['id']}/tables", ['headers' => ['Authorization' => "Bearer {$token}"]]))[0]['columns'];
    expect(array_column($columns, 'name'))->not->toContain('ghost');
});

test('404s adding a column to a table the caller does not own', function () {
    [$token, $project, $table] = postsTableForRls();
    $intruder = registerPlatformUser();

    $response = api()->post("/api/v1/projects/{$project['id']}/tables/{$table['id']}/columns", [
        'headers' => ['Authorization' => "Bearer {$intruder['token']}"],
        'json'    => ['name' => 'x', 'type' => 'text'],
    ]);

    expect($response->getStatusCode())->toBe(404);
});

// --- Updating a column ---

test('renames a column, and existing data survives under the new name', function () {
    [$token, $project, $table] = postsTableForRls();
    $key = createApiKey($token, $project['id'], ['select', 'insert']);
    createRlsPolicy($token, $project['id'], $table['id'], ['operation' => 'ALL', 'conditions' => []]);
    api()->post("/api/v1/{$project['id']}/rest/posts", [
        'headers' => ['Authorization' => "Bearer {$key['key']}"],
        'json'    => ['title' => 'hello'],
    ]);
    $columnId = json(api()->get("/api/v1/projects/{$project['id']}/tables", ['headers' => ['Authorization' => "Bearer {$token}"]]))[0]['columns'][0]['id'];

    $response = api()->patch("/api/v1/projects/{$project['id']}/tables/{$table['id']}/columns/{$columnId}", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json'    => ['name' => 'headline'],
    ]);
    expect($response->getStatusCode())->toBe(200);
    expect(json($response)['name'])->toBe('headline');

    $row = json(api()->get("/api/v1/{$project['id']}/rest/posts", ['headers' => ['Authorization' => "Bearer {$key['key']}"]]))[0];
    expect($row)->toHaveKey('headline');
    expect($row['headline'])->toBe('hello');
    expect($row)->not->toHaveKey('title');
});

test('changes a column\'s type, nullability, and default', function () {
    [$token, $project, $table] = postsTableForRls();
    $columnId = json(api()->get("/api/v1/projects/{$project['id']}/tables", ['headers' => ['Authorization' => "Bearer {$token}"]]))[0]['columns'][1]['id']; // created_by

    $response = api()->patch("/api/v1/projects/{$project['id']}/tables/{$table['id']}/columns/{$columnId}", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json'    => ['type' => 'text', 'nullable' => false, 'default_value' => 'anonymous'],
    ]);

    expect($response->getStatusCode())->toBe(200);
    $col = json($response);
    expect($col['type'])->toBe('text');
    expect($col['nullable'])->toBeFalse();
    expect($col['default_value'])->toBe('anonymous');

    $key = createApiKey($token, $project['id'], ['select', 'insert']);
    createRlsPolicy($token, $project['id'], $table['id'], ['operation' => 'ALL', 'conditions' => []]);
    $insert = api()->post("/api/v1/{$project['id']}/rest/posts", [
        'headers' => ['Authorization' => "Bearer {$key['key']}"],
        'json'    => ['title' => 'x'],
    ]);
    expect(json($insert)['created_by'])->toBe('anonymous');
});

test('rejects renaming a column to one that already exists on the table', function () {
    [$token, $project, $table] = postsTableForRls();
    $columns = json(api()->get("/api/v1/projects/{$project['id']}/tables", ['headers' => ['Authorization' => "Bearer {$token}"]]))[0]['columns'];
    $createdByColumnId = $columns[1]['id'];

    $response = api()->patch("/api/v1/projects/{$project['id']}/tables/{$table['id']}/columns/{$createdByColumnId}", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json'    => ['name' => 'title'],
    ]);

    expect($response->getStatusCode())->toBe(409);
});

test('404s updating a column via a table_id that belongs to a different project', function () {
    $owner = registerPlatformUser();
    $projectA = createProject($owner['token']);
    $projectB = createProject($owner['token']);
    $table = createTable($owner['token'], $projectA['id'], 'posts', [['name' => 'x', 'type' => 'text']]);
    $columnId = json(api()->get("/api/v1/projects/{$projectA['id']}/tables", ['headers' => ['Authorization' => "Bearer {$owner['token']}"]]))[0]['columns'][0]['id'];

    $response = api()->patch("/api/v1/projects/{$projectB['id']}/tables/{$table['id']}/columns/{$columnId}", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
        'json'    => ['name' => 'y'],
    ]);

    expect($response->getStatusCode())->toBe(404);
});

test('rejects renaming a column to an empty or invalid name', function () {
    [$token, $project, $table] = postsTableForRls();
    $columnId = json(api()->get("/api/v1/projects/{$project['id']}/tables", ['headers' => ['Authorization' => "Bearer {$token}"]]))[0]['columns'][0]['id'];

    $empty = api()->patch("/api/v1/projects/{$project['id']}/tables/{$table['id']}/columns/{$columnId}", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json'    => ['name' => ''],
    ]);
    expect($empty->getStatusCode())->toBe(422);

    $invalid = api()->patch("/api/v1/projects/{$project['id']}/tables/{$table['id']}/columns/{$columnId}", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json'    => ['name' => 'not valid!'],
    ]);
    expect($invalid->getStatusCode())->toBe(422);
});

test('rejects renaming a column to id', function () {
    [$token, $project, $table] = postsTableForRls();
    $columnId = json(api()->get("/api/v1/projects/{$project['id']}/tables", ['headers' => ['Authorization' => "Bearer {$token}"]]))[0]['columns'][0]['id'];

    $response = api()->patch("/api/v1/projects/{$project['id']}/tables/{$table['id']}/columns/{$columnId}", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json'    => ['name' => 'id'],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('rejects an invalid type when updating a column', function () {
    [$token, $project, $table] = postsTableForRls();
    $columnId = json(api()->get("/api/v1/projects/{$project['id']}/tables", ['headers' => ['Authorization' => "Bearer {$token}"]]))[0]['columns'][0]['id'];

    $response = api()->patch("/api/v1/projects/{$project['id']}/tables/{$table['id']}/columns/{$columnId}", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json'    => ['type' => 'not-a-type'],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('500s when the physical CHANGE COLUMN collides with a column outside the tracked metadata', function () {
    [$token, $project, $table] = postsTableForRls();
    $columnId = json(api()->get("/api/v1/projects/{$project['id']}/tables", ['headers' => ['Authorization' => "Bearer {$token}"]]))[0]['columns'][1]['id']; // created_by

    Database::getConn('default')->exec(
        'ALTER TABLE `' . Tables::physicalName(projectInternalId($project['id']), 'posts') . '` ADD COLUMN `ghost` TEXT'
    );

    $response = api()->patch("/api/v1/projects/{$project['id']}/tables/{$table['id']}/columns/{$columnId}", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json'    => ['name' => 'ghost'],
    ]);

    expect($response->getStatusCode())->toBe(500);

    // The column keeps its original name in metadata — nothing was applied.
    $columns = json(api()->get("/api/v1/projects/{$project['id']}/tables", ['headers' => ['Authorization' => "Bearer {$token}"]]))[0]['columns'];
    expect($columns[1]['name'])->toBe('created_by');
});

test('404s updating a column that does not exist', function () {
    [$token, $project, $table] = postsTableForRls();

    $response = api()->patch("/api/v1/projects/{$project['id']}/tables/{$table['id']}/columns/999999999", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json'    => ['name' => 'x'],
    ]);

    expect($response->getStatusCode())->toBe(404);
});

// --- Removing a column ---

test('requires confirm=true to remove a column', function () {
    [$token, $project, $table] = postsTableForRls();
    $columnId = json(api()->get("/api/v1/projects/{$project['id']}/tables", ['headers' => ['Authorization' => "Bearer {$token}"]]))[0]['columns'][1]['id'];

    $response = api()->delete("/api/v1/projects/{$project['id']}/tables/{$table['id']}/columns/{$columnId}", [
        'headers' => ['Authorization' => "Bearer {$token}"],
    ]);

    expect($response->getStatusCode())->toBe(422);
});

test('removes a column when confirmed, and the physical column is really gone', function () {
    [$token, $project, $table] = postsTableForRls();
    $columnId = json(api()->get("/api/v1/projects/{$project['id']}/tables", ['headers' => ['Authorization' => "Bearer {$token}"]]))[0]['columns'][1]['id']; // created_by

    $response = api()->delete("/api/v1/projects/{$project['id']}/tables/{$table['id']}/columns/{$columnId}?confirm=true", [
        'headers' => ['Authorization' => "Bearer {$token}"],
    ]);
    expect($response->getStatusCode())->toBe(204);

    $columns = json(api()->get("/api/v1/projects/{$project['id']}/tables", ['headers' => ['Authorization' => "Bearer {$token}"]]))[0]['columns'];
    expect(array_column($columns, 'name'))->not->toContain('created_by');

    // The physical column is gone too, so re-adding it with the same name must work.
    $readd = api()->post("/api/v1/projects/{$project['id']}/tables/{$table['id']}/columns", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json'    => ['name' => 'created_by', 'type' => 'integer', 'nullable' => true],
    ]);
    expect($readd->getStatusCode())->toBe(201);
});

test('404s removing a column via a table_id that belongs to a different project', function () {
    $owner = registerPlatformUser();
    $projectA = createProject($owner['token']);
    $projectB = createProject($owner['token']);
    $table = createTable($owner['token'], $projectA['id'], 'posts', [['name' => 'x', 'type' => 'text']]);
    $columnId = json(api()->get("/api/v1/projects/{$projectA['id']}/tables", ['headers' => ['Authorization' => "Bearer {$owner['token']}"]]))[0]['columns'][0]['id'];

    $response = api()->delete("/api/v1/projects/{$projectB['id']}/tables/{$table['id']}/columns/{$columnId}?confirm=true", [
        'headers' => ['Authorization' => "Bearer {$owner['token']}"],
    ]);

    expect($response->getStatusCode())->toBe(404);
});

test('500s when the physical column was already removed outside the tracked metadata', function () {
    [$token, $project, $table] = postsTableForRls();
    $columnId = json(api()->get("/api/v1/projects/{$project['id']}/tables", ['headers' => ['Authorization' => "Bearer {$token}"]]))[0]['columns'][1]['id']; // created_by

    Database::getConn('default')->exec(
        'ALTER TABLE `' . Tables::physicalName(projectInternalId($project['id']), 'posts') . '` DROP COLUMN `created_by`'
    );

    $response = api()->delete("/api/v1/projects/{$project['id']}/tables/{$table['id']}/columns/{$columnId}?confirm=true", [
        'headers' => ['Authorization' => "Bearer {$token}"],
    ]);

    expect($response->getStatusCode())->toBe(500);

    // Metadata still has the (now physically nonexistent) column, since the DDL never succeeded.
    $columns = json(api()->get("/api/v1/projects/{$project['id']}/tables", ['headers' => ['Authorization' => "Bearer {$token}"]]))[0]['columns'];
    expect(array_column($columns, 'name'))->toContain('created_by');
});

test('404s removing a column that does not exist', function () {
    [$token, $project, $table] = postsTableForRls();

    $response = api()->delete("/api/v1/projects/{$project['id']}/tables/{$table['id']}/columns/999999999?confirm=true", [
        'headers' => ['Authorization' => "Bearer {$token}"],
    ]);

    expect($response->getStatusCode())->toBe(404);
});

<?php

use App\Database;

test('getConn throws for a connection that was never configured', function () {
    Database::getConn('does-not-exist-' . uniqid());
})->throws(Exception::class, "not configured");

test('getConn auto-creates the database on first connect if it is missing, then reuses the connection', function () {
    $key = 'autocreate_test';
    $dbName = 'loxodontu_autocreate_' . bin2hex(random_bytes(4));

    Database::configure($key, 'mysql', [
        'host'     => env('DB_HOST_TEST'),
        'port'     => env('DB_PORT_TEST'),
        'username' => env('DB_USERNAME_TEST'),
        'password' => env('DB_PASSWORD_TEST'),
        'database' => $dbName,
    ]);

    $pdo = Database::getConn($key);
    expect($pdo)->toBeInstanceOf(PDO::class);
    expect((int) $pdo->query('SELECT 1')->fetchColumn())->toBe(1);

    // A second call must reuse the same lazily-created instance, not reconnect.
    expect(Database::getConn($key))->toBe($pdo);

    $pdo->exec("DROP DATABASE IF EXISTS `{$dbName}`");
});

test('supports a sqlite connection', function () {
    $key = 'sqlite_test_' . uniqid();
    $path = sys_get_temp_dir() . '/loxodontu_test_' . uniqid() . '.sqlite';

    Database::configure($key, 'sqlite', ['path' => $path]);
    $pdo = Database::getConn($key);

    expect($pdo)->toBeInstanceOf(PDO::class);
    expect((int) $pdo->query('SELECT 1')->fetchColumn())->toBe(1);

    // Not unlinking $path: Database keeps the PDO connection open for the rest of
    // the process (it's a singleton registry), which holds a lock on the file on
    // some platforms. It's a handful of bytes in the OS temp dir — harmless to leave.
});

test('getConn rejects an unsupported driver before ever attempting to connect', function () {
    $key = 'unsupported_driver_' . uniqid();

    Database::configure($key, 'not-a-real-driver', ['host' => 'localhost', 'database' => 'x']);

    Database::getConn($key);
})->throws(Exception::class, "not supported");

<?php

/**
 * Wipes and re-runs every migration against the "testing" database connection.
 * Run once before the test suite (see the `test`/`test:coverage` composer
 * scripts) — never inside the suite itself, since DDL auto-commits and can't
 * be scoped per test anyway.
 */

use AdaiasMagdiel\FullCrawl\MigrationManager;
use App\Database;

putenv('DB_MODE=testing');
$_ENV['DB_MODE'] = 'testing';

require_once __DIR__ . '/../bootstrap.php';

$pdo = Database::getConn('default');
$migrationsDir = __DIR__ . '/../database/migrations';

// wipe() drops every table, including the migrations_history one its own
// constructor just created — so run() needs a fresh manager afterward to
// re-create it, rather than reusing the same instance.
(new MigrationManager($pdo, $migrationsDir))->wipe();
(new MigrationManager($pdo, $migrationsDir))->run();

echo "Test database ready.\n";

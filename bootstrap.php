<?php

use App\Database;
use App\Storage\LocalDisk;
use Dotenv\Dotenv;

require_once __DIR__ . '/vendor/autoload.php';

Dotenv::createImmutable(__DIR__)->safeLoad();

$suffix = match (env('DB_MODE')) {
    'development' => 'DEV',
    'testing'     => 'TEST',
    default       => 'PROD',
};

Database::configure('default', 'mysql', [
    'host' => env("DB_HOST_$suffix"),
    'port' => env("DB_PORT_$suffix"),
    'username' => env("DB_USERNAME_$suffix"),
    'password' => env("DB_PASSWORD_$suffix"),
    'database' => env("DB_DATABASE_$suffix"),
]);

LocalDisk::configure(env('STORAGE_PATH', ROOT_DIR . '/storage/files'));

if (isDev()) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

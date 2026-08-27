<?php

/**
 * Router for `php -S localhost:8000 router.php`.
 * Mirrors .htaccess, minus the HTTPS/www redirects.
 */

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$path = ltrim($uri, '/');
$file = __DIR__ . DIRECTORY_SEPARATOR . $path;

// Allow access to static files under assets/ and public/
if (is_file($file) && (str_starts_with($path, 'assets/') || str_starts_with($path, 'public/'))) {
    return false;
}

// Block direct access to PHP files, except index.php
if (str_ends_with(strtolower($path), '.php') && $path !== 'index.php') {
    http_response_code(404);
    require __DIR__ . '/templates/404.php';
    return true;
}

// Redirect all other requests to index.php
require __DIR__ . '/index.php';

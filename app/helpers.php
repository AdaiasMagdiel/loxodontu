<?php

/**
 * The absolute path to the project's root directory.
 */
define('ROOT_DIR', dirname(__DIR__));

/**
 * Retrieves the value of an environment variable.
 *
 * Checks $_ENV first, then falls back to getenv() -- some SAPIs and
 * process managers (e.g. php-fpm pools with `env[...]` directives) only
 * populate the latter.
 *
 * @param string $key     The environment variable name.
 * @param mixed  $default The default value to return if the key is not set.
 * @return mixed The value of the environment variable or the default value.
 */
function env(string $key, mixed $default = null): mixed
{
    if (array_key_exists($key, $_ENV)) {
        return $_ENV[$key];
    }

    $value = getenv($key);

    return $value === false ? $default : $value;
}

/**
 * Checks if the current application environment is set to development.
 *
 * @return bool True if the 'ENV' variable is 'development', false otherwise.
 */
function isDev(): bool
{
    return env('ENV') === 'development';
}

/**
 * Resolves a template name to its absolute file path within the templates directory.
 * * Automatically handles both 'path/to/view' and 'path/to/view.php' formats.
 *
 * @param string $path The relative path to the template.
 * @return string The absolute path to the template file.
 */
function t(string $path): string
{
    if (str_ends_with(strtolower($path), '.php')) {
        $path = substr($path, 0, -4);
    }

    return ROOT_DIR . '/templates/' . ltrim($path, '/') . '.php';
}

/**
 * Escapes a string for safe output in an HTML context to prevent XSS attacks.
 *
 * @param string|null $value The raw string to be escaped.
 * @return string The escaped HTML string.
 */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

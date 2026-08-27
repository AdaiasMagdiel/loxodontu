<?php

test('env reads from $_ENV first, then falls back to getenv, then to the default', function () {
    $_ENV['HELPERS_TEST_VAR'] = 'from-env-array';
    expect(env('HELPERS_TEST_VAR'))->toBe('from-env-array');
    unset($_ENV['HELPERS_TEST_VAR']);

    putenv('HELPERS_TEST_VAR=from-getenv');
    expect(env('HELPERS_TEST_VAR'))->toBe('from-getenv');
    putenv('HELPERS_TEST_VAR');

    expect(env('HELPERS_TEST_VAR_MISSING', 'fallback'))->toBe('fallback');
});

test('isDev reflects the ENV variable', function () {
    $original = $_ENV['ENV'] ?? null;

    try {
        $_ENV['ENV'] = 'development';
        expect(isDev())->toBeTrue();

        $_ENV['ENV'] = 'testing';
        expect(isDev())->toBeFalse();
    } finally {
        // Restore, since the app's own exception handler checks isDev() and every
        // other test in this process depends on it staying "testing".
        $_ENV['ENV'] = $original;
    }
});

test('t resolves a template path with or without a .php extension', function () {
    expect(t('404'))->toBe(ROOT_DIR . '/templates/404.php');
    expect(t('404.php'))->toBe(ROOT_DIR . '/templates/404.php');
    expect(t('/404'))->toBe(ROOT_DIR . '/templates/404.php');
});

test('e escapes HTML special characters and tolerates null', function () {
    expect(e('<script>alert(1)</script>'))->toBe('&lt;script&gt;alert(1)&lt;/script&gt;');
    expect(e(null))->toBe('');
});

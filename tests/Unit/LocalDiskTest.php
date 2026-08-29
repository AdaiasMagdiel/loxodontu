<?php

use App\Storage\LocalDisk;

test('path() throws if configure() was never called', function () {
    $reflection = new ReflectionClass(LocalDisk::class);
    $property   = $reflection->getProperty('root');
    $property->setAccessible(true);
    $original   = $property->getValue();

    try {
        $property->setValue(null, null);
        LocalDisk::path(1, 1, 1);
    } finally {
        $property->setValue(null, $original);
    }
})->throws(RuntimeException::class, 'LocalDisk root is not configured.');

test('configure() strips a trailing slash from the root', function () {
    $root = sys_get_temp_dir() . '/loxodontu-localdisk-test-' . bin2hex(random_bytes(4));

    LocalDisk::configure($root . '/');

    expect(LocalDisk::path(1, 2, 3))->toBe("{$root}/1/2/3");

    // Restore the real root so later tests (feature suite) keep working.
    LocalDisk::configure(env('STORAGE_PATH', ROOT_DIR . '/storage/files'));
});

test('put() writes the file to disk, creating directories as needed, and delete() removes it', function () {
    $root = sys_get_temp_dir() . '/loxodontu-localdisk-test-' . bin2hex(random_bytes(4));
    LocalDisk::configure($root);

    $tmpFile = tempnam(sys_get_temp_dir(), 'loxodontu-src-');
    file_put_contents($tmpFile, 'hello disk');

    LocalDisk::put(1, 2, 3, $tmpFile);

    $target = LocalDisk::path(1, 2, 3);
    expect(is_file($target))->toBeTrue();
    expect(file_get_contents($target))->toBe('hello disk');

    LocalDisk::delete(1, 2, 3);
    expect(is_file($target))->toBeFalse();

    // Deleting a file that was never written is a no-op, not an error.
    LocalDisk::delete(1, 2, 3);

    LocalDisk::configure(env('STORAGE_PATH', ROOT_DIR . '/storage/files'));
});

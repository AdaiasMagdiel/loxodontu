<?php

namespace App\Storage;

use RuntimeException;

/**
 * Local filesystem backend for Storage. Files are written under
 * {root}/{project_id}/{bucket_id}/{object_id} — the filename on disk is always
 * the object's numeric id, never derived from the client-supplied logical
 * `path`, so there is no path-traversal surface to defend against.
 */
class LocalDisk
{
    private static ?string $root = null;

    public static function configure(string $root): void
    {
        self::$root = rtrim($root, '/');
    }

    public static function path(int $projectId, int $bucketId, int $objectId): string
    {
        if (self::$root === null) {
            throw new RuntimeException('LocalDisk root is not configured.');
        }

        return sprintf('%s/%d/%d/%d', self::$root, $projectId, $bucketId, $objectId);
    }

    public static function put(int $projectId, int $bucketId, int $objectId, string $sourceTmpPath): void
    {
        $target = self::path($projectId, $bucketId, $objectId);
        $dir    = dirname($target);

        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException("Could not create storage directory: {$dir}");
        }

        if (!move_uploaded_file($sourceTmpPath, $target) && !rename($sourceTmpPath, $target)) {
            throw new RuntimeException("Could not store uploaded file at: {$target}");
        }
    }

    public static function delete(int $projectId, int $bucketId, int $objectId): void
    {
        $path = self::path($projectId, $bucketId, $objectId);

        if (is_file($path)) {
            unlink($path);
        }
    }
}

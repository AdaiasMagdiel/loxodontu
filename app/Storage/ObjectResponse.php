<?php

namespace App\Storage;

use AdaiasMagdiel\Erlenmeyer\Response;

/**
 * How stored objects are typed on upload and served on download.
 *
 * Uploaded files are tenant-controlled and served from the same origin as
 * the dashboard, so an HTML or SVG file rendered inline could run script
 * with the dashboard's privileges. To prevent that:
 *
 *  - the MIME type is detected from the file's contents, never taken from
 *    the client's multipart `Content-Type`;
 *  - only passive media types are ever served `inline` — everything else is
 *    an `attachment`;
 *  - every response carries `nosniff` and a `sandbox` CSP, so even a
 *    browser that renders the body anyway gets an opaque origin with no
 *    script execution.
 */
class ObjectResponse
{
    /** Types safe to render inline: they can't execute script in the page's origin. */
    private const INLINE_TYPES = [
        'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/avif', 'image/bmp', 'image/x-icon',
        'audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/webm', 'audio/flac',
        'video/mp4', 'video/webm', 'video/ogg',
        'application/pdf', 'text/plain',
    ];

    public static function detectMimeType(string $path): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $type = $finfo->file($path);

        return is_string($type) && $type !== '' ? $type : 'application/octet-stream';
    }

    /** @param array{path: string, mime_type: ?string} $object */
    public static function send(Response $res, string $diskPath, array $object): Response
    {
        $type = strtolower(trim((string) ($object['mime_type'] ?? '')));
        if ($type === '' || !preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#', $type)) {
            $type = 'application/octet-stream';
        }

        $inline = in_array($type, self::INLINE_TYPES, true);

        return $res->withFile($diskPath)
            ->setContentType($type === 'text/plain' ? 'text/plain; charset=utf-8' : $type)
            ->setHeader('Content-Disposition', self::contentDisposition($inline ? 'inline' : 'attachment', (string) $object['path']))
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Content-Security-Policy', "default-src 'none'; sandbox")
            ->setHeader('Cross-Origin-Resource-Policy', 'cross-origin');
    }

    /**
     * Builds a header-safe Content-Disposition (RFC 6266): an ASCII-only
     * fallback `filename` with quotes, backslashes and control characters
     * stripped, plus the UTF-8 name in `filename*`.
     */
    private static function contentDisposition(string $disposition, string $path): string
    {
        $name = basename(str_replace('\\', '/', $path));
        $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name) ?? '';
        if ($name === '') {
            $name = 'download';
        }

        $ascii = preg_replace('/[^\x20-\x7E]|["\\\\]/', '_', $name) ?? 'download';

        return sprintf('%s; filename="%s"; filename*=UTF-8\'\'%s', $disposition, $ascii, rawurlencode($name));
    }
}

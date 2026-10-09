<?php

namespace App;

use RuntimeException;

/**
 * AES-256-GCM helper for secrets that must be recoverable later (SMTP
 * passwords, email-API keys) — unlike auth tokens, which are only ever
 * hashed. Key comes from APP_KEY (base64, 32 raw bytes), resolved lazily so
 * commands that never touch encrypted data don't require it to be set.
 */
class Crypto
{
    private const CIPHER = 'aes-256-gcm';
    private const NONCE_LENGTH = 12;
    private const TAG_LENGTH = 16;

    public static function encrypt(string $plaintext): string
    {
        $key = self::key();
        $nonce = random_bytes(self::NONCE_LENGTH);

        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($ciphertext === false) {
            throw new RuntimeException('Failed to encrypt value.');
        }

        return base64_encode($nonce . $tag . $ciphertext);
    }

    public static function decrypt(string $payload): string
    {
        $key = self::key();
        $raw = base64_decode($payload, true);

        if ($raw === false || strlen($raw) < self::NONCE_LENGTH + self::TAG_LENGTH) {
            throw new RuntimeException('Invalid encrypted payload.');
        }

        $nonce = substr($raw, 0, self::NONCE_LENGTH);
        $tag = substr($raw, self::NONCE_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($raw, self::NONCE_LENGTH + self::TAG_LENGTH);

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($plaintext === false) {
            throw new RuntimeException('Failed to decrypt value.');
        }

        return $plaintext;
    }

    private static function key(): string
    {
        $encoded = env('APP_KEY', '');
        if ($encoded === '') {
            throw new RuntimeException('APP_KEY is not set. Generate one with: openssl rand -base64 32');
        }

        $key = base64_decode($encoded, true);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('APP_KEY must be a base64-encoded 32-byte key.');
        }

        return $key;
    }
}

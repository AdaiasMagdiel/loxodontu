<?php

use App\Crypto;

test('encrypts and decrypts a round trip', function () {
    $plaintext = 'super-secret-smtp-password';

    $encrypted = Crypto::encrypt($plaintext);

    expect($encrypted)->not->toBe($plaintext);
    expect(Crypto::decrypt($encrypted))->toBe($plaintext);
});

test('produces a different ciphertext each time (random nonce)', function () {
    $a = Crypto::encrypt('same-value');
    $b = Crypto::encrypt('same-value');

    expect($a)->not->toBe($b);
});

test('rejects a tampered payload', function () {
    $encrypted = Crypto::encrypt('some-value');
    $tampered = substr($encrypted, 0, -4) . 'abcd';

    expect(fn () => Crypto::decrypt($tampered))->toThrow(RuntimeException::class);
});

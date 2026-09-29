<?php
declare(strict_types=1);

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(403);
    exit('Direct access not permitted.');
}

// Encrypts a value using a key that lives only in .env (APP_ENCRYPTION_KEY),
// never in the database — so a database compromise alone doesn't expose
// SSO client secrets, only a compromise of both the database AND the
// server's environment would.
function app_encrypt(string $plaintext): string
{
    $key = app_encryption_key();
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);
    return base64_encode($nonce . $cipher);
}

function app_decrypt(string $encoded): ?string
{
    $key = app_encryption_key();
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        return null;
    }
    $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plaintext = sodium_crypto_secretbox_open($cipher, $nonce, $key);
    return $plaintext === false ? null : $plaintext;
}

function app_encryption_key(): string
{
    $b64 = getenv('APP_ENCRYPTION_KEY') ?: '';
    if ($b64 === '') {
        throw new RuntimeException('APP_ENCRYPTION_KEY is not set. Generate one with: php -r "echo base64_encode(random_bytes(32));"');
    }
    $key = base64_decode($b64, true);
    if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        throw new RuntimeException('APP_ENCRYPTION_KEY is not a valid base64-encoded 32-byte key.');
    }
    return $key;
}

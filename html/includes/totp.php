<?php
declare(strict_types=1);

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(403);
    exit('Direct access not permitted.');
}

const TOTP_BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function totp_generate_secret(): string
{
    $bytes = random_bytes(20); // 160 bits, the RFC 4226 recommended length
    return totp_base32_encode($bytes);
}

function totp_base32_encode(string $data): string
{
    $bits = '';
    foreach (str_split($data) as $byte) {
        $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
    }
    $bits = str_pad($bits, (int)(ceil(strlen($bits) / 5) * 5), '0', STR_PAD_RIGHT);
    $output = '';
    foreach (str_split($bits, 5) as $chunk) {
        $output .= TOTP_BASE32_ALPHABET[bindec($chunk)];
    }
    return $output;
}

function totp_base32_decode(string $secret): string
{
    $secret = strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret));
    $bits = '';
    foreach (str_split($secret) as $char) {
        $pos = strpos(TOTP_BASE32_ALPHABET, $char);
        if ($pos === false) {
            continue;
        }
        $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $bytes = '';
    foreach (str_split($bits, 8) as $byteBits) {
        if (strlen($byteBits) < 8) {
            break;
        }
        $bytes .= chr(bindec($byteBits));
    }
    return $bytes;
}

// Computes the 6-digit TOTP code for a given 30-second time step, per
// RFC 6238 (time-based) built on RFC 4226 (HMAC-based one-time password).
function totp_code_at(string $secret, int $timeStep): string
{
    $key = totp_base32_decode($secret);
    $counterBytes = pack('N*', 0, $timeStep); // 8-byte big-endian counter
    $hash = hash_hmac('sha1', $counterBytes, $key, true);
    $offset = ord($hash[19]) & 0x0f;
    $truncated = ((ord($hash[$offset]) & 0x7f) << 24)
        | (ord($hash[$offset + 1]) << 16)
        | (ord($hash[$offset + 2]) << 8)
        | ord($hash[$offset + 3]);
    return str_pad((string)($truncated % 1000000), 6, '0', STR_PAD_LEFT);
}

function totp_current_code(string $secret): string
{
    return totp_code_at($secret, (int)floor(time() / 30));
}

// Allows the immediately preceding and following 30-second step too, so a
// small amount of clock drift between the server and the authenticator
// app's device doesn't cause valid codes to be rejected.
function totp_verify(string $secret, string $code): bool
{
    $code = trim($code);
    if (!preg_match('/^\d{6}$/', $code)) {
        return false;
    }
    $currentStep = (int)floor(time() / 30);
    for ($i = -1; $i <= 1; $i++) {
        if (hash_equals(totp_code_at($secret, $currentStep + $i), $code)) {
            return true;
        }
    }
    return false;
}

function totp_provisioning_uri(string $secret, string $username, string $issuer = 'LinksDirectory'): string
{
    $label = rawurlencode($issuer) . ':' . rawurlencode($username);
    // algorithm/digits/period are omitted deliberately: SHA1/6/30 are the
    // universal defaults every authenticator app already assumes, so
    // spelling them out just burns capacity for no benefit.
    $params = http_build_query([
        'secret' => $secret,
        'issuer' => $issuer,
    ]);
    return "otpauth://totp/{$label}?{$params}";
}

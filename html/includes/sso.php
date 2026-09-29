<?php
declare(strict_types=1);

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(403);
    exit('Direct access not permitted.');
}

const SSO_PROVIDERS = ['google' => 'Google', 'microsoft' => 'Microsoft'];

// Returns the fixed set of endpoints for a provider. For Microsoft, tenant
// defaults to "common" (any Microsoft or Entra account) if none is set —
// pass a specific tenant ID/domain on the connection to restrict sign-in to
// one organization only.
function sso_provider_endpoints(string $provider, ?string $tenantId = null): array
{
    if ($provider === 'google') {
        return [
            'authorize' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token' => 'https://oauth2.googleapis.com/token',
            'userinfo' => 'https://www.googleapis.com/oauth2/v3/userinfo',
        ];
    }
    if ($provider === 'microsoft') {
        $tenant = ($tenantId !== null && $tenantId !== '') ? $tenantId : 'common';
        return [
            'authorize' => "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/authorize",
            'token' => "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token",
            'userinfo' => 'https://graph.microsoft.com/oidc/userinfo',
        ];
    }
    throw new RuntimeException("Unknown SSO provider '{$provider}'.");
}

function get_sso_connections(PDO $pdo, bool $onlyEnabled = false): array
{
    $sql = 'SELECT * FROM sso_connections' . ($onlyEnabled ? ' WHERE enabled = 1' : '') . ' ORDER BY display_name';
    return $pdo->query($sql)->fetchAll();
}

function get_sso_connection(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM sso_connections WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// The single redirect URI used for every connection, regardless of
// provider — which connection initiated the request is tracked via the
// session, not the URL, so only one URI ever needs registering per
// provider console.
function sso_redirect_uri(): string
{
    $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return "https://{$domain}/auth/sso/callback.php";
}

function base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function sso_http_post_form(string $url, array $params): ?array
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        error_log("SSO token request to {$url} failed: {$curlError}");
        return null;
    }
    $data = json_decode($response, true);
    if ($code < 200 || $code >= 300) {
        error_log("SSO token request to {$url} returned HTTP {$code}: {$response}");
        return null;
    }
    return is_array($data) ? $data : null;
}

function sso_http_get_bearer(string $url, string $accessToken): ?array
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken, 'Accept: application/json']);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        error_log("SSO userinfo request to {$url} failed: {$curlError}");
        return null;
    }
    $data = json_decode($response, true);
    if ($code < 200 || $code >= 300) {
        error_log("SSO userinfo request to {$url} returned HTTP {$code}: {$response}");
        return null;
    }
    return is_array($data) ? $data : null;
}

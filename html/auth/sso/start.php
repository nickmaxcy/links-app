<?php
declare(strict_types=1);

require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../../includes/sso.php';

$pdo = get_pdo();

if (is_logged_in()) {
    header('Location: /');
    exit;
}

$connectionId = (int)($_GET['connection'] ?? 0);
$connection = get_sso_connection($pdo, $connectionId);

if (!$connection || !(int)$connection['enabled']) {
    http_response_code(404);
    exit('This sign-in option is not available.');
}

$nonce = bin2hex(random_bytes(16));
$verifier = base64url_encode(random_bytes(32));
$challenge = base64url_encode(hash('sha256', $verifier, true));

$_SESSION['sso_state'] = $nonce;
$_SESSION['sso_connection_id'] = $connectionId;
$_SESSION['sso_pkce_verifier'] = $verifier;

$endpoints = sso_provider_endpoints($connection['provider'], $connection['tenant_id']);

$params = [
    'client_id' => $connection['client_id'],
    'redirect_uri' => sso_redirect_uri(),
    'response_type' => 'code',
    'scope' => 'openid email profile',
    'state' => $nonce,
    'code_challenge' => $challenge,
    'code_challenge_method' => 'S256',
];
if ($connection['provider'] === 'google') {
    $params['access_type'] = 'online';
    $params['prompt'] = 'select_account';
}

header('Location: ' . $endpoints['authorize'] . '?' . http_build_query($params));
exit;

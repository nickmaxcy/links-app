<?php
declare(strict_types=1);

require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../../includes/sso.php';
require __DIR__ . '/../../includes/crypto.php';

$pdo = get_pdo();

if (is_logged_in()) {
    header('Location: /');
    exit;
}

$providerError = $_GET['error'] ?? null;
if ($providerError) {
    http_response_code(400);
    exit('Sign-in was cancelled or failed: ' . htmlspecialchars((string)$providerError, ENT_QUOTES, 'UTF-8'));
}

$code = $_GET['code'] ?? '';
$state = $_GET['state'] ?? '';

if ($code === '' || $state === '' || empty($_SESSION['sso_state']) || !hash_equals($_SESSION['sso_state'], $state)) {
    http_response_code(400);
    exit('Invalid or expired sign-in request. Please go back and try signing in again.');
}

$connectionId = (int)($_SESSION['sso_connection_id'] ?? 0);
$verifier = $_SESSION['sso_pkce_verifier'] ?? '';
unset($_SESSION['sso_state'], $_SESSION['sso_connection_id'], $_SESSION['sso_pkce_verifier']);

$connection = get_sso_connection($pdo, $connectionId);
if (!$connection || !(int)$connection['enabled']) {
    http_response_code(400);
    exit('This sign-in option is no longer available.');
}

$decryptedSecret = app_decrypt($connection['client_secret']);
if ($decryptedSecret === null) {
    http_response_code(500);
    exit('This SSO connection is misconfigured (its stored secret could not be decrypted). An admin needs to re-enter the Client Secret on this connection.');
}

$endpoints = sso_provider_endpoints($connection['provider'], $connection['tenant_id']);

$tokenResponse = sso_http_post_form($endpoints['token'], [
    'client_id' => $connection['client_id'],
    'client_secret' => $decryptedSecret,
    'code' => $code,
    'redirect_uri' => sso_redirect_uri(),
    'grant_type' => 'authorization_code',
    'code_verifier' => $verifier,
]);

if (!$tokenResponse || empty($tokenResponse['access_token'])) {
    http_response_code(502);
    exit('Could not complete sign-in with the identity provider. Please try again.');
}

// Ask the provider directly who this access token belongs to, rather than
// parsing/verifying the ID token's JWT signature ourselves.
$userinfo = sso_http_get_bearer($endpoints['userinfo'], $tokenResponse['access_token']);
if (!$userinfo || empty($userinfo['sub']) || empty($userinfo['email'])) {
    http_response_code(502);
    exit('The identity provider did not return the expected account information.');
}

// Google explicitly reports email_verified; Microsoft's endpoint generally
// doesn't include it for verified work/school accounts, so its absence is
// treated as verified rather than rejected.
$emailVerified = $userinfo['email_verified'] ?? true;
if ($emailVerified === false || $emailVerified === 'false') {
    http_response_code(403);
    exit('Your email address is not verified with the identity provider.');
}

$email = strtolower(trim((string)$userinfo['email']));
$subject = (string)$userinfo['sub'];

if (!empty($connection['allowed_domain'])) {
    $atPos = strrpos($email, '@');
    $emailDomain = $atPos !== false ? substr($email, $atPos + 1) : '';
    if (strcasecmp($emailDomain, (string)$connection['allowed_domain']) !== 0) {
        http_response_code(403);
        exit("This sign-in is restricted to @{$connection['allowed_domain']} accounts.");
    }
}

// If this connection defines email-prefix rules, the local-part (before
// the @) must match at least one of them, and the role comes from the
// longest matching prefix. With no rules defined at all, every email
// permitted by the domain check above is allowed, using default_role —
// this is the original, simpler behavior, unchanged when no rules exist.
$prefixRules = json_decode($connection['prefix_rules'] ?? '', true) ?: [];
$localPart = strtolower(explode('@', $email, 2)[0]);
$matchedRole = null;
if (!empty($prefixRules)) {
    $bestMatchLen = -1;
    foreach ($prefixRules as $rule) {
        $prefix = strtolower(trim((string)($rule['prefix'] ?? '')));
        if ($prefix !== '' && strpos($localPart, $prefix) === 0 && strlen($prefix) > $bestMatchLen) {
            $bestMatchLen = strlen($prefix);
            $matchedRole = $rule['role'] ?? null;
        }
    }
    if ($matchedRole === null || !array_key_exists($matchedRole, ROLE_LEVELS)) {
        http_response_code(403);
        exit('Your email address does not match any allowed sign-in rule for this connection.');
    }
}
$effectiveRole = $matchedRole ?? $connection['default_role'];

// Match by the provider's permanent subject ID, not email — an email can
// change; the subject can't.
$stmt = $pdo->prepare('SELECT id, username, role FROM admin_users WHERE sso_connection_id = :cid AND sso_subject = :sub');
$stmt->execute([':cid' => $connectionId, ':sub' => $subject]);
$user = $stmt->fetch();

if (!$user) {
    // First sign-in through this connection for this identity — provision
    // a new account automatically with the role determined above.
    $localPartForUsername = explode('@', $email, 2)[0];
    $baseUsername = preg_replace('/[^a-zA-Z0-9._-]/', '', $localPartForUsername) ?: 'user';
    $username = $baseUsername;
    $suffix = 2;
    while (true) {
        $check = $pdo->prepare('SELECT COUNT(*) FROM admin_users WHERE username = :u');
        $check->execute([':u' => $username]);
        if ((int)$check->fetchColumn() === 0) {
            break;
        }
        $username = $baseUsername . $suffix;
        $suffix++;
    }

    $pdo->prepare(
        'INSERT INTO admin_users (username, email, role, sso_connection_id, sso_subject) VALUES (:u, :e, :r, :cid, :sub)'
    )->execute([
        ':u' => $username,
        ':e' => $email,
        ':r' => $effectiveRole,
        ':cid' => $connectionId,
        ':sub' => $subject,
    ]);
    $userId = (int)$pdo->lastInsertId();
    $role = $effectiveRole;
    log_action($pdo, 'sso_provision_user', "Auto-created account '{$username}' via SSO ({$connection['display_name']}), role '{$role}'.");
} else {
    $userId = (int)$user['id'];
    $username = $user['username'];
    // Re-evaluate the role on every login, so a change to this
    // connection's prefix rules (or default role) takes effect for
    // existing SSO accounts the next time they sign in, not just new ones.
    $role = $effectiveRole;
    if ($role !== $user['role']) {
        $pdo->prepare('UPDATE admin_users SET role = :role WHERE id = :id')->execute([':role' => $role, ':id' => $userId]);
        log_action($pdo, 'update_role', "SSO login updated role for '{$username}' to '{$role}'.");
    }
}

session_regenerate_id(true);
$_SESSION['admin_user_id'] = $userId;
$_SESSION['admin_username'] = $username;
$_SESSION['admin_role'] = $role;
$_SESSION['must_reset_password'] = false;
$_SESSION['idle_timeout_seconds'] = SESSION_IDLE_TIMEOUT_SECONDS;
$_SESSION['last_activity'] = time();

$pdo->prepare('UPDATE admin_users SET last_login = UTC_TIMESTAMP() WHERE id = :id')->execute([':id' => $userId]);
log_action($pdo, 'sso_login', "Logged in via SSO ({$connection['display_name']}).");

header('Location: /');
exit;

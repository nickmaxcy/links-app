<?php
declare(strict_types=1);

// Block this file from being requested directly over HTTP.
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(403);
    exit('Direct access not permitted.');
}

const SESSION_IDLE_TIMEOUT_SECONDS = 1800; // 30 minutes (default; "stay logged in" extends this per-session)
const SESSION_EXTENDED_TIMEOUT_SECONDS = 14400; // 4 hours
const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_LOCKOUT_MINUTES = 15;
const ROLE_LEVELS = ['viewer' => 1, 'editor' => 2, 'admin' => 3];

if (session_status() === PHP_SESSION_NONE) {
    // Now that the app is only reachable over HTTPS (plain HTTP redirects
    // at the proxy), lock the session cookie to HTTPS-only, JS-inaccessible,
    // and same-site — standard hardening once TLS is the sole entry point.
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Enforce an idle timeout: if a logged-in user's session has had no
// activity for their timeout window, log them out automatically. The
// window is per-session (set at login) so the "stay logged in 12 hours"
// checkbox can extend it without changing the default for everyone else.
if (!empty($_SESSION['admin_user_id'])) {
    $timeout = $_SESSION['idle_timeout_seconds'] ?? SESSION_IDLE_TIMEOUT_SECONDS;
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
        $_SESSION = [];
        session_destroy();
    } else {
        $_SESSION['last_activity'] = time();
    }
}

function is_logged_in(): bool
{
    return !empty($_SESSION['admin_user_id']);
}

function current_role(): ?string
{
    return $_SESSION['admin_role'] ?? null;
}

// Shorthand used in a few places that specifically mean "the admin role",
// not just "any logged-in user" — e.g. gating account-management UI.
function is_admin(): bool
{
    return current_role() === 'admin';
}

function current_admin_username(): ?string
{
    return $_SESSION['admin_username'] ?? null;
}

function current_admin_id(): ?int
{
    return isset($_SESSION['admin_user_id']) ? (int)$_SESSION['admin_user_id'] : null;
}

// Redirects to the login page if not authenticated at all, preserving the
// page being requested so login lands back on it. Every page in the app
// calls this (including the public lookup page) — nothing is reachable
// without an account.
function require_login(): void
{
    if (!is_logged_in()) {
        $return = $_SERVER['REQUEST_URI'] ?? '/';
        // Only allow local, same-site redirect targets.
        if (strpos($return, '/') !== 0 || strpos($return, '//') === 0) {
            $return = '/';
        }
        header('Location: /login.php?return=' . urlencode($return));
        exit;
    }

    // A user flagged for a forced password reset can reach nothing else
    // until they've set a new one.
    $currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if (!empty($_SESSION['must_reset_password']) && $currentScript !== 'change-password.php' && $currentScript !== 'logout.php') {
        header('Location: /change-password.php');
        exit;
    }
}

// Requires the current user to be logged in AND hold at least $minRole
// (viewer < editor < admin). A logged-in user without enough permission
// sees a plain "Access Denied" page rather than being bounced back to
// login, since logging in again wouldn't change anything.
function require_role(string $minRole): void
{
    require_login();
    $role = current_role();
    $have = ROLE_LEVELS[$role] ?? 0;
    $need = ROLE_LEVELS[$minRole] ?? 999;
    if ($have < $need) {
        http_response_code(403);
        echo '<!doctype html><html><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Access Denied</title><link rel="stylesheet" href="/assets/style.css"></head><body>'
            . '<div class="container"><h2>Access Denied</h2>'
            . '<p>Your account does not have permission to view this page.</p>'
            . '<p><a href="/">&larr; Back to Lookup</a></p></div></body></html>';
        exit;
    }
}

// Centralized nav bar, shown/hidden per link based on the current user's
// role, so no page has to hand-maintain its own copy of this logic.
// --- CSRF protection -------------------------------------------------

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

// Call at the top of every POST handler. Stops execution with a 403 if the
// token is missing or doesn't match the one issued for this session.
function require_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        die('Your session token is invalid or expired. Please go back, reload the page, and try again.');
    }
}

// --- Login throttling (tracked per source IP, not per session, so it
// can't be bypassed simply by not sending cookies) --------------------

// Returns ['locked' => bool, 'locked_until' => ?string]. Also clears an
// expired lock as a side effect, so attempts can resume once it passes.
// Returns ['locked' => bool, 'minutes_left' => int]. All time comparisons
// happen inside MySQL using UTC_TIMESTAMP() (the server's absolute UTC
// clock) rather than the session-dependent NOW(), and rather than parsing
// a MySQL datetime string with PHP's strtotime(). Mixing either of those
// risks a false "already expired" result if two connections end up with
// different session timezones. Also clears an expired lock as a side effect.
function get_login_lock_status(PDO $pdo, string $ip): array
{
    $stmt = $pdo->prepare(
        'SELECT
            (locked_until IS NOT NULL AND locked_until > UTC_TIMESTAMP()) AS is_locked,
            GREATEST(TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), locked_until), 1) AS minutes_left,
            locked_until
         FROM login_attempts WHERE ip = :ip'
    );
    $stmt->execute([':ip' => $ip]);
    $row = $stmt->fetch();

    if ($row && (int)$row['is_locked'] === 1) {
        return ['locked' => true, 'minutes_left' => (int)$row['minutes_left']];
    }

    if ($row && $row['locked_until'] !== null) {
        $pdo->prepare('UPDATE login_attempts SET attempts = 0, locked_until = NULL WHERE ip = :ip')
            ->execute([':ip' => $ip]);
    }

    return ['locked' => false, 'minutes_left' => 0];
}

function record_failed_login(PDO $pdo, string $ip): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO login_attempts (ip, attempts, last_attempt) VALUES (:ip, 1, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_attempt = UTC_TIMESTAMP()'
    );
    $stmt->execute([':ip' => $ip]);

    $check = $pdo->prepare('SELECT attempts FROM login_attempts WHERE ip = :ip');
    $check->execute([':ip' => $ip]);
    $attempts = (int)$check->fetchColumn();

    if ($attempts >= LOGIN_MAX_ATTEMPTS) {
        $pdo->prepare(
            'UPDATE login_attempts SET locked_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ' . LOGIN_LOCKOUT_MINUTES . ' MINUTE) WHERE ip = :ip'
        )->execute([':ip' => $ip]);
    }
}

function clear_login_attempts(PDO $pdo, string $ip): void
{
    $pdo->prepare('DELETE FROM login_attempts WHERE ip = :ip')->execute([':ip' => $ip]);
}

// Generic version of the same lockout pattern, usable against any table
// shaped like (key_column, attempts, last_attempt, locked_until) —
// currently totp_attempts (keyed by user_id) and password_reset_requests
// (keyed by ip).
function get_generic_lock_status(PDO $pdo, string $table, string $keyColumn, $keyValue): array
{
    $stmt = $pdo->prepare(
        "SELECT
            (locked_until IS NOT NULL AND locked_until > UTC_TIMESTAMP()) AS is_locked,
            GREATEST(TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), locked_until), 1) AS minutes_left,
            locked_until
         FROM {$table} WHERE {$keyColumn} = :key"
    );
    $stmt->execute([':key' => $keyValue]);
    $row = $stmt->fetch();

    if ($row && (int)$row['is_locked'] === 1) {
        return ['locked' => true, 'minutes_left' => (int)$row['minutes_left']];
    }
    if ($row && $row['locked_until'] !== null) {
        $pdo->prepare("UPDATE {$table} SET attempts = 0, locked_until = NULL WHERE {$keyColumn} = :key")
            ->execute([':key' => $keyValue]);
    }
    return ['locked' => false, 'minutes_left' => 0];
}

function record_generic_failure(PDO $pdo, string $table, string $keyColumn, $keyValue, int $maxAttempts, int $lockoutMinutes): void
{
    $pdo->prepare(
        "INSERT INTO {$table} ({$keyColumn}, attempts, last_attempt) VALUES (:key, 1, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_attempt = UTC_TIMESTAMP()"
    )->execute([':key' => $keyValue]);

    $check = $pdo->prepare("SELECT attempts FROM {$table} WHERE {$keyColumn} = :key");
    $check->execute([':key' => $keyValue]);
    $attempts = (int)$check->fetchColumn();

    if ($attempts >= $maxAttempts) {
        $pdo->prepare(
            "UPDATE {$table} SET locked_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL {$lockoutMinutes} MINUTE) WHERE {$keyColumn} = :key"
        )->execute([':key' => $keyValue]);
    }
}

function clear_generic_attempts(PDO $pdo, string $table, string $keyColumn, $keyValue): void
{
    $pdo->prepare("DELETE FROM {$table} WHERE {$keyColumn} = :key")->execute([':key' => $keyValue]);
}

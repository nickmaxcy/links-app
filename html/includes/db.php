<?php
declare(strict_types=1);

// Block this file from being requested directly over HTTP; it should only
// ever run as part of another script that includes it.
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(403);
    exit('Direct access not permitted.');
}

// Since db.php is required by literally every entry point, this is a
// pragmatic backstop against ever showing a visitor a raw PHP error with
// real file paths — errors still get logged, just not displayed. The
// more thorough fix is setting these at the php.ini level directly, but
// this ensures it holds even if that's ever missed on a fresh deploy.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

function get_pdo(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dbHost = getenv('DB_HOST') ?: 'db';
        $dbName = getenv('DB_NAME') ?: 'linksdb';
        $dbUser = getenv('DB_USER') ?: 'linksuser';
        $dbPass = getenv('DB_PASSWORD') ?: 'change_this_password';

        try {
            $pdo = new PDO(
                "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
                $dbUser,
                $dbPass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
        } catch (PDOException $e) {
            http_response_code(500);
            die('Database connection failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }
    }
    return $pdo;
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

// Records one row in the audit trail. Username is pulled automatically
// from the current session (set at login) when available; older rows from
// before named accounts existed will show a NULL username.
function log_action(PDO $pdo, string $action, string $details = ''): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $username = $_SESSION['admin_username'] ?? null;
    $stmt = $pdo->prepare(
        'INSERT INTO audit_log (action, details, ip, username) VALUES (:action, :details, :ip, :username)'
    );
    $stmt->execute([':action' => $action, ':details' => $details, ':ip' => $ip, ':username' => $username]);
}

function get_setting(PDO $pdo, string $key, string $default = ''): string
{
    $stmt = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = :key');
    $stmt->execute([':key' => $key]);
    $value = $stmt->fetchColumn();
    return $value !== false ? (string)$value : $default;
}

function set_setting(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO app_settings (setting_key, setting_value) VALUES (:key, :value)
         ON DUPLICATE KEY UPDATE setting_value = :value'
    );
    $stmt->execute([':key' => $key, ':value' => $value]);
}

// Turns a free-typed label like "Room Email" into a safe, storable field
// key like "room_email". Used when an admin defines a new field on a
// platform, so they never have to think about key syntax themselves.
function slugify_key(string $label): string
{
    $slug = strtolower(trim($label));
    $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
    $slug = trim((string)$slug, '_');
    return $slug !== '' ? $slug : 'field';
}

// Returns every platform, each with its field_schema already json_decode'd
// into a plain PHP array (so callers never touch raw JSON strings).
function get_platforms(PDO $pdo): array
{
    $rows = $pdo->query('SELECT id, platform_key, display_name, logo_path, field_schema, sort_order FROM platforms ORDER BY display_name')->fetchAll();
    foreach ($rows as &$row) {
        $row['field_schema'] = json_decode($row['field_schema'], true) ?: [];
    }
    unset($row);
    return $rows;
}

function get_platform(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT id, platform_key, display_name, logo_path, field_schema, sort_order FROM platforms WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $row['field_schema'] = json_decode($row['field_schema'], true) ?: [];
    return $row;
}

// Returns null if the user has unrestricted access to all Entities (true
// for admins always, and for any user with zero rows in
// user_entity_access — the backward-compatible default), or an array of
// the specific Entity names they're limited to.
function get_allowed_entity_names(PDO $pdo, int $userId, string $role): ?array
{
    if ($role === 'admin') {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT e.name FROM user_entity_access uea JOIN entities e ON e.id = uea.entity_id WHERE uea.user_id = :uid'
    );
    $stmt->execute([':uid' => $userId]);
    $names = $stmt->fetchAll(PDO::FETCH_COLUMN);
    return empty($names) ? null : $names;
}

// Natural, case-insensitive sort — so "ESU 2" sorts before "ESU 10", unlike
// a plain alphabetical sort which compares character-by-character and would
// put "ESU 10" first (since '1' < '2' at that position).
function natsort_rows_by(array $rows, string $key): array
{
    usort($rows, fn($a, $b) => strnatcasecmp((string)($a[$key] ?? ''), (string)($b[$key] ?? '')));
    return $rows;
}

const FIELD_TYPES = ['text' => 'Text', 'url' => 'URL / Link', 'yesno' => 'Yes / No', 'ip' => 'IP Address', 'number' => 'Number'];

// Accepts either the internal type key (e.g. "ip") or its human-readable
// label as shown in the Platforms UI (e.g. "IP Address"), case-insensitive
// either way — matters for CSV imports, where someone will naturally type
// what they see in the dropdown rather than the internal key it maps to.
// Falls back to "text" only if truly unrecognized.
function normalize_field_type(string $raw): string
{
    $raw = trim($raw);
    $key = strtolower($raw);
    if (array_key_exists($key, FIELD_TYPES)) {
        return $key;
    }
    foreach (FIELD_TYPES as $k => $label) {
        if (strcasecmp($label, $raw) === 0) {
            return $k;
        }
    }
    return 'text';
}

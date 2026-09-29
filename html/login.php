<?php
declare(strict_types=1);

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/theme.php';
require __DIR__ . '/includes/sso.php';

$pdo = get_pdo();
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

// First-run bootstrap: if no admin accounts exist yet, create exactly one
// from the INITIAL_ADMIN_USERNAME / INITIAL_ADMIN_PASSWORD env vars. This
// only ever fires once — after the first account exists, this block never
// runs again regardless of what .env still contains.
$userCount = (int)$pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
if ($userCount === 0) {
    $initUsername = getenv('INITIAL_ADMIN_USERNAME') ?: 'admin';
    $initPassword = getenv('INITIAL_ADMIN_PASSWORD') ?: '';
    if ($initPassword !== '') {
        $stmt = $pdo->prepare(
            'INSERT INTO admin_users (username, password_hash, role) VALUES (:username, :hash, :role)'
        );
        $stmt->execute([
            ':username' => $initUsername,
            ':hash' => password_hash($initPassword, PASSWORD_DEFAULT),
            ':role' => 'admin',
        ]);
        log_action($pdo, 'bootstrap_admin', "Created initial admin account '{$initUsername}'.");
    }
}

$error = null;
$return = $_GET['return'] ?? $_POST['return'] ?? '/';
if (strpos($return, '/') !== 0 || strpos($return, '//') === 0) {
    $return = '/';
}

if (is_logged_in()) {
    header('Location: ' . $return);
    exit;
}

$lock = get_login_lock_status($pdo, $ip);
$ssoConnections = get_sso_connections($pdo, true);

if ($lock['locked']) {
    $unit = $lock['minutes_left'] === 1 ? 'minute' : 'minutes';
    $error = "Too many failed login attempts. Try again in {$lock['minutes_left']} {$unit}.";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    $stmt = $pdo->prepare('SELECT id, username, password_hash, role, must_reset_password, totp_enabled FROM admin_users WHERE username = :username');
    $stmt->execute([':username' => $username]);
    $user = $stmt->fetch();

    if ($user && $password !== '' && $user['password_hash'] !== null && password_verify($password, $user['password_hash'])) {
        clear_login_attempts($pdo, $ip);

        if (!empty($user['totp_enabled'])) {
            // Password checks out, but a second factor is required before a
            // full session is established.
            session_regenerate_id(true);
            $_SESSION['pending_2fa_user_id'] = (int)$user['id'];
            $_SESSION['pending_2fa_return'] = $return;
            $_SESSION['pending_2fa_remember'] = !empty($_POST['remember_12h']);
            header('Location: /verify-2fa.php');
            exit;
        }

        session_regenerate_id(true);
        $_SESSION['admin_user_id'] = (int)$user['id'];
        $_SESSION['admin_username'] = $user['username'];
        $_SESSION['admin_role'] = $user['role'];
        $_SESSION['must_reset_password'] = (bool)$user['must_reset_password'];
        $_SESSION['idle_timeout_seconds'] = !empty($_POST['remember_12h'])
            ? SESSION_EXTENDED_TIMEOUT_SECONDS
            : SESSION_IDLE_TIMEOUT_SECONDS;
        $_SESSION['last_activity'] = time();

        $pdo->prepare('UPDATE admin_users SET last_login = UTC_TIMESTAMP() WHERE id = :id')
            ->execute([':id' => $user['id']]);

        log_action($pdo, 'login', "Logged in.");
        header('Location: ' . ($user['must_reset_password'] ? '/change-password.php' : $return));
        exit;
    } else {
        record_failed_login($pdo, $ip);
        $error = 'Incorrect username or password.';
    }
}
?>
<!doctype html>
<html<?= render_html_data_theme_attr($pdo) ?>>
<head>
<meta charset="UTF-8" name="viewport" content="width=device-width, initial-scale=1">
<title>Admin Login - Links Directory</title>
<link rel="icon" type="image/x-icon" href="/<?= h(get_site_favicon_path($pdo)) ?>">
<link rel="stylesheet" href="<?= asset_version_url('assets/style.css') ?>">
<?= render_theme_style_block($pdo) ?>
</head>
<body>
<?= render_theme_toggle($pdo) ?>

  <?php if ($error): ?>
    <div class="notice error"><?= h($error) ?></div>
  <?php endif; ?>

  <div class="container auth-card">
    <div class="header">
      <img src="/<?= h(get_site_logo_path($pdo)) ?>" alt="Site Logo"/>
      <h1>Admin Login</h1>
    </div>

    <?php if (!$lock['locked']): ?>
      <?php if (!empty($ssoConnections)): ?>
        <?php foreach ($ssoConnections as $conn): ?>
          <a href="/auth/sso/start.php?connection=<?= (int)$conn['id'] ?>" class="btn" style="display:flex; align-items:center; justify-content:center; gap:8px; margin-bottom:8px;">
            <?php if (!empty($conn['icon_path'])): ?>
              <img src="/assets/<?= h($conn['icon_path']) ?>" style="height:20px; width:20px; object-fit:contain;">
            <?php endif; ?>
            Sign in with <?= h($conn['display_name']) ?>
          </a>
        <?php endforeach; ?>
        <p style="text-align:center; color:var(--text-muted); font-size:0.85rem; margin:16px 0;">or sign in with a local account</p>
      <?php endif; ?>
      <form method="post" action="/login.php">
        <?= csrf_field() ?>
        <input type="hidden" name="return" value="<?= h($return) ?>">
        <div class="form-group">
          <label for="username">Username</label>
          <input type="text" id="username" name="username" autofocus required>
        </div>
        <div class="form-group">
          <label for="password">Password</label>
          <input type="password" id="password" name="password" required>
        </div>
        <div class="form-group" style="display:flex; align-items:center; gap:8px;">
          <input type="checkbox" id="remember_12h" name="remember_12h" value="1" style="width:auto;">
          <label for="remember_12h" style="margin:0; font-weight:normal;">Stay logged in for 4 hours</label>
        </div>
        <button type="submit">Log In</button>
      </form>
      <p class="footer-link" style="color: var(--text-muted); margin-top: 12px;">
        <a href="/forgot-password.php">Forgot password?</a>
      </p>
    <?php endif; ?>
  </div>

</body>
</html>

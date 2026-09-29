<?php
declare(strict_types=1);

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/theme.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/totp.php';

$pdo = get_pdo();
$error = null;

if (is_logged_in()) {
    header('Location: /');
    exit;
}

$pendingUserId = $_SESSION['pending_2fa_user_id'] ?? null;
if (!$pendingUserId) {
    header('Location: /login.php');
    exit;
}

$stmt = $pdo->prepare('SELECT id, username, role, must_reset_password, totp_secret FROM admin_users WHERE id = :id AND totp_enabled = 1');
$stmt->execute([':id' => $pendingUserId]);
$user = $stmt->fetch();

if (!$user) {
    unset($_SESSION['pending_2fa_user_id']);
    header('Location: /login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $code = trim($_POST['code'] ?? '');
    $lock = get_generic_lock_status($pdo, 'totp_attempts', 'user_id', $pendingUserId);

    if ($lock['locked']) {
        $error = "Too many incorrect codes. Try again in about {$lock['minutes_left']} minute(s).";
    } elseif (totp_verify($user['totp_secret'], $code)) {
        clear_generic_attempts($pdo, 'totp_attempts', 'user_id', $pendingUserId);
        $return = $_SESSION['pending_2fa_return'] ?? '/';
        $rememberExtended = !empty($_SESSION['pending_2fa_remember']);
        unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_return'], $_SESSION['pending_2fa_remember']);

        session_regenerate_id(true);
        $_SESSION['admin_user_id'] = (int)$user['id'];
        $_SESSION['admin_username'] = $user['username'];
        $_SESSION['admin_role'] = $user['role'];
        $_SESSION['must_reset_password'] = (bool)$user['must_reset_password'];
        $_SESSION['idle_timeout_seconds'] = $rememberExtended ? SESSION_EXTENDED_TIMEOUT_SECONDS : SESSION_IDLE_TIMEOUT_SECONDS;
        $_SESSION['last_activity'] = time();

        $pdo->prepare('UPDATE admin_users SET last_login = UTC_TIMESTAMP() WHERE id = :id')->execute([':id' => $user['id']]);
        log_action($pdo, 'login', 'Logged in (with 2FA).');

        header('Location: ' . ($user['must_reset_password'] ? '/change-password.php' : $return));
        exit;
    } else {
        record_generic_failure($pdo, 'totp_attempts', 'user_id', $pendingUserId, 5, 15);
        $error = 'That code did not match. Please try again.';
    }
}

render_layout_head($pdo, 'Verify Code - Links Directory');
$lockNow = get_generic_lock_status($pdo, 'totp_attempts', 'user_id', $pendingUserId);
?>
<body>
<?= render_theme_toggle($pdo) ?>

  <?php if ($error): ?>
    <div class="notice error"><?= h($error) ?></div>
  <?php endif; ?>

  <div class="container auth-card">
    <div class="header">
      <img src="/<?= h(get_site_logo_path($pdo)) ?>" alt="Site Logo"/>
      <h1>Verify Your Identity</h1>
    </div>
    <?php if (!$lockNow['locked']): ?>
      <p style="color: var(--text-muted); font-size: 0.9rem;">Enter the 6-digit code from your authenticator app.</p>
      <form method="post" action="/verify-2fa.php">
        <?= csrf_field() ?>
        <div class="form-group">
          <label for="code">Code</label>
          <input type="text" id="code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autofocus required>
        </div>
        <button type="submit">Verify</button>
      </form>
    <?php endif; ?>
  </div>

</body>
</html>

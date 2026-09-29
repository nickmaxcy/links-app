<?php
declare(strict_types=1);

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/theme.php';
require __DIR__ . '/includes/layout.php';

$pdo = get_pdo();
$error = null;
$success = null;

if (is_logged_in()) {
    header('Location: /');
    exit;
}

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$tokenHash = $token !== '' ? hash('sha256', $token) : '';

$resetRow = null;
if ($tokenHash !== '') {
    $stmt = $pdo->prepare(
        'SELECT pr.id, pr.user_id, pr.expires_at, pr.used, u.username
         FROM password_resets pr JOIN admin_users u ON u.id = pr.user_id
         WHERE pr.token_hash = :hash'
    );
    $stmt->execute([':hash' => $tokenHash]);
    $resetRow = $stmt->fetch();
}

$validToken = $resetRow
    && (int)$resetRow['used'] === 0
    && strtotime($resetRow['expires_at']) > time();

if ($token === '') {
    $error = 'Missing reset token.';
} elseif (!$resetRow) {
    $error = 'This reset link is invalid.';
} elseif ((int)$resetRow['used'] === 1) {
    $error = 'This reset link has already been used. Request a new one if you still need to reset your password.';
} elseif (strtotime($resetRow['expires_at']) <= time()) {
    $error = 'This reset link has expired. Request a new one.';
}

if ($validToken && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    if (strlen($newPassword) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($newPassword !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $pdo->prepare('UPDATE admin_users SET password_hash = :hash, must_reset_password = 0 WHERE id = :id')
            ->execute([':hash' => password_hash($newPassword, PASSWORD_DEFAULT), ':id' => $resetRow['user_id']]);
        $pdo->prepare('UPDATE password_resets SET used = 1 WHERE id = :id')
            ->execute([':id' => $resetRow['id']]);
        log_action($pdo, 'complete_password_reset', "Password reset completed for '{$resetRow['username']}'.");
        $success = 'Your password has been reset. You can now log in with your new password.';
        $validToken = false; // prevent re-showing the form after success
    }
}

render_layout_head($pdo, 'Reset Password - Links Directory');
?>
<body>
<?= render_theme_toggle($pdo) ?>

  <?php if ($error): ?>
    <div class="notice error"><?= h($error) ?></div>
  <?php endif; ?>
  <?php if ($success): ?>
    <div class="notice success"><?= h($success) ?></div>
  <?php endif; ?>

  <div class="container auth-card">
    <div class="header">
      <img src="/<?= h(get_site_logo_path($pdo)) ?>" alt="Site Logo"/>
      <h1>Reset Password</h1>
    </div>

    <?php if ($validToken && !$success): ?>
      <form method="post" action="/reset-password.php">
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= h($token) ?>">
        <div class="form-group">
          <label for="new_password">New Password</label>
          <input type="password" id="new_password" name="new_password" minlength="8" required autofocus>
        </div>
        <div class="form-group">
          <label for="confirm_password">Confirm New Password</label>
          <input type="password" id="confirm_password" name="confirm_password" minlength="8" required>
        </div>
        <button type="submit">Save New Password</button>
      </form>
    <?php endif; ?>
    <p class="footer-link" style="color: var(--text-muted); margin-top: 16px;">
      <a href="/login.php">&larr; Back to login</a>
    </p>
  </div>

</body>
</html>

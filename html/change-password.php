<?php
declare(strict_types=1);

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/theme.php';
require __DIR__ . '/includes/layout.php';
require_login();

$pdo = get_pdo();
$error = null;
$forced = !empty($_SESSION['must_reset_password']);

$ssoCheckStmt = $pdo->prepare('SELECT sso_connection_id FROM admin_users WHERE id = :id');
$ssoCheckStmt->execute([':id' => current_admin_id()]);
if (!empty($ssoCheckStmt->fetchColumn())) {
    header('Location: /security.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    if (strlen($newPassword) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($newPassword !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $stmt = $pdo->prepare('UPDATE admin_users SET password_hash = :hash, must_reset_password = 0 WHERE id = :id');
        $stmt->execute([':hash' => password_hash($newPassword, PASSWORD_DEFAULT), ':id' => current_admin_id()]);
        $_SESSION['must_reset_password'] = false;
        log_action($pdo, 'change_own_password', 'Changed own password.');
        header('Location: /');
        exit;
    }
}

render_layout_head($pdo, 'Change Password - Links Directory');
?>
<?php render_layout_body_open($pdo, 'Change Password', 'change-password'); ?>

  <?php if ($forced): ?>
    <div class="notice error">You must set a new password before continuing.</div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="notice error"><?= h($error) ?></div>
  <?php endif; ?>

  <div class="container auth-card">
    <h2>Change Password</h2>
    <form method="post" action="/change-password.php">
      <?= csrf_field() ?>
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
  </div>

<?php render_layout_close(); ?>

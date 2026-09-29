<?php
declare(strict_types=1);

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/theme.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/mailer.php';

$pdo = get_pdo();
$error = null;
$success = null;
$lock = ['locked' => false];

if (is_logged_in()) {
    header('Location: /');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $username = trim($_POST['username'] ?? '');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $lock = get_generic_lock_status($pdo, 'password_reset_requests', 'ip', $ip);

    if ($lock['locked']) {
        $error = "Too many reset requests from this location. Try again in about {$lock['minutes_left']} minute(s).";
    } elseif ($username === '') {
        $error = 'Enter your username.';
    } else {
        record_generic_failure($pdo, 'password_reset_requests', 'ip', $ip, 3, 15);

        $stmt = $pdo->prepare('SELECT id, username, email FROM admin_users WHERE username = :u');
        $stmt->execute([':u' => $username]);
        $user = $stmt->fetch();

        $success = 'If that account exists and has an email address on file, a password reset link has been sent to it.';

        if ($user && !empty($user['email'])) {
            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            $expiresAt = date('Y-m-d H:i:s', time() + 1800);

            $pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (:uid, :hash, :exp)')
                ->execute([':uid' => $user['id'], ':hash' => $tokenHash, ':exp' => $expiresAt]);

            $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $resetUrl = "https://{$domain}/reset-password.php?token={$token}";

            $body = "Hello {$user['username']},\n\n"
                . "A password reset was requested for your Links Directory account. If this was you, click the link below to set a new password. This link expires in 30 minutes and can only be used once.\n\n"
                . "{$resetUrl}\n\n"
                . "If you didn't request this, you can safely ignore this email - your password will not be changed.\n";

            send_email($user['email'], 'Links Directory password reset', $body);
            log_action($pdo, 'request_password_reset', "Password reset requested for '{$user['username']}'.");
        }
    }
}

render_layout_head($pdo, 'Forgot Password - Links Directory');
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
      <h1>Forgot Password</h1>
    </div>

    <?php if (!$success && !($lock['locked'] ?? false)): ?>
      <form method="post" action="/forgot-password.php">
        <?= csrf_field() ?>
        <div class="form-group">
          <label for="username">Username</label>
          <input type="text" id="username" name="username" autofocus required>
        </div>
        <button type="submit">Send Reset Link</button>
      </form>
    <?php endif; ?>
    <p class="footer-link" style="color: var(--text-muted); margin-top: 16px;">
      <a href="/login.php">&larr; Back to login</a>
    </p>
  </div>

</body>
</html>

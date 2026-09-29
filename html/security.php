<?php
declare(strict_types=1);

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/theme.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/totp.php';
require __DIR__ . '/includes/qrcode-lib.php';
require_login();

$pdo = get_pdo();
$error = null;
$success = null;
$userId = current_admin_id();

$stmt = $pdo->prepare('SELECT username, sso_connection_id, totp_enabled FROM admin_users WHERE id = :id');
$stmt->execute([':id' => $userId]);
$me = $stmt->fetch();
$isSsoAccount = !empty($me['sso_connection_id']);

if (!$isSsoAccount && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'start_2fa') {
        $secret = totp_generate_secret();
        $_SESSION['pending_totp_secret'] = $secret;
    } elseif ($action === 'confirm_2fa') {
        $pendingSecret = $_SESSION['pending_totp_secret'] ?? '';
        $code = trim($_POST['code'] ?? '');
        if ($pendingSecret === '' || !totp_verify($pendingSecret, $code)) {
            $error = 'That code did not match. Scan the code again (or re-check the manual entry key) and try once more.';
        } else {
            $pdo->prepare('UPDATE admin_users SET totp_secret = :s, totp_enabled = 1 WHERE id = :id')
                ->execute([':s' => $pendingSecret, ':id' => $userId]);
            unset($_SESSION['pending_totp_secret']);
            log_action($pdo, 'enable_2fa', 'Enabled two-factor authentication.');
            $success = 'Two-factor authentication is now enabled on your account.';
            $me['totp_enabled'] = 1;
        }
    } elseif ($action === 'disable_2fa') {
        $pdo->prepare('UPDATE admin_users SET totp_secret = NULL, totp_enabled = 0 WHERE id = :id')
            ->execute([':id' => $userId]);
        log_action($pdo, 'disable_2fa', 'Disabled two-factor authentication.');
        $success = 'Two-factor authentication has been disabled.';
        $me['totp_enabled'] = 0;
    }
}

$pendingSecret = $_SESSION['pending_totp_secret'] ?? null;
$qrSvg = null;
if ($pendingSecret) {
    $provisioningUri = totp_provisioning_uri($pendingSecret, $me['username']);
    $qrSvg = qr_encode_svg_lib($provisioningUri);
}

render_layout_head($pdo, 'Security - Links Directory');
?>
<?php render_layout_body_open($pdo, 'Security', 'security'); ?>

  <?php if ($error): ?>
    <div class="notice error"><?= h($error) ?></div>
  <?php endif; ?>
  <?php if ($success): ?>
    <div class="notice success"><?= h($success) ?></div>
  <?php endif; ?>

  <div class="container">
    <h2>Two-Factor Authentication</h2>

    <?php if ($isSsoAccount): ?>
      <p style="color: var(--text-muted);">This account signs in through Single Sign-On, so two-factor authentication is managed by your identity provider, not here.</p>

    <?php elseif ($me['totp_enabled']): ?>
      <p style="color: var(--text-muted);">Two-factor authentication is currently <strong>enabled</strong> on your account. You'll be asked for a 6-digit code from your authenticator app each time you log in with your password.</p>
      <form method="post" action="/security.php" onsubmit="return confirm('Disable two-factor authentication on your account?');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="disable_2fa">
        <button type="submit" class="del-btn" style="padding:10px 18px; font-size:0.95rem;">Disable 2FA</button>
      </form>

    <?php elseif ($pendingSecret): ?>
      <p>Scan this code with your authenticator app (Google Authenticator, Microsoft Authenticator, Authy, etc.):</p>
      <?php if ($qrSvg): ?>
        <div style="background:#ffffff; display:inline-block; padding:16px; border-radius:8px; color-scheme: light only; isolation: isolate;"><?= $qrSvg ?></div>
        <p style="color: var(--text-muted); font-size: 0.78rem; margin-top:8px;">
          If this doesn't scan, check whether your phone's system-wide dark mode or "force dark" browser setting is
          on — some phones auto-invert image colors in that mode, which breaks a QR code's contrast. Try again with
          dark mode off, or use the manual key below either way.
        </p>
      <?php else: ?>
        <div class="notice error">Could not generate a QR code (the data was too long). Use the manual entry key below instead.</div>
      <?php endif; ?>

      <p style="margin-top:16px;">Can't scan it? Enter this key into your authenticator app instead:</p>

      <br><br><br><br>
      <p style="text-align:center; margin:0;"><code id="manualKey" style="font-size:1.3rem; letter-spacing:1px; background:var(--surface-alt); padding:8px 14px; border-radius:6px;"><?= h($pendingSecret) ?></code></p>
      <br><br><br><br>

      <p style="text-align:center;">
        <button type="button" id="copyKeyBtn" class="btn" style="background:#94a3b8; padding:6px 12px; font-size:0.82rem;">Copy</button>
      </p>
      <p style="color: var(--text-muted); font-size: 0.8rem;">
        Copying instead of retyping avoids the most common cause of a rejected key — a single mistyped character in
        a long random string.
      </p>
      <script>
      document.getElementById('copyKeyBtn').addEventListener('click', function() {
        var btn = this;
        navigator.clipboard.writeText(document.getElementById('manualKey').textContent.trim()).then(function() {
          btn.textContent = 'Copied!';
          setTimeout(function() { btn.textContent = 'Copy'; }, 2000);
        });
      });
      </script>

      <form method="post" action="/security.php" style="margin-top:20px;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="confirm_2fa">
        <div class="form-group">
          <label for="code">Enter the 6-digit code from your app to confirm setup</label>
          <input type="text" id="code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autofocus required>
        </div>
        <button type="submit">Confirm and Enable</button>
      </form>

    <?php else: ?>
      <p style="color: var(--text-muted);">Two-factor authentication is currently <strong>disabled</strong>. Enabling it requires a 6-digit code from an authenticator app in addition to your password each time you log in.</p>
      <form method="post" action="/security.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="start_2fa">
        <button type="submit">Enable 2FA</button>
      </form>
    <?php endif; ?>
  </div>

<?php render_layout_close(); ?>

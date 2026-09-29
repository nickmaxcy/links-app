<?php
declare(strict_types=1);

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/theme.php';
require __DIR__ . '/../includes/layout.php';
require_role('admin');

$pdo = get_pdo();

$entries = $pdo->query(
    'SELECT action, username, details, ip, created_at FROM audit_log ORDER BY created_at DESC LIMIT 300'
)->fetchAll();

$actionLabels = [
    'bootstrap_admin' => 'Initial Admin Created',
    'login' => 'Login',
    'logout' => 'Logout',
    'add_device' => 'Add Device',
    'update_device' => 'Update Device',
    'delete_device' => 'Delete Device',
    'add_entity' => 'Add Entity',
    'delete_entity' => 'Delete Entity',
    'add_school' => 'Add School',
    'delete_school' => 'Delete School',
    'add_user' => 'Add User',
    'delete_user' => 'Delete User',
    'update_role' => 'Update Role',
    'reset_password' => 'Reset Password',
    'change_own_password' => 'Changed Own Password',
    'update_setting' => 'Update Setting',
    'add_platform' => 'Add Platform',
    'update_platform' => 'Update Platform',
    'delete_platform' => 'Delete Platform',
    'update_appearance' => 'Update Appearance',
    'import_entities' => 'Import Entities',
    'import_schools' => 'Import Schools',
    'import_platforms' => 'Import Platforms',
    'import_devices' => 'Import Devices',
    'import_users' => 'Import Users',
    'update_email' => 'Update Email',
    'request_password_reset' => 'Requested Password Reset',
    'complete_password_reset' => 'Completed Password Reset',
    'add_sso_connection' => 'Add SSO Connection',
    'update_sso_connection' => 'Update SSO Connection',
    'delete_sso_connection' => 'Delete SSO Connection',
    'sso_login' => 'SSO Login',
    'sso_provision_user' => 'SSO Auto-Created User',
    'enable_2fa' => 'Enabled 2FA',
    'disable_2fa' => 'Disabled 2FA',
    'update_entity_access' => 'Update Entity Access',
];
?>
<?php render_layout_head($pdo, 'Audit Log - Links Directory Admin'); ?>
<?php render_layout_body_open($pdo, 'Audit Log', 'audit'); ?>

  <div class="container">
    <h2>Audit Log</h2>
    <p style="color: var(--text-muted); font-size: 0.85rem; margin-top: -8px;">
      Showing the most recent 300 admin actions.
    </p>

    <?php if (empty($entries)): ?>
      <p style="color: var(--text-muted); font-size: 0.9rem;">No admin activity has been recorded yet.</p>
    <?php else: ?>
      <table class="device-table">
        <thead>
          <tr><th>When</th><th>User</th><th>Action</th><th>Details</th><th>IP</th></tr>
        </thead>
        <tbody>
          <?php foreach ($entries as $entry): ?>
            <tr>
              <td data-label="When"><?= h($entry['created_at']) ?></td>
              <td data-label="User"><?= h($entry['username'] ?? '—') ?></td>
              <td data-label="Action"><?= h($actionLabels[$entry['action']] ?? $entry['action']) ?></td>
              <td data-label="Details"><?= h((string)($entry['details'] ?? '')) ?></td>
              <td data-label="IP"><?= h((string)($entry['ip'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

<?php render_layout_close(); ?>

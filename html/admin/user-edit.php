<?php
declare(strict_types=1);

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/theme.php';
require __DIR__ . '/../includes/layout.php';
require_role('admin');

$pdo = get_pdo();
$error = null;
$success = null;
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_entity_access') {
        $entityIds = array_map('intval', $_POST['entity_ids'] ?? []);
        $lookup = $pdo->prepare('SELECT username FROM admin_users WHERE id = :id');
        $lookup->execute([':id' => $id]);
        $username = $lookup->fetchColumn();

        if ($username === false) {
            $error = 'User not found.';
        } else {
            $pdo->prepare('DELETE FROM user_entity_access WHERE user_id = :uid')->execute([':uid' => $id]);
            foreach ($entityIds as $eid) {
                $pdo->prepare('INSERT IGNORE INTO user_entity_access (user_id, entity_id) VALUES (:uid, :eid)')
                    ->execute([':uid' => $id, ':eid' => $eid]);
            }
            $count = count($entityIds);
            $desc = $count === 0 ? 'no restriction (full access)' : "{$count} entity/entities";
            log_action($pdo, 'update_entity_access', "Set entity access for '{$username}' to {$desc}.");
            header("Location: /admin/user-edit.php?id={$id}&access_updated=1");
            exit;
        }
    } elseif ($action === 'update_email') {
        $email = trim($_POST['email'] ?? '');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'That email address doesn\'t look valid.';
        } else {
            $lookup = $pdo->prepare('SELECT username FROM admin_users WHERE id = :id');
            $lookup->execute([':id' => $id]);
            $username = $lookup->fetchColumn();
            if ($username === false) {
                $error = 'User not found.';
            } else {
                $pdo->prepare('UPDATE admin_users SET email = :email WHERE id = :id')
                    ->execute([':email' => $email ?: null, ':id' => $id]);
                log_action($pdo, 'update_email', "Updated email for '{$username}'.");
                header("Location: /admin/user-edit.php?id={$id}&email_updated=1");
                exit;
            }
        }
    } elseif ($action === 'update_role') {
        $role = $_POST['role'] ?? '';
        if (!array_key_exists($role, ROLE_LEVELS)) {
            $error = 'Invalid role.';
        } elseif ($id === current_admin_id() && $role !== 'admin') {
            $error = 'You cannot remove your own admin access while logged in as that account.';
        } else {
            $lookup = $pdo->prepare('SELECT username, role FROM admin_users WHERE id = :id');
            $lookup->execute([':id' => $id]);
            $target = $lookup->fetch();

            if (!$target) {
                $error = 'User not found.';
            } elseif ($target['role'] === 'admin' && $role !== 'admin') {
                $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM admin_users WHERE role = 'admin'")->fetchColumn();
                if ($adminCount <= 1) {
                    $error = 'Cannot change this user\'s role: they are the only remaining admin.';
                }
            }
            if (!$error) {
                $pdo->prepare('UPDATE admin_users SET role = :role WHERE id = :id')->execute([':role' => $role, ':id' => $id]);
                log_action($pdo, 'update_role', "Changed role of '{$target['username']}' to '{$role}'.");
                header("Location: /admin/user-edit.php?id={$id}&role_updated=1");
                exit;
            }
        }
    } elseif ($action === 'admin_disable_2fa') {
        $lookup = $pdo->prepare('SELECT username FROM admin_users WHERE id = :id');
        $lookup->execute([':id' => $id]);
        $username = $lookup->fetchColumn();
        if ($username === false) {
            $error = 'User not found.';
        } else {
            $pdo->prepare('UPDATE admin_users SET totp_secret = NULL, totp_enabled = 0 WHERE id = :id')->execute([':id' => $id]);
            log_action($pdo, 'disable_2fa', "Admin disabled two-factor authentication for '{$username}'.");
            header("Location: /admin/user-edit.php?id={$id}&twofa_disabled=1");
            exit;
        }
    } elseif ($action === 'reset_password') {
        $password = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['password_confirm'] ?? '');
        if (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            $lookup = $pdo->prepare('SELECT username FROM admin_users WHERE id = :id');
            $lookup->execute([':id' => $id]);
            $username = $lookup->fetchColumn();
            if ($username === false) {
                $error = 'User not found.';
            } else {
                $pdo->prepare('UPDATE admin_users SET password_hash = :hash WHERE id = :id')
                    ->execute([':hash' => password_hash($password, PASSWORD_DEFAULT), ':id' => $id]);
                log_action($pdo, 'reset_password', "Reset password for user '{$username}'.");
                header("Location: /admin/user-edit.php?id={$id}&reset=1");
                exit;
            }
        }
    } elseif ($action === 'delete_user') {
        if ($id === current_admin_id()) {
            $error = 'You cannot delete your own account while logged in as that account.';
        } else {
            $lookup = $pdo->prepare('SELECT username, role FROM admin_users WHERE id = :id');
            $lookup->execute([':id' => $id]);
            $target = $lookup->fetch();
            if (!$target) {
                $error = 'User not found.';
            } elseif ($target['role'] === 'admin') {
                $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM admin_users WHERE role = 'admin'")->fetchColumn();
                if ($adminCount <= 1) {
                    $error = 'Cannot delete the only remaining admin.';
                }
            }
            if (!$error) {
                $pdo->prepare('DELETE FROM admin_users WHERE id = :id')->execute([':id' => $id]);
                log_action($pdo, 'delete_user', "Deleted user account '{$target['username']}'.");
                header('Location: /admin/users.php?deleted=1');
                exit;
            }
        }
    }
}

$stmt = $pdo->prepare('SELECT * FROM admin_users WHERE id = :id');
$stmt->execute([':id' => $id]);
$user = $stmt->fetch();

if (!$user) {
    http_response_code(404);
    require __DIR__ . '/../includes/theme.php';
    render_layout_head($pdo, 'User Not Found - Links Directory Admin');
    render_layout_body_open($pdo, 'Users', 'users');
    echo '<div class="notice error">That user could not be found.</div><a href="/admin/users.php" class="edit-link">Back to Users</a>';
    render_layout_close();
    exit;
}

if (isset($_GET['reset'])) { $success = 'Password reset.'; }
if (isset($_GET['role_updated'])) { $success = 'Role updated.'; }
if (isset($_GET['email_updated'])) { $success = 'Email updated.'; }
if (isset($_GET['access_updated'])) { $success = 'Entity access updated.'; }
if (isset($_GET['twofa_disabled'])) { $success = 'Two-factor authentication disabled.'; }

$isSsoUser = !empty($user['sso_connection_id']);
$allEntities = natsort_rows_by($pdo->query('SELECT id, name FROM entities')->fetchAll(), 'name');
$accessStmt = $pdo->prepare('SELECT entity_id FROM user_entity_access WHERE user_id = :id');
$accessStmt->execute([':id' => $id]);
$currentAccessIds = array_map('intval', $accessStmt->fetchAll(PDO::FETCH_COLUMN));

render_layout_head($pdo, 'Edit User - Links Directory Admin');
?>
<?php render_layout_body_open($pdo, 'Users', 'users'); ?>

  <p><a href="/admin/users.php" class="edit-link">&larr; Back to Users</a></p>

  <?php if ($error): ?>
    <div class="notice error"><?= h($error) ?></div>
  <?php endif; ?>
  <?php if ($success): ?>
    <div class="notice success"><?= h($success) ?></div>
  <?php endif; ?>

  <div class="container">
    <h2>
      <?= h($user['username']) ?>
      <?php if ((int)$user['id'] === current_admin_id()): ?><span class="count-badge">(you)</span><?php endif; ?>
      <?php if ($user['must_reset_password']): ?><span class="count-badge">(reset pending)</span><?php endif; ?>
      <span class="count-badge">(<?= $isSsoUser ? 'SSO' : 'Local' ?>)</span>
      <?php if (!empty($user['totp_enabled'])): ?><span class="count-badge">(2FA on)</span><?php endif; ?>
    </h2>
    <p style="color: var(--text-muted); font-size: 0.85rem; margin-top: -8px;">
      Created <?= h($user['created_at']) ?> &middot; Last login: <?= h($user['last_login'] ?? 'never') ?>
    </p>
  </div>

  <div class="container">
    <h3>Email</h3>
    <form method="post" action="/admin/user-edit.php?id=<?= (int)$id ?>" style="display:flex; align-items:center; gap:8px;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update_email">
      <input type="hidden" name="id" value="<?= (int)$id ?>">
      <input type="email" name="email" value="<?= h($user['email'] ?? '') ?>" placeholder="none set" style="max-width:320px;">
      <button type="submit">Save</button>
    </form>
  </div>

  <div class="container">
    <h3>Role</h3>
    <form method="post" action="/admin/user-edit.php?id=<?= (int)$id ?>" style="display:flex; align-items:center; gap:8px;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update_role">
      <input type="hidden" name="id" value="<?= (int)$id ?>">
      <select name="role" style="max-width:220px;">
        <option value="viewer" <?= $user['role'] === 'viewer' ? 'selected' : '' ?>>Viewer</option>
        <option value="editor" <?= $user['role'] === 'editor' ? 'selected' : '' ?>>Editor</option>
        <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
      </select>
      <button type="submit">Save</button>
    </form>
  </div>

  <?php if ($user['role'] !== 'admin'): ?>
  <div class="container">
    <h3>Entity Access</h3>
    <form method="post" action="/admin/user-edit.php?id=<?= (int)$id ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update_entity_access">
      <input type="hidden" name="id" value="<?= (int)$id ?>">
      <p style="font-size:0.85rem; color:var(--text-muted); margin:0 0 10px;">
        Leave everything unchecked for full access to all entities. Check specific entities to restrict this user to
        only those.
      </p>
      <div style="display:flex; flex-wrap:wrap; gap:10px; margin-bottom:14px;">
        <?php foreach ($allEntities as $e): ?>
          <label style="font-weight:normal; font-size:0.88rem; display:flex; align-items:center; gap:5px;">
            <input type="checkbox" name="entity_ids[]" value="<?= (int)$e['id'] ?>"
              <?= in_array((int)$e['id'], $currentAccessIds, true) ? 'checked' : '' ?>
              style="width:auto;">
            <?= h($e['name']) ?>
          </label>
        <?php endforeach; ?>
      </div>
      <button type="submit">Save Entity Access</button>
    </form>
  </div>
  <?php endif; ?>

  <?php if (!$isSsoUser): ?>
  <div class="container">
    <h3>Reset Password</h3>
    <form method="post" action="/admin/user-edit.php?id=<?= (int)$id ?>" style="display:flex; align-items:flex-end; gap:8px; flex-wrap:wrap;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="reset_password">
      <input type="hidden" name="id" value="<?= (int)$id ?>">
      <div class="form-group" style="margin-bottom:0;">
        <label>New password</label>
        <input type="password" name="password" minlength="8" required>
      </div>
      <div class="form-group" style="margin-bottom:0;">
        <label>Confirm</label>
        <input type="password" name="password_confirm" minlength="8" required>
      </div>
      <button type="submit">Save</button>
    </form>
  </div>
  <?php endif; ?>

  <?php if (!empty($user['totp_enabled'])): ?>
  <div class="container">
    <h3>Two-Factor Authentication</h3>
    <p style="color: var(--text-muted); font-size: 0.85rem;">This account currently has 2FA enabled.</p>
    <form method="post" action="/admin/user-edit.php?id=<?= (int)$id ?>" onsubmit="return confirm('Disable two-factor authentication for &quot;<?= h(addslashes($user['username'])) ?>&quot;?');">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="admin_disable_2fa">
      <input type="hidden" name="id" value="<?= (int)$id ?>">
      <button type="submit" class="del-btn" style="padding:10px 18px; font-size:0.95rem;">Disable 2FA</button>
    </form>
  </div>
  <?php endif; ?>

  <?php if ((int)$id !== current_admin_id()): ?>
  <div class="container">
    <h3>Delete Account</h3>
    <p style="color: var(--text-muted); font-size: 0.85rem;">This permanently removes the account. This cannot be undone.</p>
    <form method="post" action="/admin/user-edit.php?id=<?= (int)$id ?>" onsubmit="return confirm('Delete user &quot;<?= h(addslashes($user['username'])) ?>&quot;? This cannot be undone.');">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete_user">
      <input type="hidden" name="id" value="<?= (int)$id ?>">
      <button type="submit" class="del-btn" style="padding:10px 18px; font-size:0.95rem;">Delete User</button>
    </form>
  </div>
  <?php endif; ?>

<?php render_layout_close(); ?>

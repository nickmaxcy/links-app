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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_user') {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['password_confirm'] ?? '');
        $role = $_POST['role'] ?? 'viewer';
        $forceReset = isset($_POST['force_reset']) ? 1 : 0;

        if (!array_key_exists($role, ROLE_LEVELS)) {
            $error = 'Invalid role.';
        } elseif ($username === '' || $password === '') {
            $error = 'Username and password are required.';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'That email address doesn\'t look valid.';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO admin_users (username, email, password_hash, role, must_reset_password) VALUES (:username, :email, :hash, :role, :force_reset)');
                $stmt->execute([
                    ':username' => $username,
                    ':email' => $email ?: null,
                    ':hash' => password_hash($password, PASSWORD_DEFAULT),
                    ':role' => $role,
                    ':force_reset' => $forceReset,
                ]);
                log_action($pdo, 'add_user', "Created user account '{$username}' with role '{$role}'.");
                header('Location: /admin/users.php?added=1');
                exit;
            } catch (PDOException $e) {
                $error = ($e->getCode() === '23000')
                    ? 'That username already exists.'
                    : 'Could not create user: ' . $e->getMessage();
            }
        }
    }
}

if (isset($_GET['added'])) { $success = 'User created.'; }
if (isset($_GET['reset'])) { $success = 'Password reset.'; }
if (isset($_GET['deleted'])) { $success = 'User deleted.'; }
if (isset($_GET['role_updated'])) { $success = 'Role updated.'; }
if (isset($_GET['email_updated'])) { $success = 'Email updated.'; }
if (isset($_GET['access_updated'])) { $success = 'Entity access updated.'; }
if (isset($_GET['twofa_disabled'])) { $success = 'Two-factor authentication disabled for that account.'; }

$users = $pdo->query('SELECT id, username, role, sso_connection_id, must_reset_password, last_login FROM admin_users ORDER BY username')->fetchAll();

render_layout_head($pdo, 'Users - Links Directory Admin');
?>
<script>
window.onload = function() {
  var search = document.getElementById('userSearch');
  var rows = document.querySelectorAll('#usersTable tbody tr');
  search.addEventListener('input', function() {
    var q = search.value.trim().toLowerCase();
    rows.forEach(function(row) {
      row.style.display = (!q || row.dataset.username.indexOf(q) !== -1) ? '' : 'none';
    });
  });
};
</script>
<?php render_layout_body_open($pdo, 'Users', 'users'); ?>

  <?php if ($error): ?>
    <div class="notice error"><?= h($error) ?></div>
  <?php endif; ?>
  <?php if ($success): ?>
    <div class="notice success"><?= h($success) ?></div>
  <?php endif; ?>

  <div class="container">
    <h2>Add User</h2>
    <form method="post" action="/admin/users.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_user">
      <div class="form-group">
        <label for="username">Username</label>
        <input type="text" id="username" name="username" required>
      </div>
      <div class="form-group">
        <label for="email">Email <span style="color: var(--text-muted); font-weight:normal;">(needed for password reset)</span></label>
        <input type="email" id="email" name="email">
      </div>
      <div class="form-group">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" minlength="8" required>
      </div>
      <div class="form-group">
        <label for="password_confirm">Confirm Password</label>
        <input type="password" id="password_confirm" name="password_confirm" minlength="8" required>
      </div>
      <div class="form-group">
        <label for="role">Role</label>
        <select id="role" name="role">
          <option value="viewer">Viewer — lookup page only</option>
          <option value="editor">Editor — lookup + Devices</option>
          <option value="admin">Admin — full access</option>
        </select>
      </div>
      <div class="form-group" style="display:flex; align-items:center; gap:8px;">
        <input type="checkbox" id="force_reset" name="force_reset" value="1" style="width:auto;">
        <label for="force_reset" style="margin:0; font-weight:normal;">Require password reset at first login</label>
      </div>
      <button type="submit">Add User</button>
    </form>
  </div>

  <div class="container">
    <h2>Existing Users</h2>
    <div class="form-group">
      <input type="text" id="userSearch" placeholder="Search users by username...">
    </div>
    <table class="device-table" id="usersTable">
      <thead>
        <tr><th>Username</th><th>Role</th><th>Account Type</th><th>Last Login</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u): ?>
          <tr data-username="<?= h(strtolower($u['username'])) ?>">
            <td data-label="Username">
              <?= h($u['username']) ?>
              <?php if ((int)$u['id'] === current_admin_id()): ?><span class="count-badge">(you)</span><?php endif; ?>
              <?php if ($u['must_reset_password']): ?><span class="count-badge">(reset pending)</span><?php endif; ?>
            </td>
            <td data-label="Role"><?= h(ucfirst($u['role'])) ?></td>
            <td data-label="Account Type"><?= !empty($u['sso_connection_id']) ? 'SSO' : 'Local' ?></td>
            <td data-label="Last Login"><?= h($u['last_login'] ?? 'never') ?></td>
            <td class="actions">
              <a href="/admin/user-edit.php?id=<?= (int)$u['id'] ?>" class="edit-link">Edit</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php render_layout_close(); ?>

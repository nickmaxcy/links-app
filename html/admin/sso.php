<?php
declare(strict_types=1);

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/theme.php';
require __DIR__ . '/../includes/layout.php';
require __DIR__ . '/../includes/sso.php';
require __DIR__ . '/../includes/crypto.php';
require_role('admin');

const SSO_ICON_DIR = __DIR__ . '/../assets/sso-icons';
const SSO_ICON_ALLOWED_EXT = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'];
const SSO_ICON_MAX_BYTES = 1 * 1024 * 1024;

$pdo = get_pdo();
$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_connection') {
        $id = (int)($_POST['id'] ?? 0);
        $provider = $_POST['provider'] ?? '';
        $displayName = trim($_POST['display_name'] ?? '');
        $clientId = trim($_POST['client_id'] ?? '');
        $clientSecret = trim($_POST['client_secret'] ?? '');
        $clientSecretEncrypted = $clientSecret !== '' ? app_encrypt($clientSecret) : '';
        $tenantId = trim($_POST['tenant_id'] ?? '');
        $allowedDomain = trim($_POST['allowed_domain'] ?? '');
        $defaultRole = $_POST['default_role'] ?? 'viewer';
        $enabled = isset($_POST['enabled']) ? 1 : 0;

        if (!array_key_exists($provider, SSO_PROVIDERS)) {
            $error = 'Invalid provider.';
        } elseif ($displayName === '' || $clientId === '') {
            $error = 'Display name and Client ID are required.';
        } elseif (!array_key_exists($defaultRole, ROLE_LEVELS)) {
            $error = 'Invalid default role.';
        } elseif ($id === 0 && $clientSecret === '') {
            $error = 'Client Secret is required for a new connection.';
        } else {
            $prefixRules = [];
            $prefixLabels = $_POST['prefix_label'] ?? [];
            $prefixRoles = $_POST['prefix_role'] ?? [];
            foreach ($prefixLabels as $i => $p) {
                $p = trim((string)$p);
                $r = $prefixRoles[$i] ?? '';
                if ($p !== '' && array_key_exists($r, ROLE_LEVELS)) {
                    $prefixRules[] = ['prefix' => $p, 'role' => $r];
                }
            }
            $prefixRulesJson = json_encode($prefixRules);

            // Icon upload — leaving the field blank on edit keeps the current icon.
            $iconPath = null;
            $iconError = null;
            if (!empty($_FILES['icon']['name']) && $_FILES['icon']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['icon']['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, SSO_ICON_ALLOWED_EXT, true)) {
                    $iconError = 'Icon must be a PNG, JPG, GIF, WEBP, or SVG image.';
                } elseif ($_FILES['icon']['size'] > SSO_ICON_MAX_BYTES) {
                    $iconError = 'Icon must be under 1MB.';
                } else {
                    if (!is_dir(SSO_ICON_DIR)) {
                        mkdir(SSO_ICON_DIR, 0755, true);
                    }
                    $filename = slugify_key($displayName) . '_' . time() . '.' . $ext;
                    if (move_uploaded_file($_FILES['icon']['tmp_name'], SSO_ICON_DIR . '/' . $filename)) {
                        $iconPath = 'sso-icons/' . $filename;
                    } else {
                        $iconError = 'Could not save the uploaded icon.';
                    }
                }
            }

            if ($iconError) {
                $error = $iconError;
            } elseif ($id > 0) {
                if ($clientSecret !== '' && $iconPath !== null) {
                    $pdo->prepare(
                        'UPDATE sso_connections SET provider=:p, display_name=:dn, icon_path=:ic, client_id=:cid, client_secret=:cs, tenant_id=:t, allowed_domain=:ad, default_role=:dr, prefix_rules=:pr, enabled=:en WHERE id=:id'
                    )->execute([':p' => $provider, ':dn' => $displayName, ':ic' => $iconPath, ':cid' => $clientId, ':cs' => $clientSecretEncrypted, ':t' => $tenantId ?: null, ':ad' => $allowedDomain ?: null, ':dr' => $defaultRole, ':pr' => $prefixRulesJson, ':en' => $enabled, ':id' => $id]);
                } elseif ($clientSecret !== '') {
                    $pdo->prepare(
                        'UPDATE sso_connections SET provider=:p, display_name=:dn, client_id=:cid, client_secret=:cs, tenant_id=:t, allowed_domain=:ad, default_role=:dr, prefix_rules=:pr, enabled=:en WHERE id=:id'
                    )->execute([':p' => $provider, ':dn' => $displayName, ':cid' => $clientId, ':cs' => $clientSecretEncrypted, ':t' => $tenantId ?: null, ':ad' => $allowedDomain ?: null, ':dr' => $defaultRole, ':pr' => $prefixRulesJson, ':en' => $enabled, ':id' => $id]);
                } elseif ($iconPath !== null) {
                    $pdo->prepare(
                        'UPDATE sso_connections SET provider=:p, display_name=:dn, icon_path=:ic, client_id=:cid, tenant_id=:t, allowed_domain=:ad, default_role=:dr, prefix_rules=:pr, enabled=:en WHERE id=:id'
                    )->execute([':p' => $provider, ':dn' => $displayName, ':ic' => $iconPath, ':cid' => $clientId, ':t' => $tenantId ?: null, ':ad' => $allowedDomain ?: null, ':dr' => $defaultRole, ':pr' => $prefixRulesJson, ':en' => $enabled, ':id' => $id]);
                } else {
                    $pdo->prepare(
                        'UPDATE sso_connections SET provider=:p, display_name=:dn, client_id=:cid, tenant_id=:t, allowed_domain=:ad, default_role=:dr, prefix_rules=:pr, enabled=:en WHERE id=:id'
                    )->execute([':p' => $provider, ':dn' => $displayName, ':cid' => $clientId, ':t' => $tenantId ?: null, ':ad' => $allowedDomain ?: null, ':dr' => $defaultRole, ':pr' => $prefixRulesJson, ':en' => $enabled, ':id' => $id]);
                }
                log_action($pdo, 'update_sso_connection', "Updated SSO connection '{$displayName}'.");
                header('Location: /admin/sso.php?updated=1');
                exit;
            } else {
                $pdo->prepare(
                    'INSERT INTO sso_connections (provider, display_name, icon_path, client_id, client_secret, tenant_id, allowed_domain, default_role, prefix_rules, enabled) VALUES (:p, :dn, :ic, :cid, :cs, :t, :ad, :dr, :pr, :en)'
                )->execute([':p' => $provider, ':dn' => $displayName, ':ic' => $iconPath, ':cid' => $clientId, ':cs' => $clientSecretEncrypted, ':t' => $tenantId ?: null, ':ad' => $allowedDomain ?: null, ':dr' => $defaultRole, ':pr' => $prefixRulesJson, ':en' => $enabled]);
                log_action($pdo, 'add_sso_connection', "Added SSO connection '{$displayName}' ({$provider}).");
                header('Location: /admin/sso.php?added=1');
                exit;
            }
        }
    } elseif ($action === 'delete_connection') {
        $id = (int)($_POST['id'] ?? 0);
        $lookup = $pdo->prepare('SELECT display_name FROM sso_connections WHERE id = :id');
        $lookup->execute([':id' => $id]);
        $name = $lookup->fetchColumn();

        if ($name === false) {
            $error = 'Connection not found.';
        } else {
            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM admin_users WHERE sso_connection_id = :id');
            $countStmt->execute([':id' => $id]);
            $userCount = (int)$countStmt->fetchColumn();
            if ($userCount > 0) {
                $error = "Cannot delete \"{$name}\": {$userCount} account(s) were created through it. Reassign or delete those accounts first.";
            } else {
                $pdo->prepare('DELETE FROM sso_connections WHERE id = :id')->execute([':id' => $id]);
                log_action($pdo, 'delete_sso_connection', "Deleted SSO connection '{$name}'.");
                header('Location: /admin/sso.php?deleted=1');
                exit;
            }
        }
    }
}

if (isset($_GET['added'])) { $success = 'Connection added.'; }
if (isset($_GET['updated'])) { $success = 'Connection updated.'; }
if (isset($_GET['deleted'])) { $success = 'Connection deleted.'; }

$editing = null;
if (isset($_GET['id'])) {
    $stmt = $pdo->prepare('SELECT * FROM sso_connections WHERE id = :id');
    $stmt->execute([':id' => (int)$_GET['id']]);
    $editing = $stmt->fetch() ?: null;
}

$connections = get_sso_connections($pdo);
$redirectUri = sso_redirect_uri();

render_layout_head($pdo, 'Single Sign-On - Links Directory Admin');
?>
<script>
window.onload = function() {
  var providerSelect = document.getElementById('provider');
  var tenantGroup = document.getElementById('tenantGroup');
  function updateTenantVisibility() {
    tenantGroup.style.display = providerSelect.value === 'microsoft' ? 'block' : 'none';
  }
  providerSelect.addEventListener('change', updateTenantVisibility);
  updateTenantVisibility();

  var prefixContainer = document.getElementById('prefixRows');
  var prefixTemplate = document.getElementById('prefixRowTemplate');

  function wirePrefixRow(row) {
    row.querySelector('.remove-prefix-row').addEventListener('click', function() {
      row.remove();
    });
  }
  document.querySelectorAll('.prefix-row').forEach(wirePrefixRow);

  document.getElementById('addPrefixRow').addEventListener('click', function(e) {
    e.preventDefault();
    var clone = prefixTemplate.content.cloneNode(true);
    prefixContainer.appendChild(clone);
    wirePrefixRow(prefixContainer.lastElementChild);
  });
};
</script>
<?php render_layout_body_open($pdo, 'Single Sign-On', 'sso'); ?>

  <?php if ($error): ?>
    <div class="notice error"><?= h($error) ?></div>
  <?php endif; ?>
  <?php if ($success): ?>
    <div class="notice success"><?= h($success) ?></div>
  <?php endif; ?>

  <div class="notice success">
    <strong>Redirect URI to register with the provider:</strong><br>
    <code><?= h($redirectUri) ?></code><br>
    This exact URL only needs registering once per provider (Google, Microsoft) — every connection you add for that
    provider shares it. Which connection is being used is tracked separately, not by the URL itself.
  </div>

  <div class="container">
    <h2><?= $editing ? 'Edit Connection' : 'Add Connection' ?></h2>
    <form method="post" action="/admin/sso.php" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_connection">
      <?php if ($editing): ?>
        <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
      <?php endif; ?>

      <div class="form-group">
        <label for="provider">Provider</label>
        <select id="provider" name="provider" required>
          <?php foreach (SSO_PROVIDERS as $key => $label): ?>
            <option value="<?= h($key) ?>" <?= ($editing && $editing['provider'] === $key) ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label for="display_name">Display Name</label>
        <input type="text" id="display_name" name="display_name" value="<?= $editing ? h($editing['display_name']) : '' ?>" placeholder="e.g. Your Organization Google Workspace" required>
      </div>

      <div class="form-group">
        <label for="icon">Icon <?= $editing ? '(leave blank to keep current)' : '(optional)' ?></label>
        <input type="file" id="icon" name="icon" accept=".png,.jpg,.jpeg,.gif,.webp,.svg">
        <?php if ($editing && !empty($editing['icon_path'])): ?>
          <div style="margin-top:8px;"><img src="/assets/<?= h($editing['icon_path']) ?>" style="height:28px; border-radius:4px; border:1px solid var(--border-color);"></div>
        <?php endif; ?>
      </div>

      <div class="form-group">
        <label for="client_id">Client ID</label>
        <input type="text" id="client_id" name="client_id" value="<?= $editing ? h($editing['client_id']) : '' ?>" required>
      </div>

      <div class="form-group">
        <label for="client_secret">Client Secret <?= $editing ? '(leave blank to keep current)' : '' ?></label>
        <input type="password" id="client_secret" name="client_secret" <?= $editing ? '' : 'required' ?>>
      </div>

      <div class="form-group" id="tenantGroup">
        <label for="tenant_id">Microsoft Tenant ID (or domain) — leave blank to allow any Microsoft account</label>
        <input type="text" id="tenant_id" name="tenant_id" value="<?= $editing ? h($editing['tenant_id'] ?? '') : '' ?>">
      </div>

      <div class="form-group">
        <label for="allowed_domain">Restrict to Email Domain (optional)</label>
        <input type="text" id="allowed_domain" name="allowed_domain" value="<?= $editing ? h($editing['allowed_domain'] ?? '') : '' ?>" placeholder="e.g. snden.org">
      </div>

      <div class="form-group">
        <label for="default_role">Default Role for New Accounts</label>
        <select id="default_role" name="default_role">
          <option value="viewer" <?= (!$editing || $editing['default_role'] === 'viewer') ? 'selected' : '' ?>>Viewer</option>
          <option value="editor" <?= ($editing && $editing['default_role'] === 'editor') ? 'selected' : '' ?>>Editor</option>
          <option value="admin" <?= ($editing && $editing['default_role'] === 'admin') ? 'selected' : '' ?>>Admin</option>
        </select>
        <p style="color: var(--text-muted); font-size: 0.8rem; margin: 6px 0 0;">
          Used when no email-prefix rule below matches. If you add at least one prefix rule, sign-in is limited to
          emails matching one of them — this default only applies when no rules are defined at all.
        </p>
      </div>

      <label style="font-weight:600; font-size:0.9rem; display:block; margin-top:18px; margin-bottom:8px;">
        Email Prefix Rules (optional)
      </label>
      <p style="color: var(--text-muted); font-size: 0.8rem; margin: -4px 0 10px;">
        Restrict sign-in to emails whose part before @ starts with one of these prefixes, and assign a role per
        prefix. Leave this empty to allow any email matching the domain restriction above, with the default role.
      </p>
      <div id="prefixRows">
        <?php
        $existingRules = ($editing && !empty($editing['prefix_rules'])) ? (json_decode($editing['prefix_rules'], true) ?: []) : [];
        foreach ($existingRules as $rule):
        ?>
          <div class="prefix-row filter-row" style="margin-bottom:8px;">
            <div class="form-group">
              <input type="text" name="prefix_label[]" value="<?= h($rule['prefix'] ?? '') ?>" placeholder="e.g. admin">
            </div>
            <div class="form-group">
              <select name="prefix_role[]">
                <option value="viewer" <?= ($rule['role'] ?? '') === 'viewer' ? 'selected' : '' ?>>Viewer</option>
                <option value="editor" <?= ($rule['role'] ?? '') === 'editor' ? 'selected' : '' ?>>Editor</option>
                <option value="admin" <?= ($rule['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Admin</option>
              </select>
            </div>
            <button type="button" class="remove-prefix-row del-btn" style="flex:0 0 auto; align-self:center;">Remove</button>
          </div>
        <?php endforeach; ?>
      </div>
      <button type="button" id="addPrefixRow" class="btn" style="background:#94a3b8; margin-bottom:16px;">+ Add Prefix Rule</button>
      <br>

      <template id="prefixRowTemplate">
        <div class="prefix-row filter-row" style="margin-bottom:8px;">
          <div class="form-group">
            <input type="text" name="prefix_label[]" placeholder="e.g. admin">
          </div>
          <div class="form-group">
            <select name="prefix_role[]">
              <option value="viewer">Viewer</option>
              <option value="editor">Editor</option>
              <option value="admin">Admin</option>
            </select>
          </div>
          <button type="button" class="remove-prefix-row del-btn" style="flex:0 0 auto; align-self:center;">Remove</button>
        </div>
      </template>

      <div class="form-group" style="display:flex; align-items:center; gap:8px;">
        <input type="checkbox" id="enabled" name="enabled" value="1" <?= (!$editing || $editing['enabled']) ? 'checked' : '' ?> style="width:auto;">
        <label for="enabled" style="margin:0; font-weight:normal;">Enabled (shown as a sign-in option on the login page)</label>
      </div>

      <button type="submit"><?= $editing ? 'Save Changes' : 'Add Connection' ?></button>
      <?php if ($editing): ?>
        <a href="/admin/sso.php" class="btn" style="background:#94a3b8; margin-left:8px;">Cancel</a>
      <?php endif; ?>
    </form>
  </div>

  <div class="container">
    <h2>Existing Connections</h2>
    <?php if (empty($connections)): ?>
      <p style="color: var(--text-muted); font-size: 0.9rem;">No SSO connections yet.</p>
    <?php endif; ?>
    <?php foreach ($connections as $c): ?>
      <div class="entity-block">
        <h3 style="display:flex; align-items:center; gap:8px;">
          <?php if (!empty($c['icon_path'])): ?>
            <img src="/assets/<?= h($c['icon_path']) ?>" style="height:22px; width:22px; object-fit:contain; border-radius:4px;">
          <?php endif; ?>
          <?= h($c['display_name']) ?>
          <span class="count-badge">(<?= h(SSO_PROVIDERS[$c['provider']] ?? $c['provider']) ?>, <?= $c['enabled'] ? 'enabled' : 'disabled' ?>, default role: <?= h($c['default_role']) ?>)</span>
        </h3>
        <p style="color: var(--text-muted); font-size: 0.82rem; margin: 0 0 8px;">
          <?= $c['allowed_domain'] ? 'Restricted to @' . h($c['allowed_domain']) : 'Any account from this provider may sign in' ?>
          <?php
          $rules = !empty($c['prefix_rules']) ? (json_decode($c['prefix_rules'], true) ?: []) : [];
          if (!empty($rules)):
          ?>
            &middot; Prefix rules:
            <?php foreach ($rules as $i => $r): ?>
              <?= $i > 0 ? ', ' : '' ?><?= h($r['prefix']) ?>&rarr;<?= h($r['role']) ?>
            <?php endforeach; ?>
          <?php endif; ?>
        </p>
        <a class="edit-link" href="/admin/sso.php?id=<?= (int)$c['id'] ?>">Edit</a>
        <form method="post" action="/admin/sso.php" onsubmit="return confirm('Delete connection &quot;<?= h(addslashes($c['display_name'])) ?>&quot;?');" style="display:inline;">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete_connection">
          <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
          <button type="submit" class="del-btn">Delete</button>
        </form>
      </div>
      <hr class="section-divider">
    <?php endforeach; ?>
  </div>

<?php render_layout_close(); ?>

<?php
declare(strict_types=1);

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/theme.php';
require __DIR__ . '/../includes/layout.php';
require_role('admin');

$pdo = get_pdo();
$error = null;
$results = null;

function generate_random_password(int $length = 12): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$%';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $password;
}

function csv_bool(string $val): bool
{
    return in_array(strtolower(trim($val)), ['1', 'true', 'yes', 'y'], true);
}

// Reads an uploaded CSV into an array of associative rows, keyed by the
// header row's column names (lowercased, trimmed).
function parse_csv_upload(array $file): array
{
    if (empty($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('No CSV file was uploaded, or the upload failed.');
    }
    $handle = fopen($file['tmp_name'], 'r');
    if (!$handle) {
        throw new RuntimeException('Could not read the uploaded file.');
    }
    $header = fgetcsv($handle);
    if (!$header) {
        fclose($handle);
        throw new RuntimeException('The CSV file appears to be empty.');
    }
    $header = array_map(fn($h) => strtolower(trim((string)$h)), $header);

    $rows = [];
    while (($line = fgetcsv($handle)) !== false) {
        if (count($line) === 1 && trim((string)$line[0]) === '') {
            continue; // skip blank lines
        }
        $row = [];
        foreach ($header as $i => $col) {
            $row[$col] = trim((string)($line[$i] ?? ''));
        }
        $rows[] = $row;
    }
    fclose($handle);
    return $rows;
}

function import_platforms(PDO $pdo, array $rows, string $mode): array
{
    $created = $updated = $skipped = $errors = [];

    // Group rows by platform_name — each row is one field.
    $grouped = [];
    foreach ($rows as $n => $row) {
        $platformName = trim($row['platform_name'] ?? '');
        $fieldLabel = trim($row['field_label'] ?? '');
        if ($platformName === '' || $fieldLabel === '') {
            $errors[] = "Row " . ($n + 2) . ": missing platform_name or field_label.";
            continue;
        }
        $grouped[$platformName][] = $row;
    }

    foreach ($grouped as $platformName => $fieldRows) {
        $schema = [];
        $usedKeys = [];
        foreach ($fieldRows as $row) {
            $label = trim($row['field_label']);
            $type = normalize_field_type($row['field_type'] ?? '');
            $baseKey = slugify_key($label);
            $key = $baseKey;
            $suffix = 2;
            while (in_array($key, $usedKeys, true)) {
                $key = $baseKey . '_' . $suffix;
                $suffix++;
            }
            $usedKeys[] = $key;
            $field = [
                'key' => $key,
                'label' => $label,
                'type' => $type,
                'required' => csv_bool($row['field_required'] ?? ''),
                'hide_from_viewer' => csv_bool($row['field_hide_viewer'] ?? ''),
            ];
            if ($type === 'url' && !empty($row['field_prefix'])) {
                $field['prefix'] = trim($row['field_prefix']);
            }
            $schema[] = $field;
        }

        $check = $pdo->prepare('SELECT id FROM platforms WHERE display_name = :name');
        $check->execute([':name' => $platformName]);
        $existingId = $check->fetchColumn();

        if ($existingId !== false) {
            if ($mode === 'skip') {
                $skipped[] = "{$platformName} (already exists, {" . count($schema) . "} fields in file skipped)";
                continue;
            }
            $pdo->prepare('UPDATE platforms SET field_schema = :schema WHERE id = :id')
                ->execute([':schema' => json_encode($schema), ':id' => $existingId]);
            $updated[] = "{$platformName} ({" . count($schema) . "} fields)";
        } else {
            $key = slugify_key($platformName);
            $baseKey = $key;
            $suffix = 2;
            while (true) {
                $c = $pdo->prepare('SELECT COUNT(*) FROM platforms WHERE platform_key = :key');
                $c->execute([':key' => $key]);
                if ((int)$c->fetchColumn() === 0) {
                    break;
                }
                $key = $baseKey . '_' . $suffix;
                $suffix++;
            }
            $maxOrder = (int)$pdo->query('SELECT COALESCE(MAX(sort_order), 0) FROM platforms')->fetchColumn();
            $pdo->prepare('INSERT INTO platforms (platform_key, display_name, field_schema, sort_order) VALUES (:key, :name, :schema, :sort)')
                ->execute([':key' => $key, ':name' => $platformName, ':schema' => json_encode($schema), ':sort' => $maxOrder + 1]);
            $created[] = "{$platformName} ({" . count($schema) . "} fields, no logo — add one on the Platforms page)";
        }
    }
    return compact('created', 'updated', 'skipped', 'errors');
}

function import_devices(PDO $pdo, array $rows, string $mode): array
{
    $created = $updated = $skipped = $errors = [];

    $platforms = get_platforms($pdo);
    $platformsByName = [];
    foreach ($platforms as $p) {
        $platformsByName[strtolower($p['display_name'])] = $p;
    }

    foreach ($rows as $n => $row) {
        $entityName = trim($row['entity_name'] ?? '');
        $schoolName = trim($row['school_name'] ?? '');
        $deviceName = trim($row['device_name'] ?? '');
        $platformName = trim($row['platform_name'] ?? '');

        if ($entityName === '' || $schoolName === '' || $deviceName === '' || $platformName === '') {
            $errors[] = "Row " . ($n + 2) . ": missing entity_name, school_name, device_name, or platform_name.";
            continue;
        }
        $platform = $platformsByName[strtolower($platformName)] ?? null;
        if (!$platform) {
            $errors[] = "Row " . ($n + 2) . " ({$deviceName}): platform '{$platformName}' not found. Add it on the Platforms page first.";
            continue;
        }

        // Get-or-create entity and school, same convenience as Schools import.
        $entStmt = $pdo->prepare('SELECT id FROM entities WHERE name = :name');
        $entStmt->execute([':name' => $entityName]);
        $entityId = $entStmt->fetchColumn();
        if ($entityId === false) {
            $pdo->prepare('INSERT INTO entities (name) VALUES (:name)')->execute([':name' => $entityName]);
            $entityId = (int)$pdo->lastInsertId();
        }
        $schStmt = $pdo->prepare('SELECT id FROM schools WHERE entity_id = :eid AND name = :name');
        $schStmt->execute([':eid' => $entityId, ':name' => $schoolName]);
        if (!$schStmt->fetch()) {
            $pdo->prepare('INSERT INTO schools (entity_id, name) VALUES (:eid, :name)')->execute([':eid' => $entityId, ':name' => $schoolName]);
        }

        // Only pick up CSV columns that match this platform's own field keys.
        $fields = [];
        foreach ($platform['field_schema'] as $f) {
            $val = trim($row[$f['key']] ?? '');
            if ($val === '') {
                $fields[$f['key']] = null;
                continue;
            }
            if ($f['type'] === 'url' && !empty($f['prefix']) && !preg_match('#^https?://#i', $val)) {
                $val = $f['prefix'] . $val;
            }
            $fields[$f['key']] = $val;
        }

        $check = $pdo->prepare('SELECT id FROM devices WHERE entity = :e AND school = :s AND device_name = :d');
        $check->execute([':e' => $entityName, ':s' => $schoolName, ':d' => $deviceName]);
        $existingId = $check->fetchColumn();

        if ($existingId !== false) {
            if ($mode === 'skip') {
                $skipped[] = "{$entityName} / {$schoolName} / {$deviceName} (already exists)";
                continue;
            }
            $pdo->prepare('UPDATE devices SET platform_id = :pid, fields = :fields WHERE id = :id')
                ->execute([':pid' => $platform['id'], ':fields' => json_encode($fields), ':id' => $existingId]);
            $updated[] = "{$entityName} / {$schoolName} / {$deviceName}";
        } else {
            $pdo->prepare('INSERT INTO devices (entity, school, device_name, platform_id, fields) VALUES (:e, :s, :d, :pid, :fields)')
                ->execute([':e' => $entityName, ':s' => $schoolName, ':d' => $deviceName, ':pid' => $platform['id'], ':fields' => json_encode($fields)]);
            $created[] = "{$entityName} / {$schoolName} / {$deviceName}";
        }
    }
    return compact('created', 'updated', 'skipped', 'errors');
}

function import_users(PDO $pdo, array $rows, string $mode): array
{
    $created = $updated = $skipped = $errors = [];
    $generatedPasswords = [];

    foreach ($rows as $n => $row) {
        $username = trim($row['username'] ?? '');
        $role = strtolower(trim($row['role'] ?? 'viewer'));
        if (!array_key_exists($role, ROLE_LEVELS)) {
            $role = 'viewer';
        }
        if ($username === '') {
            $errors[] = "Row " . ($n + 2) . ": missing username.";
            continue;
        }

        $check = $pdo->prepare('SELECT id FROM admin_users WHERE username = :u');
        $check->execute([':u' => $username]);
        $existingId = $check->fetchColumn();

        if ($existingId !== false) {
            if ($mode === 'skip') {
                $skipped[] = "{$username} (already exists — password left untouched)";
                continue;
            }
            $pdo->prepare('UPDATE admin_users SET role = :role WHERE id = :id')
                ->execute([':role' => $role, ':id' => $existingId]);
            $updated[] = "{$username} (role set to {$role}; password left untouched)";
        } else {
            $password = generate_random_password();
            $pdo->prepare('INSERT INTO admin_users (username, password_hash, role, must_reset_password) VALUES (:u, :h, :r, 1)')
                ->execute([':u' => $username, ':h' => password_hash($password, PASSWORD_DEFAULT), ':r' => $role]);
            $created[] = "{$username} ({$role})";
            $generatedPasswords[$username] = $password;
        }
    }
    return compact('created', 'updated', 'skipped', 'errors', 'generatedPasswords');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import') {
    require_csrf();
    $type = $_POST['import_type'] ?? '';
    $mode = ($_POST['duplicate_mode'] ?? 'skip') === 'update' ? 'update' : 'skip';

    try {
        $rows = parse_csv_upload($_FILES['csv_file'] ?? []);
        $result = match ($type) {
            'platforms' => import_platforms($pdo, $rows, $mode),
            'devices' => import_devices($pdo, $rows, $mode),
            'users' => import_users($pdo, $rows, $mode),
            default => throw new RuntimeException('Invalid import type.'),
        };
        $results = ['type' => $type] + $result;

        $counts = 'created=' . count($result['created'] ?? []) . ', updated=' . count($result['updated'] ?? []) . ', skipped=' . count($result['skipped'] ?? []) . ', errors=' . count($result['errors'] ?? []);
        log_action($pdo, 'import_' . $type, "Imported {$type} ({$mode} mode): {$counts}.");
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}

// Simple downloadable CSV templates so admins know the exact expected columns.
// The Devices template is built dynamically from whatever platforms and
// fields actually exist right now, so it always includes every column an
// import could use — including custom platforms and fields added later —
// rather than a fixed example that would need to be kept in sync by hand.
if (isset($_GET['template'])) {
    $t = $_GET['template'];

    if ($t === 'devices') {
        $allPlatforms = get_platforms($pdo);
        $columns = ['entity_name', 'school_name', 'device_name', 'platform_name'];
        $fieldKeysSeen = [];
        foreach ($allPlatforms as $p) {
            foreach ($p['field_schema'] as $f) {
                if (!in_array($f['key'], $fieldKeysSeen, true)) {
                    $fieldKeysSeen[] = $f['key'];
                    $columns[] = $f['key'];
                }
            }
        }
        $lines = [implode(',', $columns)];
        // One example row per existing platform, with only that platform's
        // own columns filled in — makes it obvious which columns apply to
        // which platform_name.
        foreach ($allPlatforms as $p) {
            $row = ['ESU 17', 'Example School', $p['display_name'] . ' Example Room', $p['display_name']];
            foreach (array_slice($columns, 4) as $col) {
                $row[] = ''; // left blank in the template; only the column names matter here
            }
            $lines[] = implode(',', $row);
        }
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="devices_template.csv"');
        echo implode("\n", $lines);
        exit;
    }

    $templates = [
        'platforms' => ["platform_name,field_label,field_type,field_required,field_hide_viewer,field_prefix",
            "Cisco Webex,Meeting Link,url,no,no,https://example.webex.com/meet/",
            "Cisco Webex,Room Email,text,no,no,"],
        'users' => ["username,role", "jdoe,editor"],
    ];
    if (isset($templates[$t])) {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $t . '_template.csv"');
        echo implode("\n", $templates[$t]);
        exit;
    }
}

render_layout_head($pdo, 'Import - Links Directory Admin');
?>
<script>
window.onload = function() {
  var modeRadios = document.querySelectorAll('input[name="duplicate_mode"]');
  var warning = document.getElementById('updateWarning');
  function updateWarningVisibility() {
    var selected = document.querySelector('input[name="duplicate_mode"]:checked');
    warning.style.display = (selected && selected.value === 'update') ? 'block' : 'none';
  }
  modeRadios.forEach(function(r) { r.addEventListener('change', updateWarningVisibility); });
  updateWarningVisibility();
};
</script>
<?php render_layout_body_open($pdo, 'Import', 'import'); ?>

  <?php if ($error): ?>
    <div class="notice error"><?= h($error) ?></div>
  <?php endif; ?>

  <?php if ($results): ?>
    <div class="container">
      <h2>Import Results: <?= h(ucfirst($results['type'])) ?></h2>

      <?php if (!empty($results['generatedPasswords'])): ?>
        <?php
          $csvLines = ["username,password"];
          foreach ($results['generatedPasswords'] as $u => $p) {
              $csvLines[] = $u . ',' . $p;
          }
          $csvContent = implode("\n", $csvLines);
          $csvDataUri = 'data:text/csv;charset=utf-8,' . rawurlencode($csvContent);
        ?>
        <div class="notice error">
          <strong>Save these generated passwords now — they will not be shown again.</strong>
          <table class="device-table" style="margin-top:8px;">
            <thead><tr><th>Username</th><th>Password</th></tr></thead>
            <tbody>
              <?php foreach ($results['generatedPasswords'] as $u => $p): ?>
                <tr><td data-label="Username"><?= h($u) ?></td><td data-label="Password"><code><?= h($p) ?></code></td></tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <a href="<?= $csvDataUri ?>" download="new_user_passwords.csv" class="btn" style="display:inline-block; margin-top:10px;">Download as CSV</a><br>
          Each of these accounts is flagged to require a password reset at first login. This download link only
          works on this page load — reloading the page clears it, since these passwords are never stored anywhere.
        </div>
      <?php endif; ?>

      <p><strong>Created:</strong> <?= count($results['created'] ?? []) ?></p>
      <?php if (!empty($results['created'])): ?>
        <ul>
          <?php foreach ($results['created'] as $c): ?><li><?= h($c) ?></li><?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <?php if (isset($results['updated'])): ?>
        <p><strong>Updated:</strong> <?= count($results['updated']) ?></p>
        <?php if (!empty($results['updated'])): ?>
          <ul>
            <?php foreach ($results['updated'] as $u): ?><li><?= h($u) ?></li><?php endforeach; ?>
          </ul>
        <?php endif; ?>
      <?php endif; ?>

      <p><strong>Skipped:</strong> <?= count($results['skipped'] ?? []) ?></p>
      <?php if (!empty($results['skipped'])): ?>
        <ul>
          <?php foreach ($results['skipped'] as $s): ?><li><?= h($s) ?></li><?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <?php if (!empty($results['errors'])): ?>
        <p><strong>Errors:</strong> <?= count($results['errors']) ?></p>
        <ul>
          <?php foreach ($results['errors'] as $e): ?><li style="color: var(--danger);"><?= h($e) ?></li><?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="container">
    <h2>Import Data</h2>
    <form method="post" action="/admin/import.php" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="import">

      <div class="form-group">
        <label for="import_type">What are you importing?</label>
        <select id="import_type" name="import_type" required>
          <option value="platforms" <?= ($_POST['import_type'] ?? 'platforms') === 'platforms' ? 'selected' : '' ?>>Platforms</option>
          <option value="devices" <?= ($_POST['import_type'] ?? '') === 'devices' ? 'selected' : '' ?>>Devices</option>
          <option value="users" <?= ($_POST['import_type'] ?? '') === 'users' ? 'selected' : '' ?>>Users</option>
        </select>
        <p style="color: var(--text-muted); font-size: 0.8rem; margin: 6px 0 0;">
          There's no separate Entities/Schools import — a Devices import creates any Entity or School named in it automatically.
        </p>
      </div>

      <div class="type-toggle">
        <label>
          <input type="radio" name="duplicate_mode" value="skip" <?= ($_POST['duplicate_mode'] ?? 'skip') !== 'update' ? 'checked' : '' ?>>
          <span>Skip Duplicates</span>
        </label>
        <label>
          <input type="radio" name="duplicate_mode" value="update" <?= ($_POST['duplicate_mode'] ?? '') === 'update' ? 'checked' : '' ?>>
          <span>Update Existing</span>
        </label>
      </div>

      <div id="updateWarning" class="notice error" style="display:none;">
        <strong>Warning: this will overwrite existing data</strong> for any row that matches something already in the system (matched by name/username, as applicable). This cannot be undone automatically — consider running a database backup first if you're unsure.
      </div>

      <div class="form-group">
        <label for="csv_file">CSV File</label>
        <input type="file" id="csv_file" name="csv_file" accept=".csv" required>
      </div>

      <button type="submit">Import</button>
    </form>

    <p style="margin-top:18px; font-size:0.85rem;">
      Download a template: 
      <a href="?template=platforms">Platforms</a> &middot;
      <a href="?template=devices">Devices</a> &middot;
      <a href="?template=users">Users</a>
    </p>
    <p style="color: var(--text-muted); font-size: 0.82rem;">
      Platforms: one row per field — rows sharing the same Platform Name are grouped into one platform's field list.
      A platform created this way starts with no logo; add one afterward on the Platforms page.<br>
      Devices: the template always includes every field column from every platform that currently exists, so nothing
      needs filling in by hand afterward — only the columns matching a row's own Platform Name are actually read;
      everything else in that row is ignored.
    </p>
  </div>

<?php render_layout_close(); ?>

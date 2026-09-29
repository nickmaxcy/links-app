<?php
declare(strict_types=1);

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/theme.php';
require __DIR__ . '/../includes/layout.php';
require_role('editor');

$pdo = get_pdo();
$error = null;
$allowedEntities = get_allowed_entity_names($pdo, (int)current_admin_id(), (string)current_role());

$platforms = get_platforms($pdo);
$platformsById = [];
foreach ($platforms as $p) {
    $platformsById[(int)$p['id']] = $p;
}

// Builds a fields JSON object from posted field_values[<key>] using the
// platform's own field_schema as the authority for types (so a URL field's
// prefix is always applied server-side, never trusted from the client).
function build_fields_json(array $schema, array $posted): string
{
    $out = [];
    foreach ($schema as $f) {
        $key = $f['key'];
        $raw = trim((string)($posted[$key] ?? ''));
        if ($raw === '') {
            $out[$key] = null;
            continue;
        }
        if ($f['type'] === 'url') {
            $prefix = $f['prefix'] ?? '';
            if ($prefix !== '' && !preg_match('#^https?://#i', $raw)) {
                $raw = $prefix . $raw;
            }
        }
        $out[$key] = $raw;
    }
    return json_encode($out);
}

// --- Add ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_device') {
    require_csrf();
    $entity = trim($_POST['entity'] ?? '');
    $school = trim($_POST['school'] ?? '');
    $deviceName = trim($_POST['device_name'] ?? '');
    $platformId = (int)($_POST['platform_id'] ?? 0);

    if ($entity === '' || $school === '' || $deviceName === '' || !isset($platformsById[$platformId])) {
        $error = 'Entity, School, Device Name, and Platform are required.';
    } elseif ($allowedEntities !== null && !in_array($entity, $allowedEntities, true)) {
        $error = 'You do not have access to add devices under that entity.';
    } else {
        $schema = $platformsById[$platformId]['field_schema'];
        $fieldsJson = build_fields_json($schema, $_POST['field_values'] ?? []);

        $stmt = $pdo->prepare(
            'INSERT INTO devices (entity, school, device_name, platform_id, fields) VALUES (:entity, :school, :device_name, :platform_id, :fields)'
        );
        $stmt->execute([
            ':entity' => $entity, ':school' => $school, ':device_name' => $deviceName,
            ':platform_id' => $platformId, ':fields' => $fieldsJson,
        ]);
        log_action($pdo, 'add_device', "Added '{$deviceName}' ({$platformsById[$platformId]['display_name']}) under {$entity} / {$school}.");
        header('Location: /admin/devices.php?added=1');
        exit;
    }
}

// --- Update ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_device') {
    require_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $entity = trim($_POST['entity'] ?? '');
    $school = trim($_POST['school'] ?? '');
    $deviceName = trim($_POST['device_name'] ?? '');
    $platformId = (int)($_POST['platform_id'] ?? 0);

    // Also verify the device's CURRENT entity is allowed, not just the
    // submitted one — otherwise a restricted user could move a device they
    // can't normally reach by resubmitting the form with a different id.
    $currentEntityStmt = $pdo->prepare('SELECT entity FROM devices WHERE id = :id');
    $currentEntityStmt->execute([':id' => $id]);
    $currentEntity = $currentEntityStmt->fetchColumn();

    if ($id <= 0 || $entity === '' || $school === '' || $deviceName === '' || !isset($platformsById[$platformId])) {
        $error = 'Entity, School, Device Name, and Platform are required.';
    } elseif ($allowedEntities !== null && (
        !in_array($entity, $allowedEntities, true)
        || $currentEntity === false
        || !in_array($currentEntity, $allowedEntities, true)
    )) {
        $error = 'You do not have access to that device.';
    } else {
        $schema = $platformsById[$platformId]['field_schema'];
        $fieldsJson = build_fields_json($schema, $_POST['field_values'] ?? []);

        $stmt = $pdo->prepare(
            'UPDATE devices SET entity = :entity, school = :school, device_name = :device_name, platform_id = :platform_id, fields = :fields WHERE id = :id'
        );
        $stmt->execute([
            ':entity' => $entity, ':school' => $school, ':device_name' => $deviceName,
            ':platform_id' => $platformId, ':fields' => $fieldsJson, ':id' => $id,
        ]);
        log_action($pdo, 'update_device', "Updated device #{$id} ('{$deviceName}') under {$entity} / {$school}.");
        header('Location: /admin/devices.php?updated=1');
        exit;
    }
}

// Editing a specific device (blank form = "add" mode if no ?id)
$editing = null;
$editingFields = [];
if (isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmt = $pdo->prepare('SELECT * FROM devices WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $editing = $stmt->fetch();
    if (!$editing) {
        $error = 'Device not found.';
    } elseif ($allowedEntities !== null && !in_array($editing['entity'], $allowedEntities, true)) {
        $error = 'You do not have access to that device.';
        $editing = null;
    } else {
        $editingFields = json_decode($editing['fields'], true) ?: [];
    }
}

$entities = natsort_rows_by($pdo->query('SELECT id, name FROM entities')->fetchAll(), 'name');
if ($allowedEntities !== null) {
    $entities = array_values(array_filter($entities, fn($e) => in_array($e['name'], $allowedEntities, true)));
}

$schoolMap = [];
$schoolRows = natsort_rows_by($pdo->query(
    'SELECT e.name AS entity_name, s.name AS school_name
     FROM schools s JOIN entities e ON e.id = s.entity_id'
)->fetchAll(), 'school_name');
foreach ($schoolRows as $sr) {
    $schoolMap[$sr['entity_name']][] = $sr['school_name'];
}
uksort($schoolMap, 'strnatcasecmp');

if ($editing) {
    $entityNames = array_column($entities, 'name');
    if (!in_array($editing['entity'], $entityNames, true)) {
        $entities[] = ['id' => 0, 'name' => $editing['entity']];
    }
    if (!isset($schoolMap[$editing['entity']]) || !in_array($editing['school'], $schoolMap[$editing['entity']], true)) {
        $schoolMap[$editing['entity']][] = $editing['school'];
    }
}

$jsonFlags = JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
render_layout_head($pdo, ($editing ? 'Edit Device' : 'Add Device') . ' - Links Directory Admin');
?>
<script>
var schoolsByEntity = <?= json_encode($schoolMap, $jsonFlags) ?>;
var platformSchemas = <?= json_encode($platformsById, $jsonFlags) ?>;

function renderFieldInputs(platformId, values) {
  values = values || {};
  var platform = platformSchemas[platformId];
  var container = document.getElementById('dynamicFields');
  container.innerHTML = '';
  if (!platform) return;

  platform.field_schema.forEach(function(f) {
    var group = document.createElement('div');
    group.className = 'form-group';

    var label = document.createElement('label');
    label.textContent = f.label + (f.required ? ' *' : '');
    group.appendChild(label);

    var val = values[f.key] || '';

    if (f.type === 'yesno') {
      var select = document.createElement('select');
      select.name = 'field_values[' + f.key + ']';
      ['', 'Yes', 'No'].forEach(function(opt) {
        var o = document.createElement('option');
        o.value = opt;
        o.text = opt === '' ? '-- Select --' : opt;
        if (opt === val) o.selected = true;
        select.appendChild(o);
      });
      group.appendChild(select);
    } else if (f.type === 'url' && f.prefix) {
      var wrap = document.createElement('div');
      wrap.className = 'input-group';
      var prefixSpan = document.createElement('span');
      prefixSpan.className = 'input-prefix';
      prefixSpan.textContent = f.prefix;
      wrap.appendChild(prefixSpan);
      var input = document.createElement('input');
      input.type = 'text';
      input.name = 'field_values[' + f.key + ']';
      var displayVal = val;
      if (val.indexOf(f.prefix) === 0) displayVal = val.substring(f.prefix.length);
      input.value = displayVal;
      wrap.appendChild(input);
      group.appendChild(wrap);
    } else {
      var inputType = (f.type === 'number') ? 'number' : 'text';
      var input = document.createElement('input');
      input.type = inputType;
      input.name = 'field_values[' + f.key + ']';
      input.value = val;
      if (f.type === 'ip') input.placeholder = 'e.g. 192.168.1.10';
      group.appendChild(input);
    }

    container.appendChild(group);
  });
}

window.onload = function() {
  var entitySel = document.getElementById('entity');
  var schoolSel = document.getElementById('school');
  var platformSel = document.getElementById('platform_id');

  if (entitySel) {
    entitySel.onchange = function() {
      var schools = schoolsByEntity[this.value] || [];
      schoolSel.innerHTML = '';
      if (!this.value) {
        schoolSel.innerHTML = '<option value="">Select entity first</option>';
        return;
      }
      if (schools.length === 0) {
        schoolSel.innerHTML = '<option value="">No schools set up for this entity yet</option>';
        return;
      }
      schoolSel.innerHTML = '<option value="">Select School</option>';
      schools.forEach(function(s) {
        var opt = document.createElement('option');
        opt.value = s;
        opt.text = s;
        schoolSel.appendChild(opt);
      });
    };
  }

  platformSel.addEventListener('change', function() {
    renderFieldInputs(this.value, {});
  });

  var initialValues = <?= json_encode($editingFields, $jsonFlags) ?>;
  renderFieldInputs(platformSel.value, initialValues);
};
</script>
<?php render_layout_body_open($pdo, $editing ? 'Edit Device' : 'Add Device', 'devices'); ?>

  <?php if ($error): ?>
    <div class="notice error"><?= h($error) ?></div>
  <?php endif; ?>

  <?php if (empty($entities)): ?>
    <div class="notice error">No entities are set up yet. <a href="/admin/setup.php">Add one on the Setup page</a> before adding a device.</div>
  <?php endif; ?>
  <?php if (empty($platforms)): ?>
    <div class="notice error">No platforms are set up yet. <a href="/admin/platforms.php">Add one on the Platforms page</a> before adding a device.</div>
  <?php endif; ?>

  <div class="container">
    <h2><?= $editing ? 'Edit Device' : 'Add Device' ?></h2>

    <form method="post" action="/admin/device-form.php<?= $editing ? '?id=' . (int)$editing['id'] : '' ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="<?= $editing ? 'update_device' : 'add_device' ?>">
      <?php if ($editing): ?>
        <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
      <?php endif; ?>

      <div class="form-group">
        <label for="entity">Entity</label>
        <select id="entity" name="entity" required>
          <?php if (!$editing): ?><option value="">Select Entity</option><?php endif; ?>
          <?php foreach ($entities as $e): ?>
            <option value="<?= h($e['name']) ?>" <?= ($editing && $e['name'] === $editing['entity']) ? 'selected' : '' ?>><?= h($e['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label for="school">School</label>
        <select id="school" name="school" required>
          <?php if ($editing): ?>
            <?php foreach (($schoolMap[$editing['entity']] ?? []) as $s): ?>
              <option value="<?= h($s) ?>" <?= $s === $editing['school'] ? 'selected' : '' ?>><?= h($s) ?></option>
            <?php endforeach; ?>
          <?php else: ?>
            <option value="">Select entity first</option>
          <?php endif; ?>
        </select>
      </div>

      <div class="form-group">
        <label for="device_name">Device Name</label>
        <input type="text" id="device_name" name="device_name" value="<?= $editing ? h($editing['device_name']) : '' ?>" required>
      </div>

      <div class="form-group">
        <label for="platform_id">Platform</label>
        <select id="platform_id" name="platform_id" required>
          <?php foreach ($platforms as $p): ?>
            <option value="<?= (int)$p['id'] ?>" <?= ($editing && (int)$editing['platform_id'] === (int)$p['id']) ? 'selected' : '' ?>><?= h($p['display_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div id="dynamicFields"></div>

      <button type="submit"><?= $editing ? 'Save Changes' : 'Add Device' ?></button>
      <a href="/admin/devices.php" class="btn" style="background:#94a3b8; margin-left:8px;">Cancel</a>
    </form>
  </div>

<?php render_layout_close(); ?>

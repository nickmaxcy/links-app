<?php
declare(strict_types=1);

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/theme.php';
require __DIR__ . '/../includes/layout.php';
require_role('editor');

$pdo = get_pdo();
$success = null;
$allowedEntities = get_allowed_entity_names($pdo, (int)current_admin_id(), (string)current_role());

// --- Delete ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_device') {
    require_csrf();
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $lookup = $pdo->prepare('SELECT entity, school, device_name FROM devices WHERE id = :id');
        $lookup->execute([':id' => $id]);
        $target = $lookup->fetch();

        if ($target && ($allowedEntities === null || in_array($target['entity'], $allowedEntities, true))) {
            $pdo->prepare('DELETE FROM devices WHERE id = :id')->execute([':id' => $id]);
            log_action($pdo, 'delete_device', "Deleted device #{$id} ('{$target['device_name']}') under {$target['entity']} / {$target['school']}.");
        }
    }
    header('Location: /admin/devices.php?deleted=1');
    exit;
}

if (isset($_GET['added'])) { $success = 'Device added successfully.'; }
if (isset($_GET['updated'])) { $success = 'Device updated successfully.'; }
if (isset($_GET['deleted'])) { $success = 'Device deleted.'; }

$rows = $pdo->query('SELECT d.id, d.entity, d.school, d.device_name, d.platform_id, p.display_name AS platform_name FROM devices d JOIN platforms p ON p.id = d.platform_id')->fetchAll();
if ($allowedEntities !== null) {
    $rows = array_values(array_filter($rows, fn($r) => in_array($r['entity'], $allowedEntities, true)));
}
usort($rows, fn($a, $b) => strnatcasecmp($a['entity'], $b['entity'])
    ?: strnatcasecmp($a['school'], $b['school'])
    ?: strnatcasecmp($a['device_name'], $b['device_name']));

$listEntitySchoolMap = [];
foreach ($rows as $row) {
    if (!isset($listEntitySchoolMap[$row['entity']])) {
        $listEntitySchoolMap[$row['entity']] = [];
    }
    if (!in_array($row['school'], $listEntitySchoolMap[$row['entity']], true)) {
        $listEntitySchoolMap[$row['entity']][] = $row['school'];
    }
}
uksort($listEntitySchoolMap, 'strnatcasecmp');
foreach ($listEntitySchoolMap as &$listSchools) {
    usort($listSchools, 'strnatcasecmp');
}
unset($listSchools);

$jsonFlags = JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
render_layout_head($pdo, 'Devices - Links Directory Admin');
?>
<script>
var listEntitySchoolMap = <?= json_encode($listEntitySchoolMap, $jsonFlags) ?>;

window.onload = function() {
  var listEntityFilter = document.getElementById('listFilterEntity');
  var listSchoolFilter = document.getElementById('listFilterSchool');
  var listNameFilter = document.getElementById('listFilterName');
  var listRows = document.querySelectorAll('#deviceListTable tbody tr');

  function applyListFilter() {
    var e = listEntityFilter.value;
    var s = listSchoolFilter.value;
    var n = listNameFilter.value.trim().toLowerCase();
    listRows.forEach(function(row) {
      var matchEntity = !e || row.dataset.entity === e;
      var matchSchool = !s || row.dataset.school === s;
      var matchName = !n || row.dataset.name.indexOf(n) !== -1;
      row.style.display = (matchEntity && matchSchool && matchName) ? '' : 'none';
    });
  }

  listEntityFilter.onchange = function() {
    var schools = listEntitySchoolMap[this.value] || [];
    listSchoolFilter.innerHTML = '<option value="">All Schools</option>';
    schools.forEach(function(s) {
      var opt = document.createElement('option');
      opt.value = s;
      opt.text = s;
      listSchoolFilter.appendChild(opt);
    });
    applyListFilter();
  };
  listSchoolFilter.onchange = applyListFilter;
  listNameFilter.oninput = applyListFilter;
};
</script>
<?php render_layout_body_open($pdo, 'Devices', 'devices'); ?>

  <?php if ($success): ?>
    <div class="notice success"><?= h($success) ?></div>
  <?php endif; ?>

  <div class="container">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 4px;">
      <h2 style="margin-bottom:0;">All Devices</h2>
      <a href="/admin/device-form.php" class="btn">+ Add Device</a>
    </div>

    <div class="filter-row">
      <div class="form-group">
        <label for="listFilterEntity">Filter by Entity</label>
        <select id="listFilterEntity">
          <option value="">All Entities</option>
          <?php foreach (array_keys($listEntitySchoolMap) as $e): ?>
            <option value="<?= h($e) ?>"><?= h($e) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label for="listFilterSchool">Filter by School</label>
        <select id="listFilterSchool">
          <option value="">All Schools</option>
        </select>
      </div>
      <div class="form-group">
        <label for="listFilterName">Search by Device Name</label>
        <input type="text" id="listFilterName" placeholder="Type to search...">
      </div>
    </div>

    <table class="device-table" id="deviceListTable">
      <thead>
        <tr><th>Entity</th><th>School</th><th>Device</th><th>Platform</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $row): ?>
          <tr data-entity="<?= h($row['entity']) ?>" data-school="<?= h($row['school']) ?>" data-name="<?= h(strtolower($row['device_name'])) ?>">
            <td data-label="Entity"><?= h($row['entity']) ?></td>
            <td data-label="School"><?= h($row['school']) ?></td>
            <td data-label="Device"><?= h($row['device_name']) ?></td>
            <td data-label="Platform"><?= h($row['platform_name']) ?></td>
            <td class="actions">
              <a class="edit-link" href="/admin/device-form.php?id=<?= (int)$row['id'] ?>">Edit</a>
              <form method="post" action="/admin/devices.php" onsubmit="return confirm('Delete &quot;<?= h(addslashes($row['device_name'])) ?>&quot;? This cannot be undone.');" style="margin:0; display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_device">
                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                <button type="submit" class="del-btn">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php render_layout_close(); ?>

<?php
declare(strict_types=1);

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/theme.php';
require __DIR__ . '/../includes/layout.php';
require_role('editor');

$pdo = get_pdo();
$error = null;
$success = null;
$myRole = (string)current_role();
$allowedEntities = get_allowed_entity_names($pdo, (int)current_admin_id(), $myRole);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_entity') {
        if ($myRole !== 'admin') {
            $error = 'Only admins can create new entities.';
        } else {
            $name = trim($_POST['entity_name'] ?? '');
            if ($name === '') {
                $error = 'Entity name is required.';
            } else {
                try {
                    $stmt = $pdo->prepare('INSERT INTO entities (name) VALUES (:name)');
                    $stmt->execute([':name' => $name]);
                    log_action($pdo, 'add_entity', "Added entity '{$name}'.");
                    header('Location: /admin/setup.php?added_entity=1');
                    exit;
                } catch (PDOException $e) {
                    $error = ($e->getCode() === '23000')
                        ? 'That entity already exists.'
                        : 'Could not add entity: ' . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'delete_entity') {
        if ($myRole !== 'admin') {
            $error = 'Only admins can delete entities.';
        } else {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare('SELECT name FROM entities WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $entityName = $stmt->fetchColumn();

            if ($entityName === false) {
                $error = 'Entity not found.';
            } else {
                $countStmt = $pdo->prepare('SELECT COUNT(*) FROM devices WHERE entity = :entity');
                $countStmt->execute([':entity' => $entityName]);
                $deviceCount = (int)$countStmt->fetchColumn();

                if ($deviceCount > 0) {
                    $error = "Cannot delete \"{$entityName}\": {$deviceCount} device(s) still use this entity. Remove or reassign them first.";
                } else {
                    $del = $pdo->prepare('DELETE FROM entities WHERE id = :id');
                    $del->execute([':id' => $id]);
                    log_action($pdo, 'delete_entity', "Deleted entity '{$entityName}'.");
                    header('Location: /admin/setup.php?deleted_entity=1');
                    exit;
                }
            }
        }
    } elseif ($action === 'add_school') {
        $entityId = (int)($_POST['entity_id'] ?? 0);
        $name = trim($_POST['school_name'] ?? '');

        $entityNameStmt = $pdo->prepare('SELECT name FROM entities WHERE id = :id');
        $entityNameStmt->execute([':id' => $entityId]);
        $entityName = $entityNameStmt->fetchColumn();

        if ($entityId <= 0 || $name === '' || $entityName === false) {
            $error = 'Entity and school name are required.';
        } elseif ($allowedEntities !== null && !in_array($entityName, $allowedEntities, true)) {
            $error = 'You do not have access to add schools under that entity.';
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO schools (entity_id, name) VALUES (:entity_id, :name)');
                $stmt->execute([':entity_id' => $entityId, ':name' => $name]);
                log_action($pdo, 'add_school', "Added school '{$name}' (entity id {$entityId}).");
                header('Location: /admin/setup.php?added_school=1');
                exit;
            } catch (PDOException $e) {
                $error = ($e->getCode() === '23000')
                    ? 'That school already exists under this entity.'
                    : 'Could not add school: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'delete_school') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare(
            'SELECT s.name AS school_name, e.name AS entity_name
             FROM schools s JOIN entities e ON e.id = s.entity_id
             WHERE s.id = :id'
        );
        $stmt->execute([':id' => $id]);
        $school = $stmt->fetch();

        if (!$school) {
            $error = 'School not found.';
        } elseif ($allowedEntities !== null && !in_array($school['entity_name'], $allowedEntities, true)) {
            $error = 'You do not have access to that school.';
        } else {
            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM devices WHERE entity = :entity AND school = :school');
            $countStmt->execute([':entity' => $school['entity_name'], ':school' => $school['school_name']]);
            $deviceCount = (int)$countStmt->fetchColumn();

            if ($deviceCount > 0) {
                $error = "Cannot delete \"{$school['school_name']}\": {$deviceCount} device(s) still use this school. Remove or reassign them first.";
            } else {
                $del = $pdo->prepare('DELETE FROM schools WHERE id = :id');
                $del->execute([':id' => $id]);
                log_action($pdo, 'delete_school', "Deleted school '{$school['school_name']}' under {$school['entity_name']}.");
                header('Location: /admin/setup.php?deleted_school=1');
                exit;
            }
        }
    }
}

if (isset($_GET['added_entity'])) { $success = 'Entity added.'; }
if (isset($_GET['deleted_entity'])) { $success = 'Entity deleted.'; }
if (isset($_GET['added_school'])) { $success = 'School added.'; }
if (isset($_GET['deleted_school'])) { $success = 'School deleted.'; }

// Entity Access restrictions gate write actions only - every editor sees
// every entity and school here. $addableEntities (restricted) feeds the
// "Add School" dropdown; $entities (unrestricted) feeds the full listing.
$entities = natsort_rows_by($pdo->query('SELECT id, name FROM entities')->fetchAll(), 'name');
$addableEntities = $entities;
if ($allowedEntities !== null) {
    $addableEntities = array_values(array_filter($entities, fn($e) => in_array($e['name'], $allowedEntities, true)));
}

$schoolsByEntityId = [];
$schoolRows = natsort_rows_by($pdo->query('SELECT id, entity_id, name FROM schools')->fetchAll(), 'name');
foreach ($schoolRows as $sr) {
    $schoolsByEntityId[$sr['entity_id']][] = $sr;
}

$entityDeviceCounts = [];
foreach ($pdo->query('SELECT entity, COUNT(*) AS cnt FROM devices GROUP BY entity')->fetchAll() as $r) {
    $entityDeviceCounts[$r['entity']] = (int)$r['cnt'];
}
$schoolDeviceCounts = [];
foreach ($pdo->query('SELECT entity, school, COUNT(*) AS cnt FROM devices GROUP BY entity, school')->fetchAll() as $r) {
    $schoolDeviceCounts[$r['entity'] . '|' . $r['school']] = (int)$r['cnt'];
}
render_layout_head($pdo, 'Locations - Links Directory Admin');
?>
<script>
window.onload = function() {
  var search = document.getElementById('entitySchoolSearch');
  var entityBlocks = document.querySelectorAll('.setup-entity-row');

  search.addEventListener('input', function() {
    var q = search.value.trim().toLowerCase();

    entityBlocks.forEach(function(block) {
      var entityMatches = !q || block.dataset.entity.indexOf(q) !== -1;
      var schoolRows = block.querySelectorAll('.setup-school-row');
      var anySchoolMatches = false;

      schoolRows.forEach(function(row) {
        if (!q || entityMatches) {
          row.style.display = '';
          if (!entityMatches) anySchoolMatches = true;
        } else {
          var schoolMatches = row.dataset.school.indexOf(q) !== -1;
          row.style.display = schoolMatches ? '' : 'none';
          if (schoolMatches) anySchoolMatches = true;
        }
      });

      block.style.display = (entityMatches || anySchoolMatches) ? '' : 'none';
    });
  });
};
</script>
<?php render_layout_body_open($pdo, 'Locations', 'locations'); ?>

  <?php if ($error): ?>
    <div class="notice error"><?= h($error) ?></div>
  <?php endif; ?>
  <?php if ($success): ?>
    <div class="notice success"><?= h($success) ?></div>
  <?php endif; ?>

  <div class="container">
    <h2>Add Entity</h2>
    <?php if ($myRole !== 'admin'): ?>
      <p style="color: var(--text-muted); font-size: 0.9rem;">Only admins can create new entities. You can still add and remove schools under the entities you have access to below.</p>
    <?php else: ?>
    <form method="post" action="/admin/setup.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_entity">
      <div class="form-group">
        <label for="entity_name">Entity Name</label>
        <input type="text" id="entity_name" name="entity_name" placeholder="e.g. ESU 17" required>
      </div>
      <button type="submit">Add Entity</button>
    </form>
    <?php endif; ?>
  </div>

  <div class="container">
    <h2>Add School</h2>
    <?php if (empty($addableEntities)): ?>
      <p style="color: var(--text-muted); font-size: 0.9rem;">
        <?= empty($entities) ? 'Add an entity first before adding a school to it.' : 'You do not have write access to any entity yet - ask an admin to assign you one under Users.' ?>
      </p>
    <?php else: ?>
      <form method="post" action="/admin/setup.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_school">
        <div class="form-group">
          <label for="entity_id">Entity</label>
          <select id="entity_id" name="entity_id" required>
            <option value="">Select Entity</option>
            <?php foreach ($addableEntities as $e): ?>
              <option value="<?= (int)$e['id'] ?>"><?= h($e['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label for="school_name">School Name</label>
          <input type="text" id="school_name" name="school_name" required>
        </div>
        <button type="submit">Add School</button>
      </form>
    <?php endif; ?>
  </div>

  <div class="container">
    <h2>Entities &amp; Schools</h2>
    <div class="form-group">
      <input type="text" id="entitySchoolSearch" placeholder="Search by entity or school name...">
    </div>
    <?php if (empty($entities)): ?>
      <p style="color: var(--text-muted); font-size: 0.9rem;">No entities yet.</p>
    <?php endif; ?>
    <?php foreach ($entities as $e): ?>
      <?php $canEditEntity = ($allowedEntities === null || in_array($e['name'], $allowedEntities, true)); ?>
      <div class="entity-block setup-entity-row" data-entity="<?= h(strtolower($e['name'])) ?>">
        <h3>
          <?= h($e['name']) ?>
          <span class="count-badge">(<?= $entityDeviceCounts[$e['name']] ?? 0 ?> device<?= (($entityDeviceCounts[$e['name']] ?? 0) === 1) ? '' : 's' ?>)</span>
          <?php if (!$canEditEntity): ?>
            <span class="count-badge">(view only)</span>
          <?php endif; ?>
          <?php if ($myRole === 'admin'): ?>
          <form method="post" action="/admin/setup.php" onsubmit="return confirm('Delete entity &quot;<?= h(addslashes($e['name'])) ?>&quot;? This also removes its schools from the setup list.');" style="display:inline; margin-left:8px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_entity">
            <input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
            <button type="submit" class="del-btn">Delete Entity</button>
          </form>
          <?php endif; ?>
        </h3>
        <?php $schools = $schoolsByEntityId[$e['id']] ?? []; ?>
        <?php if (empty($schools)): ?>
          <p style="color: var(--text-muted); font-size: 0.85rem; margin: 4px 0 0;">No schools yet.</p>
        <?php else: ?>
          <?php foreach ($schools as $s): ?>
            <?php $cnt = $schoolDeviceCounts[$e['name'] . '|' . $s['name']] ?? 0; ?>
            <div class="school-row setup-school-row" data-school="<?= h(strtolower($s['name'])) ?>">
              <span><?= h($s['name']) ?> <span class="count-badge">(<?= $cnt ?> device<?= $cnt === 1 ? '' : 's' ?>)</span></span>
              <?php if ($canEditEntity): ?>
                <form method="post" action="/admin/setup.php" onsubmit="return confirm('Delete school &quot;<?= h(addslashes($s['name'])) ?>&quot;?');" style="margin:0;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete_school">
                  <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                  <button type="submit" class="del-btn">Delete</button>
                </form>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
      <hr class="section-divider">
    <?php endforeach; ?>
  </div>

<?php render_layout_close(); ?>

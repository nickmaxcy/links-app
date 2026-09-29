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

const LOGO_DIR = __DIR__ . '/../assets/platform-logos';
const LOGO_MAX_BYTES = 2 * 1024 * 1024; // 2MB
const LOGO_ALLOWED_EXT = ['png', 'jpg', 'jpeg', 'gif', 'webp'];

// Turns the posted per-row fields (keyed by an explicit row index, listed
// in order in field_key_order[]) into a field_schema array.
//
// Critically, an existing field's storage key is preserved from
// field_existing_key[], not regenerated from its current label — a saved
// device's fields JSON is keyed by that value, so silently changing it on
// an unrelated edit (e.g. toggling a different field's checkbox) would
// orphan every device's existing data for that field. Only a field with
// no existing key (a genuinely new one) gets a key auto-generated from
// its label.
//
// Explicit indices (rather than bare field_label[] etc.) matter too:
// unchecked checkboxes are omitted entirely from a form submission, so
// without a stable index per row, checkbox arrays silently re-pack
// themselves in submission order and can attach the wrong flag to the
// wrong field. A hidden 0/1 fallback paired with each checkbox under the
// same explicit index closes that gap.
function build_field_schema_from_post(): array
{
    $order = $_POST['field_key_order'] ?? [];
    $labels = $_POST['field_label'] ?? [];
    $types = $_POST['field_type'] ?? [];
    $prefixes = $_POST['field_prefix'] ?? [];
    $required = $_POST['field_required'] ?? [];
    $hideViewer = $_POST['field_hide_viewer'] ?? [];
    $existingKeys = $_POST['field_existing_key'] ?? [];

    $schema = [];
    $usedKeys = [];

    foreach ($order as $idx) {
        $label = trim((string)($labels[$idx] ?? ''));
        if ($label === '') {
            continue;
        }
        $type = in_array($types[$idx] ?? '', array_keys(FIELD_TYPES), true) ? $types[$idx] : 'text';

        $existingKey = trim((string)($existingKeys[$idx] ?? ''));
        if ($existingKey !== '' && !in_array($existingKey, $usedKeys, true)) {
            $key = $existingKey;
        } else {
            $baseKey = $existingKey !== '' ? $existingKey : slugify_key($label);
            $key = $baseKey;
            $suffix = 2;
            while (in_array($key, $usedKeys, true)) {
                $key = $baseKey . '_' . $suffix;
                $suffix++;
            }
        }
        $usedKeys[] = $key;

        $field = [
            'key' => $key,
            'label' => $label,
            'type' => $type,
            'required' => ($required[$idx] ?? '0') === '1',
            'hide_from_viewer' => ($hideViewer[$idx] ?? '0') === '1',
        ];
        if ($type === 'url') {
            $prefix = trim((string)($prefixes[$idx] ?? ''));
            if ($prefix !== '') {
                $field['prefix'] = $prefix;
            }
        }
        $schema[] = $field;
    }
    return $schema;
}

// Generates a simple flat colored-badge SVG with centered text — the same
// style of placeholder logo used for the built-in Zoom/Teams/Meet/Polycom
// platforms, but admin-configurable instead of hand-made.
function generate_badge_svg(string $text, string $bgColor): string
{
    $fontSize = 22;
    $charWidth = $fontSize * 0.6;
    $textLen = max(1, mb_strlen($text));
    $width = max(120, (int)round($textLen * $charWidth) + 40);
    $height = 60;

    $hex = ltrim($bgColor, '#');
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
    $textColor = $luminance > 0.6 ? '#1f2937' : '#ffffff';

    $safeText = htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');

    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '" viewBox="0 0 ' . $width . ' ' . $height . '">'
        . '<rect x="0" y="0" width="' . $width . '" height="' . $height . '" rx="10" fill="' . $bgColor . '"/>'
        . '<text x="50%" y="50%" dominant-baseline="central" text-anchor="middle" '
        . 'font-family="-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif" '
        . 'font-size="' . $fontSize . '" font-weight="600" fill="' . $textColor . '">' . $safeText . '</text>'
        . '</svg>';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_platform') {
        $id = (int)($_POST['id'] ?? 0);
        $displayName = trim($_POST['display_name'] ?? '');
        $schema = build_field_schema_from_post();

        if ($displayName === '') {
            $error = 'Display name is required.';
        } elseif (empty($schema)) {
            $error = 'At least one field is required.';
        } else {
            $logoPath = null;
            $logoMode = $_POST['logo_mode'] ?? 'badge';

            if ($logoMode === 'upload') {
                // Handle logo upload, if one was provided.
                if (!empty($_FILES['logo']['name']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
                    if (!in_array($ext, LOGO_ALLOWED_EXT, true)) {
                        $error = 'Logo must be a PNG, JPG, GIF, or WEBP image.';
                    } elseif ($_FILES['logo']['size'] > LOGO_MAX_BYTES) {
                        $error = 'Logo must be under 2MB.';
                    } else {
                        $filename = slugify_key($displayName) . '_' . time() . '.' . $ext;
                        if (!is_dir(LOGO_DIR)) {
                            mkdir(LOGO_DIR, 0755, true);
                        }
                        if (move_uploaded_file($_FILES['logo']['tmp_name'], LOGO_DIR . '/' . $filename)) {
                            $logoPath = 'platform-logos/' . $filename;
                        } else {
                            $error = 'Could not save the uploaded logo.';
                        }
                    }
                }
            } elseif ($logoMode === 'badge') {
                $badgeColor = trim($_POST['badge_color'] ?? '#2d8cff');
                $badgeText = trim($_POST['badge_text'] ?? '') ?: $displayName;
                if (!preg_match('/^#[0-9a-fA-F]{6}$/', $badgeColor)) {
                    $badgeColor = '#2d8cff';
                }
                if (!is_dir(LOGO_DIR)) {
                    mkdir(LOGO_DIR, 0755, true);
                }
                $filename = slugify_key($displayName) . '_' . time() . '.svg';
                file_put_contents(LOGO_DIR . '/' . $filename, generate_badge_svg($badgeText, $badgeColor));
                $logoPath = 'platform-logos/' . $filename;
            }
            // logo_mode === 'keep': $logoPath stays null, existing logo_path column is left untouched below.

            if (!$error) {
                if ($id > 0) {
                    // Editing an existing platform.
                    if ($logoPath) {
                        $stmt = $pdo->prepare('UPDATE platforms SET display_name = :name, field_schema = :schema, logo_path = :logo WHERE id = :id');
                        $stmt->execute([':name' => $displayName, ':schema' => json_encode($schema), ':logo' => $logoPath, ':id' => $id]);
                    } else {
                        $stmt = $pdo->prepare('UPDATE platforms SET display_name = :name, field_schema = :schema WHERE id = :id');
                        $stmt->execute([':name' => $displayName, ':schema' => json_encode($schema), ':id' => $id]);
                    }
                    log_action($pdo, 'update_platform', "Updated platform '{$displayName}'.");
                    header('Location: /admin/platforms.php?updated=1');
                    exit;
                } else {
                    // New platform.
                    $key = slugify_key($displayName);
                    $baseKey = $key;
                    $suffix = 2;
                    while (true) {
                        $check = $pdo->prepare('SELECT COUNT(*) FROM platforms WHERE platform_key = :key');
                        $check->execute([':key' => $key]);
                        if ((int)$check->fetchColumn() === 0) {
                            break;
                        }
                        $key = $baseKey . '_' . $suffix;
                        $suffix++;
                    }
                    $maxOrder = (int)$pdo->query('SELECT COALESCE(MAX(sort_order), 0) FROM platforms')->fetchColumn();
                    $stmt = $pdo->prepare(
                        'INSERT INTO platforms (platform_key, display_name, logo_path, field_schema, sort_order) VALUES (:key, :name, :logo, :schema, :sort)'
                    );
                    $stmt->execute([
                        ':key' => $key,
                        ':name' => $displayName,
                        ':logo' => $logoPath,
                        ':schema' => json_encode($schema),
                        ':sort' => $maxOrder + 1,
                    ]);
                    log_action($pdo, 'add_platform', "Added platform '{$displayName}'.");
                    header('Location: /admin/platforms.php?added=1');
                    exit;
                }
            }
        }
    } elseif ($action === 'delete_platform') {
        $id = (int)($_POST['id'] ?? 0);
        $lookup = $pdo->prepare('SELECT display_name FROM platforms WHERE id = :id');
        $lookup->execute([':id' => $id]);
        $name = $lookup->fetchColumn();

        if ($name === false) {
            $error = 'Platform not found.';
        } else {
            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM devices WHERE platform_id = :id');
            $countStmt->execute([':id' => $id]);
            $deviceCount = (int)$countStmt->fetchColumn();

            if ($deviceCount > 0) {
                $error = "Cannot delete \"{$name}\": {$deviceCount} device(s) still use this platform. Reassign or remove them first.";
            } else {
                $pdo->prepare('DELETE FROM platforms WHERE id = :id')->execute([':id' => $id]);
                log_action($pdo, 'delete_platform', "Deleted platform '{$name}'.");
                header('Location: /admin/platforms.php?deleted=1');
                exit;
            }
        }
    }
}

if (isset($_GET['added'])) { $success = 'Platform added.'; }
if (isset($_GET['updated'])) { $success = 'Platform updated.'; }
if (isset($_GET['deleted'])) { $success = 'Platform deleted.'; }

$editing = null;
if (isset($_GET['id'])) {
    $editing = get_platform($pdo, (int)$_GET['id']);
    if (!$editing) {
        $error = 'Platform not found.';
    }
}

$platforms = get_platforms($pdo);

$deviceCounts = [];
foreach ($pdo->query('SELECT platform_id, COUNT(*) AS cnt FROM devices GROUP BY platform_id')->fetchAll() as $r) {
    $deviceCounts[(int)$r['platform_id']] = (int)$r['cnt'];
}
?>
<?php render_layout_head($pdo, 'Platforms - Links Directory Admin'); ?>
<script>
window.onload = function() {
  var platformSearch = document.getElementById('platformSearch');
  var platformRows = document.querySelectorAll('.platform-row');
  platformSearch.addEventListener('input', function() {
    var q = platformSearch.value.trim().toLowerCase();
    platformRows.forEach(function(row) {
      row.style.display = (!q || row.dataset.name.indexOf(q) !== -1) ? '' : 'none';
    });
  });


  var logoRadios = document.querySelectorAll('input[name="logo_mode"]');
  var uploadFields = document.getElementById('logoUploadFields');
  var badgeFields = document.getElementById('logoBadgeFields');

  function updateLogoFieldsVisibility() {
    var selected = document.querySelector('input[name="logo_mode"]:checked');
    var mode = selected ? selected.value : 'badge';
    uploadFields.style.display = mode === 'upload' ? 'block' : 'none';
    badgeFields.style.display = mode === 'badge' ? 'block' : 'none';
  }
  logoRadios.forEach(function(r) { r.addEventListener('change', updateLogoFieldsVisibility); });
  updateLogoFieldsVisibility();

  var container = document.getElementById('fieldRows');
  var template = document.getElementById('fieldRowTemplate');

  function wireRow(row) {
    var typeSelect = row.querySelector('.field-type');
    var prefixWrap = row.querySelector('.field-prefix-wrap');
    var removeBtn = row.querySelector('.remove-field-row');

    function updatePrefixVisibility() {
      prefixWrap.style.display = typeSelect.value === 'url' ? 'block' : 'none';
    }
    typeSelect.addEventListener('change', updatePrefixVisibility);
    updatePrefixVisibility();

    removeBtn.addEventListener('click', function(e) {
      e.preventDefault();
      if (container.querySelectorAll('.field-row').length > 1) {
        row.remove();
      }
    });
  }

  document.querySelectorAll('.field-row').forEach(wireRow);

  var nextFieldIndex = document.querySelectorAll('.field-row').length;

  document.getElementById('addFieldRow').addEventListener('click', function(e) {
    e.preventDefault();
    var clone = template.content.cloneNode(true);
    var idx = nextFieldIndex++;
    clone.querySelectorAll('[name*="__INDEX__"]').forEach(function(el) {
      el.setAttribute('name', el.getAttribute('name').replace('__INDEX__', idx));
    });
    clone.querySelectorAll('[value="__INDEX__"]').forEach(function(el) {
      el.setAttribute('value', idx);
    });
    container.appendChild(clone);
    wireRow(container.lastElementChild);
  });
};
</script>
<?php render_layout_body_open($pdo, 'Platforms', 'platforms'); ?>

  <?php if ($error): ?>
    <div class="notice error"><?= h($error) ?></div>
  <?php endif; ?>
  <?php if ($success): ?>
    <div class="notice success"><?= h($success) ?></div>
  <?php endif; ?>

  <div class="container">
    <h2><?= $editing ? 'Edit Platform' : 'Add Platform' ?></h2>
    <form method="post" action="/admin/platforms.php" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_platform">
      <?php if ($editing): ?>
        <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
      <?php endif; ?>

      <div class="form-group">
        <label for="display_name">Display Name</label>
        <input type="text" id="display_name" name="display_name" value="<?= $editing ? h($editing['display_name']) : '' ?>" placeholder="e.g. Cisco Webex" required>
      </div>

      <div class="form-group">
        <label>Logo</label>
        <?php if ($editing && $editing['logo_path']): ?>
          <div style="margin-bottom:8px;"><img src="/assets/<?= h($editing['logo_path']) ?>" style="height:32px; border-radius:4px; border:1px solid var(--border-color);"></div>
        <?php endif; ?>
        <div class="type-toggle">
          <?php if ($editing && $editing['logo_path']): ?>
            <label>
              <input type="radio" name="logo_mode" value="keep" checked>
              <span>Keep Current</span>
            </label>
          <?php endif; ?>
          <label>
            <input type="radio" name="logo_mode" value="upload" <?= (!$editing || !$editing['logo_path']) ? '' : '' ?>>
            <span>Upload Image</span>
          </label>
          <label>
            <input type="radio" name="logo_mode" value="badge" <?= (!$editing || !$editing['logo_path']) ? 'checked' : '' ?>>
            <span>Color + Text Badge</span>
          </label>
        </div>

        <div id="logoUploadFields" style="display:none; margin-top:10px;">
          <input type="file" id="logo" name="logo" accept=".png,.jpg,.jpeg,.gif,.webp">
        </div>

        <div id="logoBadgeFields" style="display:none; margin-top:10px;">
          <div class="filter-row" style="margin-bottom:0;">
            <div class="form-group">
              <label for="badge_color">Badge Color</label>
              <input type="color" id="badge_color" name="badge_color" value="#2d8cff" style="height:42px; padding:4px;">
            </div>
            <div class="form-group">
              <label for="badge_text">Badge Text</label>
              <input type="text" id="badge_text" name="badge_text" value="<?= $editing ? h($editing['display_name']) : '' ?>" placeholder="e.g. Webex" maxlength="20">
            </div>
          </div>
        </div>
      </div>

      <label style="font-weight:600; font-size:0.9rem; display:block; margin-top:18px; margin-bottom:8px;">Fields</label>
      <div id="fieldRows">
        <?php
        $existingFields = $editing['field_schema'] ?? [['key' => '', 'label' => '', 'type' => 'text', 'required' => false]];
        if (empty($existingFields)) {
            $existingFields = [['key' => '', 'label' => '', 'type' => 'text', 'required' => false]];
        }
        foreach ($existingFields as $i => $f):
        ?>
          <div class="field-row" style="border:1px solid var(--border-color); border-radius:8px; padding:12px; margin-bottom:10px;">
            <input type="hidden" name="field_key_order[]" value="<?= (int)$i ?>">
            <input type="hidden" name="field_existing_key[<?= (int)$i ?>]" value="<?= h($f['key'] ?? '') ?>">
            <div class="filter-row" style="margin-bottom:0;">
              <div class="form-group">
                <label>Field Label</label>
                <input type="text" name="field_label[<?= (int)$i ?>]" value="<?= h($f['label'] ?? '') ?>" placeholder="e.g. Meeting Link">
              </div>
              <div class="form-group">
                <label>Type</label>
                <select name="field_type[<?= (int)$i ?>]" class="field-type">
                  <?php foreach (FIELD_TYPES as $val => $label): ?>
                    <option value="<?= h($val) ?>" <?= ($f['type'] ?? 'text') === $val ? 'selected' : '' ?>><?= h($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group" style="flex:0 0 auto; align-self:flex-end;">
                <label style="visibility:hidden;">Required</label>
                <label style="font-weight:normal; display:flex; align-items:center; gap:6px; padding:10px 0;">
                  <input type="hidden" name="field_required[<?= (int)$i ?>]" value="0">
                  <input type="checkbox" name="field_required[<?= (int)$i ?>]" value="1" <?= !empty($f['required']) ? 'checked' : '' ?> style="width:auto;"> Required
                </label>
                <label style="font-weight:normal; display:flex; align-items:center; gap:6px; padding:2px 0;">
                  <input type="hidden" name="field_hide_viewer[<?= (int)$i ?>]" value="0">
                  <input type="checkbox" name="field_hide_viewer[<?= (int)$i ?>]" value="1" <?= !empty($f['hide_from_viewer']) ? 'checked' : '' ?> style="width:auto;"> Hide from Viewer
                </label>
              </div>
            </div>
            <div class="form-group field-prefix-wrap" style="display:none; margin-top:10px;">
              <label>URL Prefix (optional — shown before the input, auto-prepended on save)</label>
              <input type="text" name="field_prefix[<?= (int)$i ?>]" value="<?= h($f['prefix'] ?? '') ?>" placeholder="e.g. https://meet.example.com/">
            </div>
            <button type="button" class="remove-field-row del-btn" style="margin-top:8px;">Remove Field</button>
          </div>
        <?php endforeach; ?>
      </div>
      <button type="button" id="addFieldRow" class="btn" style="background:#94a3b8; margin-bottom:16px;">+ Add Field</button>
      <br>

      <template id="fieldRowTemplate">
        <div class="field-row" style="border:1px solid var(--border-color); border-radius:8px; padding:12px; margin-bottom:10px;">
          <input type="hidden" name="field_key_order[]" value="__INDEX__" class="row-index-input">
          <input type="hidden" name="field_existing_key[__INDEX__]" value="">
          <div class="filter-row" style="margin-bottom:0;">
            <div class="form-group">
              <label>Field Label</label>
              <input type="text" name="field_label[__INDEX__]" placeholder="e.g. Meeting Link" class="row-label-input">
            </div>
            <div class="form-group">
              <label>Type</label>
              <select name="field_type[__INDEX__]" class="field-type row-type-input">
                <?php foreach (FIELD_TYPES as $val => $label): ?>
                  <option value="<?= h($val) ?>"><?= h($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group" style="flex:0 0 auto; align-self:flex-end;">
              <label style="visibility:hidden;">Required</label>
              <label style="font-weight:normal; display:flex; align-items:center; gap:6px; padding:10px 0;">
                <input type="hidden" name="field_required[__INDEX__]" value="0" class="row-required-hidden">
                <input type="checkbox" name="field_required[__INDEX__]" value="1" style="width:auto;" class="row-required-checkbox"> Required
              </label>
              <label style="font-weight:normal; display:flex; align-items:center; gap:6px; padding:2px 0;">
                <input type="hidden" name="field_hide_viewer[__INDEX__]" value="0" class="row-hide-hidden">
                <input type="checkbox" name="field_hide_viewer[__INDEX__]" value="1" style="width:auto;" class="row-hide-checkbox"> Hide from Viewer
              </label>
            </div>
          </div>
          <div class="form-group field-prefix-wrap" style="display:none; margin-top:10px;">
            <label>URL Prefix (optional — shown before the input, auto-prepended on save)</label>
            <input type="text" name="field_prefix[__INDEX__]" placeholder="e.g. https://meet.example.com/" class="row-prefix-input">
          </div>
          <button type="button" class="remove-field-row del-btn" style="margin-top:8px;">Remove Field</button>
        </div>
      </template>

      <button type="submit"><?= $editing ? 'Save Changes' : 'Add Platform' ?></button>
      <?php if ($editing): ?>
        <a href="/admin/platforms.php" class="btn" style="background:#94a3b8; margin-left:8px;">Cancel / New Platform</a>
      <?php endif; ?>
    </form>
  </div>

  <div class="container">
    <h2>Existing Platforms</h2>
    <div class="form-group">
      <input type="text" id="platformSearch" placeholder="Search platforms by name...">
    </div>
    <?php foreach ($platforms as $p): ?>
      <div class="entity-block platform-row" data-name="<?= h(strtolower($p['display_name'])) ?>">
        <h3 style="display:flex; align-items:center; gap:10px;">
          <?php if ($p['logo_path']): ?>
            <img src="/assets/<?= h($p['logo_path']) ?>" style="height:24px; border-radius:4px; border:1px solid var(--border-color);">
          <?php endif; ?>
          <?= h($p['display_name']) ?>
          <span class="count-badge">(<?= $deviceCounts[$p['id']] ?? 0 ?> device<?= (($deviceCounts[$p['id']] ?? 0) === 1) ? '' : 's' ?>, <?= count($p['field_schema']) ?> field<?= count($p['field_schema']) === 1 ? '' : 's' ?>)</span>
        </h3>
        <p style="color: var(--text-muted); font-size: 0.82rem; margin: 0 0 8px;">
          <?php foreach ($p['field_schema'] as $i => $f): ?>
            <?= $i > 0 ? ', ' : '' ?><?= h($f['label']) ?><?= !empty($f['hide_from_viewer']) ? ' (hidden from viewer)' : '' ?>
          <?php endforeach; ?>
        </p>
        <a class="edit-link" href="/admin/platforms.php?id=<?= (int)$p['id'] ?>">Edit</a>
        <form method="post" action="/admin/platforms.php" onsubmit="return confirm('Delete platform &quot;<?= h(addslashes($p['display_name'])) ?>&quot;?');" style="display:inline;">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete_platform">
          <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
          <button type="submit" class="del-btn">Delete</button>
        </form>
      </div>
      <hr class="section-divider">
    <?php endforeach; ?>
  </div>

<?php render_layout_close(); ?>

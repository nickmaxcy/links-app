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

const BRANDING_DIR = __DIR__ . '/../assets/branding';
const IMAGE_MAX_BYTES = 2 * 1024 * 1024; // 2MB
const LOGO_ALLOWED_EXT = ['png', 'jpg', 'jpeg', 'gif', 'webp'];
const FAVICON_ALLOWED_EXT = ['ico', 'png'];

function handle_upload(string $fieldName, array $allowedExt, string $prefix): ?string
{
    if (empty($_FILES[$fieldName]['name']) || $_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    $ext = strtolower(pathinfo($_FILES[$fieldName]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        throw new RuntimeException(strtoupper(implode('/', $allowedExt)) . ' file required.');
    }
    if ($_FILES[$fieldName]['size'] > IMAGE_MAX_BYTES) {
        throw new RuntimeException('File must be under 2MB.');
    }
    if (!is_dir(BRANDING_DIR)) {
        mkdir(BRANDING_DIR, 0755, true);
    }
    $filename = $prefix . '_' . time() . '.' . $ext;
    if (!move_uploaded_file($_FILES[$fieldName]['tmp_name'], BRANDING_DIR . '/' . $filename)) {
        throw new RuntimeException('Could not save the uploaded file.');
    }
    return 'assets/branding/' . $filename;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $primary = trim($_POST['theme_primary_color'] ?? '#015eea');
    $accent = trim($_POST['theme_accent_color'] ?? '#2d8cff');
    $fontKey = $_POST['theme_font_family'] ?? 'system';
    $darkMode = $_POST['theme_dark_mode'] ?? 'toggle';

    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $primary)) {
        $error = 'Primary color must be a valid hex color (e.g. #015eea).';
    } elseif (!preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
        $error = 'Accent color must be a valid hex color (e.g. #2d8cff).';
    } elseif (!array_key_exists($fontKey, FONT_STACKS)) {
        $error = 'Invalid font selection.';
    } elseif (!in_array($darkMode, ['light', 'dark', 'auto', 'toggle'], true)) {
        $error = 'Invalid dark mode selection.';
    } else {
        try {
            $logoPath = handle_upload('logo', LOGO_ALLOWED_EXT, 'logo');
            $faviconPath = handle_upload('favicon', FAVICON_ALLOWED_EXT, 'favicon');

            set_setting($pdo, 'theme_primary_color', $primary);
            set_setting($pdo, 'theme_accent_color', $accent);
            set_setting($pdo, 'theme_font_family', $fontKey);
            set_setting($pdo, 'theme_dark_mode', $darkMode);
            if ($logoPath) {
                set_setting($pdo, 'site_logo_path', $logoPath);
            }
            if ($faviconPath) {
                set_setting($pdo, 'site_favicon_path', $faviconPath);
            }

            log_action($pdo, 'update_appearance', 'Updated site appearance settings.');
            header('Location: /admin/appearance.php?updated=1');
            exit;
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
    }
}

if (isset($_GET['updated'])) {
    $success = 'Appearance updated.';
}

$currentPrimary = get_setting($pdo, 'theme_primary_color', '#015eea');
$currentAccent = get_setting($pdo, 'theme_accent_color', '#2d8cff');
$currentFont = get_setting($pdo, 'theme_font_family', 'system');
$currentDarkMode = get_dark_mode_setting($pdo);
$currentLogo = get_site_logo_path($pdo);
$currentFavicon = get_site_favicon_path($pdo);
?>
<?php render_layout_head($pdo, 'Appearance - Links Directory Admin'); ?>
<?php render_layout_body_open($pdo, 'Appearance', 'appearance'); ?>

  <?php if ($error): ?>
    <div class="notice error"><?= h($error) ?></div>
  <?php endif; ?>
  <?php if ($success): ?>
    <div class="notice success"><?= h($success) ?></div>
  <?php endif; ?>

  <div class="container">
    <h2>Appearance</h2>
    <form method="post" action="/admin/appearance.php" enctype="multipart/form-data">
      <?= csrf_field() ?>

      <div class="filter-row">
        <div class="form-group">
          <label for="theme_primary_color">Primary Color</label>
          <input type="color" id="theme_primary_color" name="theme_primary_color" value="<?= h($currentPrimary) ?>" style="height:42px; padding:4px;">
        </div>
        <div class="form-group">
          <label for="theme_accent_color">Accent Color</label>
          <input type="color" id="theme_accent_color" name="theme_accent_color" value="<?= h($currentAccent) ?>" style="height:42px; padding:4px;">
        </div>
      </div>
      <p style="color: var(--text-muted); font-size: 0.82rem; margin-top: -8px;">
        Primary is the page background; Accent is used for links and highlighted badges.
        Both stay the same in light and dark mode — only surfaces, text, and borders adapt.
      </p>

      <div class="form-group">
        <label for="theme_font_family">Font</label>
        <select id="theme_font_family" name="theme_font_family">
          <?php foreach (FONT_LABELS as $key => $label): ?>
            <option value="<?= h($key) ?>" <?= $key === $currentFont ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label for="theme_dark_mode">Dark Mode</label>
        <select id="theme_dark_mode" name="theme_dark_mode">
          <option value="light" <?= $currentDarkMode === 'light' ? 'selected' : '' ?>>Light Only</option>
          <option value="dark" <?= $currentDarkMode === 'dark' ? 'selected' : '' ?>>Dark Only</option>
          <option value="auto" <?= $currentDarkMode === 'auto' ? 'selected' : '' ?>>Auto (follow visitor's system)</option>
          <option value="toggle" <?= $currentDarkMode === 'toggle' ? 'selected' : '' ?>>Visitor Toggle (button shown on every page)</option>
        </select>
      </div>

      <div class="form-group">
        <label for="logo">Logo (leave blank to keep current)</label>
        <input type="file" id="logo" name="logo" accept=".png,.jpg,.jpeg,.gif,.webp">
        <div style="margin-top:8px;"><img src="/<?= h($currentLogo) ?>" style="max-height:60px; border:1px solid var(--border-color); border-radius:6px; padding:4px; background:#fff;"></div>
      </div>

      <div class="form-group">
        <label for="favicon">Favicon (leave blank to keep current)</label>
        <input type="file" id="favicon" name="favicon" accept=".ico,.png">
        <div style="margin-top:8px;"><img src="/<?= h($currentFavicon) ?>" style="height:32px; border:1px solid var(--border-color); border-radius:4px; padding:4px; background:#fff;"></div>
      </div>

      <button type="submit">Save Appearance</button>
    </form>
  </div>

<?php render_layout_close(); ?>

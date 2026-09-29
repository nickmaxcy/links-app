<?php
declare(strict_types=1);

// Block this file from being requested directly over HTTP.
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(403);
    exit('Direct access not permitted.');
}

const FONT_STACKS = [
    'system' => '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif',
    'humanist' => '"Segoe UI", Calibri, Candara, Arial, sans-serif',
    'serif' => 'Georgia, "Times New Roman", Times, serif',
    'mono' => '"Courier New", Courier, monospace',
    'rounded' => 'Verdana, Tahoma, Geneva, sans-serif',
];

const FONT_LABELS = [
    'system' => 'System Default',
    'humanist' => 'Humanist Sans',
    'serif' => 'Serif',
    'mono' => 'Monospace',
    'rounded' => 'Rounded Sans',
];

function get_site_logo_path(PDO $pdo): string
{
    return get_setting($pdo, 'site_logo_path', 'logo.png');
}

function get_site_favicon_path(PDO $pdo): string
{
    return get_setting($pdo, 'site_favicon_path', 'favicon.ico');
}

// Appends the file's last-modified time as a query string, so browsers
// fetch a fresh copy immediately after any deploy instead of serving a
// cached (possibly outdated) version until their cache naturally expires.
function asset_version_url(string $relativePath): string
{
    $relativePath = ltrim($relativePath, '/');
    $fsPath = __DIR__ . '/../' . $relativePath;
    $mtime = @filemtime($fsPath);
    return '/' . $relativePath . ($mtime ? '?v=' . $mtime : '');
}

function get_dark_mode_setting(PDO $pdo): string
{
    $mode = get_setting($pdo, 'theme_dark_mode', 'toggle');
    return in_array($mode, ['light', 'dark', 'auto', 'toggle'], true) ? $mode : 'toggle';
}

// Echoed inside <html ...>. Forces a specific theme when the admin picked
// Light Only or Dark Only; left empty for auto/toggle, where CSS media
// queries and a small localStorage-backed script (see below) decide instead.
function render_html_data_theme_attr(PDO $pdo): string
{
    $mode = get_dark_mode_setting($pdo);
    if ($mode === 'light') return ' data-theme="light"';
    if ($mode === 'dark') return ' data-theme="dark"';
    return '';
}

// Echoed in <head>, after the static stylesheet link, so these override it.
// Colors and font are validated against a strict allow-list/regex before
// ever reaching a <style> block, since this is admin-supplied input being
// injected directly into CSS.
function render_theme_style_block(PDO $pdo): string
{
    $primary = get_setting($pdo, 'theme_primary_color', '#015eea');
    $accent = get_setting($pdo, 'theme_accent_color', '#2d8cff');
    $fontKey = get_setting($pdo, 'theme_font_family', 'system');

    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $primary)) {
        $primary = '#015eea';
    }
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
        $accent = '#2d8cff';
    }
    $fontStack = FONT_STACKS[$fontKey] ?? FONT_STACKS['system'];

    $css = ":root { --primary-blue: {$primary}; --accent-blue: {$accent}; }\n";
    $css .= "body { font-family: {$fontStack}; }\n";

    return "<style>{$css}</style>";
}

// Echoed right after <body>. Only renders a visible toggle (and its script)
// when the admin picked "Visitor Toggle"; for auto/light/dark this returns
// nothing, since those modes need no user-facing control.
function render_theme_toggle(PDO $pdo): string
{
    if (get_dark_mode_setting($pdo) !== 'toggle') {
        return '';
    }
    return <<<HTML
<button id="themeToggleBtn" type="button" aria-label="Toggle dark mode" title="Toggle dark mode"
  style="position:fixed;top:12px;right:12px;z-index:1000;background:var(--card-bg);color:var(--text-dark);border:1px solid var(--border-color);border-radius:50%;width:40px;height:40px;cursor:pointer;font-size:18px;box-shadow:0 2px 6px rgba(0,0,0,0.15);">&#9788;</button>
<script>
(function() {
  var btn = document.getElementById('themeToggleBtn');

  function isDarkNow() {
    var attr = document.documentElement.getAttribute('data-theme');
    if (attr === 'dark') return true;
    if (attr === 'light') return false;
    return window.matchMedia('(prefers-color-scheme: dark)').matches;
  }
  // Sun icon (tap to go light) while dark; moon icon (tap to go dark) while light.
  function updateIcon() {
    btn.innerHTML = isDarkNow() ? '&#9788;' : '&#9789;';
  }

  var stored = localStorage.getItem('theme');
  if (stored === 'light' || stored === 'dark') {
    document.documentElement.setAttribute('data-theme', stored);
  }
  updateIcon();

  btn.addEventListener('click', function() {
    var next = isDarkNow() ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    try { localStorage.setItem('theme', next); } catch (e) {}
    updateIcon();
  });
})();
</script>
HTML;
}

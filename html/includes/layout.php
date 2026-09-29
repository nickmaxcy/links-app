<?php
declare(strict_types=1);

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(403);
    exit('Direct access not permitted.');
}

function nav_icon(string $name): string
{
    $icons = [
        'search' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="10" cy="10" r="6"/><line x1="20" y1="20" x2="14.5" y2="14.5"/></svg>',
        'devices' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="12" rx="2"/><line x1="8" y1="20" x2="16" y2="20"/><line x1="12" y1="16" x2="12" y2="20"/></svg>',
        'grid' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="8" height="8" rx="1.5"/><rect x="13" y="3" width="8" height="8" rx="1.5"/><rect x="3" y="13" width="8" height="8" rx="1.5"/><rect x="13" y="13" width="8" height="8" rx="1.5"/></svg>',
        'settings' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="3.2"/><line x1="12" y1="2" x2="12" y2="5"/><line x1="12" y1="19" x2="12" y2="22"/><line x1="2" y1="12" x2="5" y2="12"/><line x1="19" y1="12" x2="22" y2="12"/><line x1="4.9" y1="4.9" x2="7" y2="7"/><line x1="17" y1="17" x2="19.1" y2="19.1"/><line x1="19.1" y1="4.9" x2="17" y2="7"/><line x1="7" y1="17" x2="4.9" y2="19.1"/></svg>',
        'palette' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3a9 9 0 1 0 0 18c1.1 0 2-.9 2-2 0-.5-.2-1-.5-1.3-.3-.4-.5-.8-.5-1.2 0-1.1.9-2 2-2h2c2.2 0 4-1.8 4-4 0-4.4-4-7.5-9-7.5z"/><circle cx="7.5" cy="10.5" r="1"/><circle cx="12" cy="7.5" r="1"/><circle cx="16.5" cy="10.5" r="1"/></svg>',
        'users' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.2"/><path d="M3.5 20c0-3.3 2.5-6 5.5-6s5.5 2.7 5.5 6"/><circle cx="17" cy="9" r="2.6"/><path d="M15.5 13.2c2.6.3 4.5 2.6 4.5 5.8"/></svg>',
        'history' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><polyline points="12,7 12,12 16,14"/></svg>',
        'logout' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h3"/><polyline points="15,17 20,12 15,7"/><line x1="20" y1="12" x2="9" y2="12"/></svg>',
        'key' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="15" r="4"/><path d="M11 12l9-9"/><path d="M16 7l3 3"/><path d="M13 10l2 2"/></svg>',
        'upload' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 16v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/><polyline points="7,9 12,4 17,9"/><line x1="12" y1="4" x2="12" y2="15"/></svg>',
        'shield' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6l7-3z"/><polyline points="9,12 11,14 15,10"/></svg>',
        'map-pin' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7-6.5-7-11.5A7 7 0 0 1 19 9.5C19 14.5 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.3"/></svg>',
        'lock' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="9" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>',
        'clock' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><polyline points="12,7 12,12 15.5,14"/></svg>',
        'folder' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a1 1 0 0 1 1-1h4l2 2h9a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V7z"/></svg>',
        'chevron-left' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15,6 9,12 15,18"/></svg>',
        'list' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>',
        'menu' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="4" y1="7" x2="20" y2="7"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="17" x2="20" y2="17"/></svg>',
        'close' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="6" y1="6" x2="18" y2="18"/><line x1="6" y1="18" x2="18" y2="6"/></svg>',
    ];
    return $icons[$name] ?? '';
}

// Opens the document (doctype through the common <head> tags) but leaves
// <head> open, so the calling page can still echo its own page-specific
// <script> block before calling render_layout_body_open().
function render_layout_head(PDO $pdo, string $title): void
{
    $dataTheme = render_html_data_theme_attr($pdo);
    $favicon = h(get_site_favicon_path($pdo));
    $styleUrl = asset_version_url('assets/style.css');
    echo "<!doctype html>\n<html{$dataTheme}>\n<head>\n";
    echo '<meta charset="UTF-8" name="viewport" content="width=device-width, initial-scale=1">' . "\n";
    echo "<title>{$title}</title>\n";
    echo "<link rel=\"icon\" type=\"image/x-icon\" href=\"/{$favicon}\">\n";
    echo "<link rel=\"stylesheet\" href=\"{$styleUrl}\">\n";
    echo render_theme_style_block($pdo) . "\n";
}

// Closes <head>, opens <body>, and renders the sidebar + mobile topbar.
// $activeKey is one of: lookup, devices, security, locations, appearance,
// users, audit, import, sso — used to highlight the current item and is
// omitted from the sidebar entirely if the current user's role doesn't
// include it.
function render_layout_body_open(PDO $pdo, string $pageLabel, string $activeKey): void
{
    echo "</head>\n<body>\n";
    echo render_theme_toggle($pdo);

    $role = current_role();
    $logo = h(get_site_logo_path($pdo));
    $collapsedClass = (($_COOKIE['sidebar_collapsed'] ?? '0') === '1') ? ' sidebar-collapsed' : '';

    $topItems = [['lookup', '/', 'search', 'Lookup']];
    if (in_array($role, ['editor', 'admin'], true)) {
        $topItems[] = ['devices', '/admin/devices.php', 'devices', 'Devices'];
        $topItems[] = ['locations', '/admin/setup.php', 'map-pin', 'Locations'];
        $topItems[] = ['platforms', '/admin/platforms.php', 'grid', 'Platforms'];
    }

    $adminItems = [];
    if ($role === 'admin') {
        $adminItems[] = ['appearance', '/admin/appearance.php', 'palette', 'Appearance'];
        $adminItems[] = ['audit', '/admin/audit.php', 'history', 'Audit Log'];
        $adminItems[] = ['import', '/admin/import.php', 'upload', 'Import'];
        $adminItems[] = ['sso', '/admin/sso.php', 'shield', 'Single Sign-On'];
        $adminItems[] = ['users', '/admin/users.php', 'users', 'Users'];
    }

    // Session countdown: computed once server-side from this session's own
    // idle-timeout window, then ticked down client-side every 30 seconds.
    $idleTimeout = $_SESSION['idle_timeout_seconds'] ?? SESSION_IDLE_TIMEOUT_SECONDS;
    $lastActivity = $_SESSION['last_activity'] ?? time();
    $sessionExpiresAt = $lastActivity + $idleTimeout;

    echo "<div class=\"app-shell{$collapsedClass}\">";
    echo '<aside class="sidebar" id="appSidebar">';
    echo '<div class="sidebar-header">';
    echo "<img src=\"/{$logo}\" alt=\"Site Logo\" class=\"sidebar-logo\">";
    echo '<button type="button" id="collapseBtn" class="icon-btn" aria-label="Collapse sidebar">' . nav_icon('chevron-left') . '</button>';
    echo '<button type="button" id="closeDrawerBtn" class="icon-btn drawer-close-btn" aria-label="Close menu">' . nav_icon('close') . '</button>';
    echo '</div>';
    echo '<nav class="sidebar-nav">';
    foreach ($topItems as [$key, $href, $icon, $label]) {
        $activeClass = $key === $activeKey ? ' active' : '';
        echo "<a href=\"{$href}\" class=\"nav-item{$activeClass}\">" . nav_icon($icon) . '<span class="nav-label">' . h($label) . '</span></a>';
    }
    echo '<a href="/security.php" class="nav-item' . ($activeKey === 'security' ? ' active' : '') . '">' . nav_icon('lock') . '<span class="nav-label">Security</span></a>';

    if (!empty($adminItems)) {
        echo '<div class="nav-section-label"><span class="nav-label">Admin</span></div>';
        foreach ($adminItems as [$key, $href, $icon, $label]) {
            $activeClass = $key === $activeKey ? ' active' : '';
            echo "<a href=\"{$href}\" class=\"nav-item nav-item-sub{$activeClass}\">" . nav_icon($icon) . '<span class="nav-label">' . h($label) . '</span></a>';
        }
    }

    echo '<div class="nav-spacer"></div>';
    echo '<div class="session-timer nav-label" id="sessionTimer" data-expires="' . (int)$sessionExpiresAt . '">Session: -- min</div>';
    $isSsoUser = false;
    $idCheck = current_admin_id();
    if ($idCheck) {
        $ssoCheckStmt = $pdo->prepare('SELECT sso_connection_id FROM admin_users WHERE id = :id');
        $ssoCheckStmt->execute([':id' => $idCheck]);
        $isSsoUser = !empty($ssoCheckStmt->fetchColumn());
    }
    if (!$isSsoUser) {
        echo '<a href="/change-password.php" class="nav-item' . ($activeKey === 'change-password' ? ' active' : '') . '">' . nav_icon('key') . '<span class="nav-label">Change Password</span></a>';
    }
    echo '<a href="/logout.php" class="nav-item">' . nav_icon('logout') . '<span class="nav-label">Log Out</span></a>';
    echo '</nav>';
    echo '</aside>';
    echo '<div class="app-scrim" id="appScrim"></div>';
    echo '<div class="main-area">';
    echo '<div class="topbar">';
    echo '<button type="button" id="hamburgerBtn" class="icon-btn" aria-label="Open menu">' . nav_icon('menu') . '</button>';
    echo '<span class="topbar-title">' . h($pageLabel) . '</span>';
    echo '</div>';
    echo '<main class="main-content">';
}

function render_layout_close(): void
{
    echo '</main></div></div>';
    echo <<<HTML
<script>
(function() {
  var shell = document.querySelector('.app-shell');
  var collapseBtn = document.getElementById('collapseBtn');
  var hamburgerBtn = document.getElementById('hamburgerBtn');
  var closeDrawerBtn = document.getElementById('closeDrawerBtn');
  var scrim = document.getElementById('appScrim');
  if (!shell) return;

  if (collapseBtn) {
    collapseBtn.addEventListener('click', function() {
      shell.classList.toggle('sidebar-collapsed');
      var collapsed = shell.classList.contains('sidebar-collapsed');
      document.cookie = 'sidebar_collapsed=' + (collapsed ? '1' : '0') + '; path=/; max-age=31536000; SameSite=Lax; Secure';
    });
  }
  if (hamburgerBtn) {
    hamburgerBtn.addEventListener('click', function() { shell.classList.add('drawer-open'); });
  }
  if (closeDrawerBtn) {
    closeDrawerBtn.addEventListener('click', function() { shell.classList.remove('drawer-open'); });
  }
  if (scrim) {
    scrim.addEventListener('click', function() { shell.classList.remove('drawer-open'); });
  }
  document.querySelectorAll('.nav-item').forEach(function(el) {
    el.addEventListener('click', function() { shell.classList.remove('drawer-open'); });
  });

  var timerEl = document.getElementById('sessionTimer');
  if (timerEl) {
    var expiresAt = parseInt(timerEl.dataset.expires, 10) * 1000;
    function updateTimer() {
      var msLeft = expiresAt - Date.now();
      if (msLeft <= 0) {
        timerEl.textContent = 'Session expired';
        location.reload();
        return;
      }
      var minsLeft = Math.ceil(msLeft / 60000);
      timerEl.textContent = 'Session: ' + minsLeft + ' min';
    }
    updateTimer();
    setInterval(updateTimer, 30000);
  }
})();
</script>
HTML;
    echo "</body>\n</html>\n";
}

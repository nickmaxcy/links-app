<?php
declare(strict_types=1);

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    log_action(get_pdo(), 'logout', 'Logged out.');
}

$_SESSION = [];
session_destroy();
header('Location: /');
exit;

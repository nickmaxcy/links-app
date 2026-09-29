<?php
declare(strict_types=1);
// This page has moved to device-form.php (preserving any ?id= in the link).
$qs = $_SERVER['QUERY_STRING'] ?? '';
header('Location: /admin/device-form.php' . ($qs !== '' ? '?' . $qs : ''), true, 301);
exit;

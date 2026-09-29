<?php
declare(strict_types=1);
// The Zoom link prefix (and any per-platform URL prefix) now lives on the
// platform's own field definition, edited from the Platforms page.
header('Location: /admin/platforms.php', true, 301);
exit;

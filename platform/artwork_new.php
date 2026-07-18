<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

Auth::requireUser();

// Legacy entry point kept for old bookmarks and external links.
header('Location: create_scenes.php', true, 302);
exit;

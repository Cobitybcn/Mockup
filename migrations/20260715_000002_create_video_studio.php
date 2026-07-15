<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/Video/VideoStudioSchema.php';

$direction = $argv[1] ?? 'up';
if ($direction !== 'up') {
    throw new InvalidArgumentException('Video Studio uses an additive migration. Use up.');
}

VideoStudioSchema::migrate(Database::connection());
echo "Video Studio schema ready.\n";

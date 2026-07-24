<?php
declare(strict_types=1);

return [
    'description' => 'Retire the unused Series image-analysis tables',
    'up' => static function (PDO $pdo): void {
        $pdo->exec('DROP TABLE IF EXISTS series_visual_language_images');
        $pdo->exec('DROP TABLE IF EXISTS series_visual_language_runs');
    },
];

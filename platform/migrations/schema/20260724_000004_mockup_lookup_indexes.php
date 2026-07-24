<?php
declare(strict_types=1);

return [
    'description' => 'Cover the live-mockup lookup the public catalog repeats for every published item',
    'up' => static function (PDO $pdo): void {
        $mysql = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $index = 'mockups_user_file_idx';

        try {
            if ($mysql) {
                $statement = $pdo->prepare('SHOW INDEX FROM mockups WHERE Key_name=?');
                $statement->execute([$index]);
                if ($statement->fetch()) return;
            } else {
                foreach ($pdo->query('PRAGMA index_list(mockups)')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    if ((string)($row['name'] ?? '') === $index) return;
                }
            }
        } catch (PDOException) {
            return;
        }

        // The published catalog checks that every mockup sheet still has a live mockup,
        // matching on user_id plus mockup_file. This index makes that check covering.
        try {
            $pdo->exec("CREATE INDEX {$index} ON mockups (user_id,mockup_file)");
        } catch (PDOException) {
            // Installations predating the mockup_file column keep their existing plan.
        }
    },
];

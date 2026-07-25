<?php
declare(strict_types=1);

return [
    'description' => 'Add recoverable editorial workspace boards to Studio Notes',
    'up' => static function (PDO $pdo): void {
        $mysql = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql';
        if ($mysql) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS studio_note_workspace_items (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                note_id INT UNSIGNED NOT NULL,
                board_type VARCHAR(24) NOT NULL,
                locale VARCHAR(12) NOT NULL DEFAULT 'es',
                label VARCHAR(255) NOT NULL,
                content_json LONGTEXT NOT NULL,
                content_hash CHAR(64) NOT NULL,
                source_job_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                position INT UNSIGNED NOT NULL DEFAULT 0,
                created_at VARCHAR(40) NOT NULL,
                updated_at VARCHAR(40) NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_studio_note_workspace_content
                    (user_id,note_id,board_type,locale,content_hash,source_job_id),
                KEY ix_studio_note_workspace_board (user_id,note_id,board_type,position,id),
                CONSTRAINT fk_studio_note_workspace_user
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS studio_note_workspace_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                note_id INTEGER NOT NULL,
                board_type TEXT NOT NULL,
                locale TEXT NOT NULL DEFAULT 'es',
                label TEXT NOT NULL,
                content_json TEXT NOT NULL,
                content_hash TEXT NOT NULL,
                source_job_id INTEGER NOT NULL DEFAULT 0,
                position INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                UNIQUE (user_id,note_id,board_type,locale,content_hash,source_job_id)
            )");
            $pdo->exec('CREATE INDEX IF NOT EXISTS ix_studio_note_workspace_board
                ON studio_note_workspace_items (user_id,note_id,board_type,position,id)');
        }

        // Earlier generation jobs retained the user's original Spanish text in
        // payload_json. Seed the new board so the migration itself recovers it.
        // Minimal schema-governance databases intentionally omit product tables.
        $tableExists = static function (string $table) use ($pdo, $mysql): bool {
            if ($mysql) {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables
                    WHERE table_schema=DATABASE() AND table_name=?');
                $stmt->execute([$table]);
                return (int)$stmt->fetchColumn() > 0;
            }
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=?");
            $stmt->execute([$table]);
            return (int)$stmt->fetchColumn() > 0;
        };
        if (!$tableExists('bilingual_editorial_jobs') || !$tableExists('social_campaigns')) {
            return;
        }
        $jobs = $pdo->query("SELECT j.id,j.user_id,j.entity_id,j.status,j.payload_json,j.result_json,j.created_at
            FROM bilingual_editorial_jobs j
            INNER JOIN social_campaigns n
                ON n.id=j.entity_id AND n.user_id=j.user_id AND n.campaign_type='website_blog'
            WHERE j.entity_type='studio_note'
            ORDER BY j.id");
        $insertSql = "INSERT INTO studio_note_workspace_items
            (user_id,note_id,board_type,locale,label,content_json,content_hash,source_job_id,position,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)";
        $insertSql .= $mysql
            ? ' ON DUPLICATE KEY UPDATE label=VALUES(label),updated_at=VALUES(updated_at)'
            : ' ON CONFLICT(user_id,note_id,board_type,locale,content_hash,source_job_id)
                DO UPDATE SET label=excluded.label,updated_at=excluded.updated_at';
        $insert = $pdo->prepare($insertSql);
        $store = static function (
            array $job,
            string $boardType,
            string $locale,
            mixed $content,
            string $label
        ) use ($insert): void {
            if (!is_array($content)) return;
            $normalized = [];
            foreach ($content as $key => $value) {
                if (is_scalar($value) || $value === null) {
                    $normalized[(string)$key] = trim((string)$value);
                }
            }
            $hasContent = false;
            foreach ($normalized as $value) {
                if (trim(strip_tags((string)$value)) !== '') {
                    $hasContent = true;
                    break;
                }
            }
            if (!$hasContent) return;
            $encoded = json_encode(
                $normalized,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
            $createdAt = trim((string)$job['created_at']) ?: date(DATE_ATOM);
            $insert->execute([
                (int)$job['user_id'],
                (int)$job['entity_id'],
                $boardType,
                $locale,
                $label,
                $encoded,
                hash('sha256', $encoded),
                (int)$job['id'],
                (int)$job['id'],
                $createdAt,
                date(DATE_ATOM),
            ]);
        };
        foreach ($jobs as $job) {
            $payload = json_decode((string)$job['payload_json'], true);
            $result = json_decode((string)$job['result_json'], true);
            $payload = is_array($payload) ? $payload : [];
            $result = is_array($result) ? $result : [];
            $date = strtotime((string)$job['created_at']);
            $dateLabel = $date === false ? 'sin fecha' : date('d/m/Y H:i', $date);
            $store(
                $job,
                'version',
                'es',
                $payload['current_spanish'] ?? null,
                'Texto antes de la propuesta · ' . $dateLabel
            );
            if ((string)$job['status'] === 'completed') {
                $store($job, 'proposal', 'es', $result['spanish_content'] ?? null, 'Propuesta española · ' . $dateLabel);
                $store($job, 'proposal', 'en', $result['english_content'] ?? null, 'English proposal · ' . $dateLabel);
            }
        }
    },
];

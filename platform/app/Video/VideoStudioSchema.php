<?php
declare(strict_types=1);

final class VideoStudioSchema
{
    public static function migrate(PDO $pdo): void
    {
        ArtworkSeries::ensureSchema($pdo);

        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            self::migrateMysql($pdo);
        } else {
            self::migrateSqlite($pdo);
        }
        self::ensureAssociationColumns($pdo);
        self::backfillAssociations($pdo);
        self::backfillDefaultProjectTitles($pdo);
    }

    private static function migrateMysql(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS video_projects (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT NOT NULL,
            global_prompt MEDIUMTEXT NOT NULL,
            artwork_id INT UNSIGNED NULL,
            series_id INT UNSIGNED NULL,
            aspect_ratio VARCHAR(12) NOT NULL DEFAULT '9:16',
            target_duration_seconds DECIMAL(8,2) NOT NULL DEFAULT 30.00,
            project_type VARCHAR(40) NOT NULL DEFAULT 'custom',
            status VARCHAR(30) NOT NULL DEFAULT 'draft',
            master_volume DECIMAL(5,4) NOT NULL DEFAULT 1.0000,
            version INT UNSIGNED NOT NULL DEFAULT 1,
            created_at VARCHAR(40) NOT NULL,
            updated_at VARCHAR(40) NOT NULL,
            KEY idx_video_projects_user_updated(user_id,updated_at),
            KEY idx_video_projects_user_status(user_id,status),
            CONSTRAINT fk_video_projects_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_video_projects_artwork FOREIGN KEY(artwork_id) REFERENCES artworks(id) ON DELETE SET NULL,
            CONSTRAINT fk_video_projects_series FOREIGN KEY(series_id) REFERENCES artwork_series(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS video_scenes (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            video_project_id INT UNSIGNED NOT NULL,
            position INT UNSIGNED NOT NULL,
            title VARCHAR(255) NOT NULL,
            purpose VARCHAR(40) NOT NULL DEFAULT 'custom',
            prompt MEDIUMTEXT NOT NULL,
            duration_seconds DECIMAL(6,2) NOT NULL DEFAULT 6.00,
            generation_mode VARCHAR(40) NOT NULL DEFAULT 'image_to_video',
            artwork_motion VARCHAR(20) NOT NULL DEFAULT 'locked',
            camera_movement VARCHAR(40) NOT NULL DEFAULT 'static',
            custom_camera_movement VARCHAR(255) NOT NULL DEFAULT '',
            motion_intensity VARCHAR(20) NOT NULL DEFAULT 'low',
            transition_out_type VARCHAR(30) NOT NULL DEFAULT 'cut',
            transition_out_duration_seconds DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            audio_mode VARCHAR(30) NOT NULL DEFAULT 'silence',
            status VARCHAR(30) NOT NULL DEFAULT 'draft',
            is_locked TINYINT(1) NOT NULL DEFAULT 0,
            created_at VARCHAR(40) NOT NULL,
            updated_at VARCHAR(40) NOT NULL,
            UNIQUE KEY uq_video_scenes_project_position(video_project_id,position),
            KEY idx_video_scenes_project_status(video_project_id,status),
            CONSTRAINT fk_video_scenes_project FOREIGN KEY(video_project_id) REFERENCES video_projects(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS video_scene_references (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            video_scene_id INT UNSIGNED NOT NULL,
            role VARCHAR(40) NOT NULL DEFAULT 'main',
            source_type VARCHAR(30) NOT NULL,
            source_id INT UNSIGNED NOT NULL,
            position INT UNSIGNED NOT NULL DEFAULT 1,
            file_path VARCHAR(500) NOT NULL DEFAULT '',
            metadata_json MEDIUMTEXT NOT NULL,
            created_at VARCHAR(40) NOT NULL,
            updated_at VARCHAR(40) NOT NULL,
            KEY idx_video_scene_refs_scene_role(video_scene_id,role,position),
            KEY idx_video_scene_refs_source(source_type,source_id),
            CONSTRAINT fk_video_scene_refs_scene FOREIGN KEY(video_scene_id) REFERENCES video_scenes(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS video_generation_jobs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            video_project_id INT UNSIGNED NOT NULL,
            video_scene_id INT UNSIGNED NULL,
            artwork_id INT UNSIGNED NULL,
            series_id INT UNSIGNED NULL,
            provider VARCHAR(60) NOT NULL,
            model VARCHAR(120) NOT NULL DEFAULT '',
            generation_mode VARCHAR(40) NOT NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'queued',
            external_job_id VARCHAR(255) NOT NULL DEFAULT '',
            task_name VARCHAR(500) NOT NULL DEFAULT '',
            idempotency_key VARCHAR(100) NOT NULL,
            scene_version INT UNSIGNED NOT NULL DEFAULT 1,
            input_hash CHAR(64) NOT NULL DEFAULT '',
            active_slot TINYINT UNSIGNED NULL,
            requested_duration_seconds DECIMAL(6,2) NOT NULL,
            generated_duration_seconds DECIMAL(6,2) NOT NULL DEFAULT 0.00,
            aspect_ratio VARCHAR(12) NOT NULL,
            prompt_text MEDIUMTEXT NOT NULL,
            request_json MEDIUMTEXT NOT NULL,
            response_json MEDIUMTEXT NOT NULL,
            output_path VARCHAR(500) NOT NULL DEFAULT '',
            thumbnail_path VARCHAR(500) NOT NULL DEFAULT '',
            error TEXT NOT NULL,
            cost_estimate DECIMAL(12,4) NULL,
            cost_currency VARCHAR(10) NOT NULL DEFAULT '',
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            next_poll_at VARCHAR(40) NULL,
            started_at VARCHAR(40) NULL,
            completed_at VARCHAR(40) NULL,
            created_at VARCHAR(40) NOT NULL,
            updated_at VARCHAR(40) NOT NULL,
            UNIQUE KEY uq_video_jobs_user_idempotency(user_id,idempotency_key),
            UNIQUE KEY uq_video_jobs_scene_active(video_scene_id,active_slot),
            KEY idx_video_jobs_scene_status(video_scene_id,status,id),
            KEY idx_video_jobs_project_status(video_project_id,status,updated_at),
            KEY idx_video_jobs_user_artwork(user_id,artwork_id,created_at),
            KEY idx_video_jobs_user_series(user_id,series_id,created_at),
            CONSTRAINT fk_video_jobs_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_video_jobs_project FOREIGN KEY(video_project_id) REFERENCES video_projects(id) ON DELETE CASCADE,
            CONSTRAINT fk_video_jobs_scene FOREIGN KEY(video_scene_id) REFERENCES video_scenes(id) ON DELETE SET NULL,
            CONSTRAINT fk_video_jobs_artwork FOREIGN KEY(artwork_id) REFERENCES artworks(id) ON DELETE SET NULL,
            CONSTRAINT fk_video_jobs_series FOREIGN KEY(series_id) REFERENCES artwork_series(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS video_exports (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            video_project_id INT UNSIGNED NOT NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'queued',
            format VARCHAR(20) NOT NULL DEFAULT 'mp4',
            video_codec VARCHAR(30) NOT NULL DEFAULT 'h264',
            audio_codec VARCHAR(30) NOT NULL DEFAULT 'aac',
            aspect_ratio VARCHAR(12) NOT NULL,
            timeline_snapshot_json MEDIUMTEXT NOT NULL,
            task_name VARCHAR(500) NOT NULL DEFAULT '',
            output_path VARCHAR(500) NOT NULL DEFAULT '',
            duration_seconds DECIMAL(9,2) NOT NULL DEFAULT 0.00,
            bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            error TEXT NOT NULL,
            started_at VARCHAR(40) NULL,
            completed_at VARCHAR(40) NULL,
            created_at VARCHAR(40) NOT NULL,
            updated_at VARCHAR(40) NOT NULL,
            KEY idx_video_exports_project_status(video_project_id,status,id),
            KEY idx_video_exports_user_updated(user_id,updated_at),
            CONSTRAINT fk_video_exports_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_video_exports_project FOREIGN KEY(video_project_id) REFERENCES video_projects(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private static function migrateSqlite(PDO $pdo): void
    {
        $statements = [
            "CREATE TABLE IF NOT EXISTS video_projects (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,title TEXT NOT NULL,description TEXT NOT NULL DEFAULT '',global_prompt TEXT NOT NULL DEFAULT '',artwork_id INTEGER,series_id INTEGER,aspect_ratio TEXT NOT NULL DEFAULT '9:16',target_duration_seconds REAL NOT NULL DEFAULT 30,project_type TEXT NOT NULL DEFAULT 'custom',status TEXT NOT NULL DEFAULT 'draft',master_volume REAL NOT NULL DEFAULT 1,version INTEGER NOT NULL DEFAULT 1,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(artwork_id) REFERENCES artworks(id) ON DELETE SET NULL,FOREIGN KEY(series_id) REFERENCES artwork_series(id) ON DELETE SET NULL)",
            "CREATE INDEX IF NOT EXISTS idx_video_projects_user_updated ON video_projects(user_id,updated_at)",
            "CREATE INDEX IF NOT EXISTS idx_video_projects_user_status ON video_projects(user_id,status)",
            "CREATE TABLE IF NOT EXISTS video_scenes (id INTEGER PRIMARY KEY AUTOINCREMENT,video_project_id INTEGER NOT NULL,position INTEGER NOT NULL,title TEXT NOT NULL,purpose TEXT NOT NULL DEFAULT 'custom',prompt TEXT NOT NULL DEFAULT '',duration_seconds REAL NOT NULL DEFAULT 6,generation_mode TEXT NOT NULL DEFAULT 'image_to_video',artwork_motion TEXT NOT NULL DEFAULT 'locked',camera_movement TEXT NOT NULL DEFAULT 'static',custom_camera_movement TEXT NOT NULL DEFAULT '',motion_intensity TEXT NOT NULL DEFAULT 'low',transition_out_type TEXT NOT NULL DEFAULT 'cut',transition_out_duration_seconds REAL NOT NULL DEFAULT 0,audio_mode TEXT NOT NULL DEFAULT 'silence',status TEXT NOT NULL DEFAULT 'draft',is_locked INTEGER NOT NULL DEFAULT 0,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,UNIQUE(video_project_id,position),FOREIGN KEY(video_project_id) REFERENCES video_projects(id) ON DELETE CASCADE)",
            "CREATE INDEX IF NOT EXISTS idx_video_scenes_project_status ON video_scenes(video_project_id,status)",
            "CREATE TABLE IF NOT EXISTS video_scene_references (id INTEGER PRIMARY KEY AUTOINCREMENT,video_scene_id INTEGER NOT NULL,role TEXT NOT NULL DEFAULT 'main',source_type TEXT NOT NULL,source_id INTEGER NOT NULL,position INTEGER NOT NULL DEFAULT 1,file_path TEXT NOT NULL DEFAULT '',metadata_json TEXT NOT NULL DEFAULT '',created_at TEXT NOT NULL,updated_at TEXT NOT NULL,FOREIGN KEY(video_scene_id) REFERENCES video_scenes(id) ON DELETE CASCADE)",
            "CREATE INDEX IF NOT EXISTS idx_video_scene_refs_scene_role ON video_scene_references(video_scene_id,role,position)",
            "CREATE INDEX IF NOT EXISTS idx_video_scene_refs_source ON video_scene_references(source_type,source_id)",
            "CREATE TABLE IF NOT EXISTS video_generation_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,video_project_id INTEGER NOT NULL,video_scene_id INTEGER,artwork_id INTEGER,series_id INTEGER,provider TEXT NOT NULL,model TEXT NOT NULL DEFAULT '',generation_mode TEXT NOT NULL,status TEXT NOT NULL DEFAULT 'queued',external_job_id TEXT NOT NULL DEFAULT '',task_name TEXT NOT NULL DEFAULT '',idempotency_key TEXT NOT NULL,scene_version INTEGER NOT NULL DEFAULT 1,input_hash TEXT NOT NULL DEFAULT '',active_slot INTEGER,requested_duration_seconds REAL NOT NULL,generated_duration_seconds REAL NOT NULL DEFAULT 0,aspect_ratio TEXT NOT NULL,prompt_text TEXT NOT NULL,request_json TEXT NOT NULL DEFAULT '',response_json TEXT NOT NULL DEFAULT '',output_path TEXT NOT NULL DEFAULT '',thumbnail_path TEXT NOT NULL DEFAULT '',error TEXT NOT NULL DEFAULT '',cost_estimate REAL,cost_currency TEXT NOT NULL DEFAULT '',attempts INTEGER NOT NULL DEFAULT 0,next_poll_at TEXT,started_at TEXT,completed_at TEXT,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,UNIQUE(user_id,idempotency_key),UNIQUE(video_scene_id,active_slot),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(video_project_id) REFERENCES video_projects(id) ON DELETE CASCADE,FOREIGN KEY(video_scene_id) REFERENCES video_scenes(id) ON DELETE SET NULL,FOREIGN KEY(artwork_id) REFERENCES artworks(id) ON DELETE SET NULL,FOREIGN KEY(series_id) REFERENCES artwork_series(id) ON DELETE SET NULL)",
            "CREATE INDEX IF NOT EXISTS idx_video_jobs_scene_status ON video_generation_jobs(video_scene_id,status,id)",
            "CREATE INDEX IF NOT EXISTS idx_video_jobs_project_status ON video_generation_jobs(video_project_id,status,updated_at)",
            "CREATE INDEX IF NOT EXISTS idx_video_jobs_user_artwork ON video_generation_jobs(user_id,artwork_id,created_at)",
            "CREATE INDEX IF NOT EXISTS idx_video_jobs_user_series ON video_generation_jobs(user_id,series_id,created_at)",
            "CREATE TABLE IF NOT EXISTS video_exports (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,video_project_id INTEGER NOT NULL,status TEXT NOT NULL DEFAULT 'queued',format TEXT NOT NULL DEFAULT 'mp4',video_codec TEXT NOT NULL DEFAULT 'h264',audio_codec TEXT NOT NULL DEFAULT 'aac',aspect_ratio TEXT NOT NULL,timeline_snapshot_json TEXT NOT NULL DEFAULT '',task_name TEXT NOT NULL DEFAULT '',output_path TEXT NOT NULL DEFAULT '',duration_seconds REAL NOT NULL DEFAULT 0,bytes INTEGER NOT NULL DEFAULT 0,error TEXT NOT NULL DEFAULT '',started_at TEXT,completed_at TEXT,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(video_project_id) REFERENCES video_projects(id) ON DELETE CASCADE)",
            "CREATE INDEX IF NOT EXISTS idx_video_exports_project_status ON video_exports(video_project_id,status,id)",
            "CREATE INDEX IF NOT EXISTS idx_video_exports_user_updated ON video_exports(user_id,updated_at)",
        ];

        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }
    }

    private static function ensureAssociationColumns(PDO $pdo): void
    {
        $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        if (!self::columnExists($pdo, 'video_generation_jobs', 'artwork_id')) {
            $pdo->exec('ALTER TABLE video_generation_jobs ADD COLUMN artwork_id ' . ($mysql ? 'INT UNSIGNED NULL AFTER video_scene_id' : 'INTEGER NULL'));
        }
        if (!self::columnExists($pdo, 'video_generation_jobs', 'series_id')) {
            $pdo->exec('ALTER TABLE video_generation_jobs ADD COLUMN series_id ' . ($mysql ? 'INT UNSIGNED NULL AFTER artwork_id' : 'INTEGER NULL'));
        }

        self::ensureIndex($pdo, 'idx_video_jobs_user_artwork', 'user_id,artwork_id,created_at');
        self::ensureIndex($pdo, 'idx_video_jobs_user_series', 'user_id,series_id,created_at');
    }

    private static function columnExists(PDO $pdo, string $table, string $column): bool
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');
            $stmt->execute([$table, $column]);
            return (int)$stmt->fetchColumn() > 0;
        }
        foreach ($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ((string)$row['name'] === $column) return true;
        }
        return false;
    }

    private static function ensureIndex(PDO $pdo, string $name, string $columns): void
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=\'video_generation_jobs\' AND index_name=?');
            $stmt->execute([$name]);
            if ((int)$stmt->fetchColumn() === 0) $pdo->exec('ALTER TABLE video_generation_jobs ADD INDEX ' . $name . ' (' . $columns . ')');
            return;
        }
        $pdo->exec('CREATE INDEX IF NOT EXISTS ' . $name . ' ON video_generation_jobs(' . $columns . ')');
    }

    private static function backfillAssociations(PDO $pdo): void
    {
        $jobs = $pdo->query("SELECT id,user_id,video_project_id,video_scene_id,generation_mode,request_json,artwork_id,series_id
            FROM video_generation_jobs WHERE artwork_id IS NULL OR series_id IS NULL ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $projectLookup = $pdo->prepare('SELECT artwork_id,series_id FROM video_projects WHERE id=? AND user_id=? LIMIT 1');
        $fallbackReference = $pdo->prepare("SELECT id FROM video_scene_references WHERE video_scene_id=?
            ORDER BY CASE role WHEN 'start_frame' THEN 1 WHEN 'main' THEN 2 WHEN 'end_frame' THEN 3 ELSE 4 END,position,id LIMIT 1");
        $updateJob = $pdo->prepare('UPDATE video_generation_jobs SET artwork_id=COALESCE(artwork_id,?),series_id=COALESCE(series_id,?) WHERE id=?');

        foreach ($jobs as $job) {
            $artworkId = $job['artwork_id'] === null ? null : (int)$job['artwork_id'];
            $seriesId = $job['series_id'] === null ? null : (int)$job['series_id'];
            $snapshot = json_decode((string)$job['request_json'], true);
            if (!is_array($snapshot)) $snapshot = [];
            $referenceIds = [];
            foreach (['startReferenceId','referenceId','endReferenceId'] as $key) {
                if ((int)($snapshot[$key] ?? 0) > 0) $referenceIds[] = (int)$snapshot[$key];
            }
            if (!$referenceIds && (int)($job['video_scene_id'] ?? 0) > 0) {
                $fallbackReference->execute([(int)$job['video_scene_id']]);
                $fallbackId = (int)$fallbackReference->fetchColumn();
                if ($fallbackId > 0) $referenceIds[] = $fallbackId;
            }
            foreach ($referenceIds as $referenceId) {
                $association = self::associationFromReference($pdo, (int)$job['user_id'], (int)($job['video_scene_id'] ?? 0), $referenceId);
                if ($association['artworkId'] !== null) {
                    $artworkId ??= $association['artworkId'];
                    $seriesId ??= $association['seriesId'];
                    break;
                }
            }
            if ($artworkId === null || $seriesId === null) {
                $projectLookup->execute([(int)$job['video_project_id'], (int)$job['user_id']]);
                $project = $projectLookup->fetch(PDO::FETCH_ASSOC);
                if (is_array($project)) {
                    $artworkId ??= $project['artwork_id'] === null ? null : (int)$project['artwork_id'];
                    $seriesId ??= $project['series_id'] === null ? null : (int)$project['series_id'];
                }
            }
            if ($artworkId !== null && $seriesId === null) $seriesId = self::seriesForArtwork($pdo, (int)$job['user_id'], $artworkId);
            $updateJob->execute([$artworkId, $seriesId, (int)$job['id']]);
        }

        $projects = $pdo->query('SELECT id,user_id,artwork_id,series_id FROM video_projects WHERE artwork_id IS NULL OR series_id IS NULL ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $latestJob = $pdo->prepare("SELECT artwork_id,series_id FROM video_generation_jobs
            WHERE video_project_id=? AND user_id=? AND status='succeeded' AND artwork_id IS NOT NULL ORDER BY id DESC LIMIT 1");
        $updateProject = $pdo->prepare('UPDATE video_projects SET artwork_id=COALESCE(artwork_id,?),series_id=COALESCE(series_id,?) WHERE id=? AND user_id=?');
        foreach ($projects as $project) {
            $artworkId = $project['artwork_id'] === null ? null : (int)$project['artwork_id'];
            $seriesId = $project['series_id'] === null ? null : (int)$project['series_id'];
            if ($artworkId === null) {
                $latestJob->execute([(int)$project['id'], (int)$project['user_id']]);
                $job = $latestJob->fetch(PDO::FETCH_ASSOC);
                if (is_array($job)) {
                    $artworkId = (int)$job['artwork_id'];
                    $seriesId ??= $job['series_id'] === null ? null : (int)$job['series_id'];
                }
            }
            if ($artworkId !== null && $seriesId === null) $seriesId = self::seriesForArtwork($pdo, (int)$project['user_id'], $artworkId);
            $updateProject->execute([$artworkId, $seriesId, (int)$project['id'], (int)$project['user_id']]);
        }
    }

    private static function backfillDefaultProjectTitles(PDO $pdo): void
    {
        $projects = $pdo->query("SELECT id,user_id,artwork_id FROM video_projects
            WHERE artwork_id IS NOT NULL AND title IN ('Untitled Video','Nuevo video') ORDER BY user_id,artwork_id,id")->fetchAll(PDO::FETCH_ASSOC);
        if (!$projects) return;

        $artwork = $pdo->prepare("SELECT a.final_title,ag.title AS group_title,
                (SELECT sh.title FROM artwork_sheets sh WHERE sh.user_id=a.user_id AND sh.canonical_artwork_id=a.id ORDER BY sh.id DESC LIMIT 1) AS sheet_title
            FROM artworks a
            LEFT JOIN artwork_groups ag ON ag.id=a.artwork_group_id AND ag.user_id=a.user_id AND ag.status='active'
            WHERE a.id=? AND a.user_id=? LIMIT 1");
        $position = $pdo->prepare('SELECT COUNT(*) FROM video_projects WHERE user_id=? AND artwork_id=? AND id<=?');
        $update = $pdo->prepare('UPDATE video_projects SET title=? WHERE id=? AND user_id=?');
        foreach ($projects as $project) {
            $artworkId = (int)$project['artwork_id'];
            $userId = (int)$project['user_id'];
            $artwork->execute([$artworkId, $userId]);
            $row = $artwork->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) continue;
            $base = trim((string)($row['group_title'] ?? ''))
                ?: trim((string)($row['final_title'] ?? ''))
                ?: trim((string)($row['sheet_title'] ?? ''))
                ?: 'Artwork #' . $artworkId;
            $position->execute([$userId, $artworkId, (int)$project['id']]);
            $number = max(1, (int)$position->fetchColumn());
            $title = mb_substr($base, 0, 225) . ' — Video ' . str_pad((string)$number, 2, '0', STR_PAD_LEFT);
            $update->execute([$title, (int)$project['id'], $userId]);
        }
    }

    /** @return array{artworkId:?int,seriesId:?int} */
    private static function associationFromReference(PDO $pdo, int $userId, int $sceneId, int $referenceId): array
    {
        $stmt = $pdo->prepare('SELECT source_type,source_id FROM video_scene_references WHERE id=? AND video_scene_id=? LIMIT 1');
        $stmt->execute([$referenceId, $sceneId]);
        $reference = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($reference)) return ['artworkId' => null, 'seriesId' => null];

        if ((string)$reference['source_type'] === 'mockup') {
            $source = $pdo->prepare('SELECT m.source_artwork_id AS artwork_id,COALESCE(m.series_id,a.series_id) AS series_id
                FROM mockups m LEFT JOIN artworks a ON a.id=m.source_artwork_id AND a.user_id=m.user_id WHERE m.id=? AND m.user_id=? LIMIT 1');
        } elseif ((string)$reference['source_type'] === 'artwork') {
            $source = $pdo->prepare('SELECT id AS artwork_id,series_id FROM artworks WHERE id=? AND user_id=? LIMIT 1');
        } elseif ((string)$reference['source_type'] === 'generation_job') {
            $source = $pdo->prepare('SELECT artwork_id,series_id FROM video_generation_jobs WHERE id=? AND user_id=? LIMIT 1');
        } else {
            return ['artworkId' => null, 'seriesId' => null];
        }
        $source->execute([(int)$reference['source_id'], $userId]);
        $row = $source->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return ['artworkId' => null, 'seriesId' => null];
        return [
            'artworkId' => $row['artwork_id'] === null ? null : (int)$row['artwork_id'],
            'seriesId' => $row['series_id'] === null ? null : (int)$row['series_id'],
        ];
    }

    private static function seriesForArtwork(PDO $pdo, int $userId, int $artworkId): ?int
    {
        $stmt = $pdo->prepare('SELECT series_id FROM artworks WHERE id=? AND user_id=? LIMIT 1');
        $stmt->execute([$artworkId, $userId]);
        $seriesId = $stmt->fetchColumn();
        return $seriesId === false || $seriesId === null || $seriesId === '' ? null : (int)$seriesId;
    }
}

<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

$options = getopt('', [
    'user-email:',
    'target-host:',
    'target-port::',
    'target-database::',
    'target-username::',
    'apply',
]);

$email = strtolower(trim((string)($options['user-email'] ?? '')));
$targetHost = trim((string)($options['target-host'] ?? getenv('EDITORIAL_SYNC_TARGET_HOST') ?: ''));
$targetPort = max(1, (int)($options['target-port'] ?? getenv('EDITORIAL_SYNC_TARGET_PORT') ?: 3306));
$targetDatabase = trim((string)($options['target-database'] ?? getenv('EDITORIAL_SYNC_TARGET_DATABASE') ?: 'mockups'));
$targetUsername = trim((string)($options['target-username'] ?? getenv('EDITORIAL_SYNC_TARGET_USERNAME') ?: 'mockups_app'));
$targetPassword = (string)(getenv('EDITORIAL_SYNC_TARGET_PASSWORD') ?: '');
$apply = array_key_exists('apply', $options);

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "A valid --user-email is required.\n");
    exit(1);
}
if ($targetHost === '' || $targetPassword === '') {
    fwrite(STDERR, "Target host and EDITORIAL_SYNC_TARGET_PASSWORD are required.\n");
    exit(1);
}
if (app_env('APP_ENV', '') !== 'local') {
    fwrite(STDERR, "Safety stop: the source must run with APP_ENV=local.\n");
    exit(1);
}

$source = Database::connection();
$sourceDatabase = (string)$source->query('SELECT DATABASE()')->fetchColumn();
if (!str_contains(strtolower($sourceDatabase), 'local')) {
    fwrite(STDERR, "Safety stop: source database is not a local database.\n");
    exit(1);
}
if ($targetDatabase !== 'mockups') {
    fwrite(STDERR, "Safety stop: the target database must be exactly 'mockups'.\n");
    exit(1);
}

$target = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $targetHost, $targetPort, $targetDatabase),
    $targetUsername,
    $targetPassword,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

$latestMigration = '20260723_000008_retire_series_visual_language';
$migration = $target->prepare('SELECT COUNT(*) FROM schema_migrations WHERE version=?');
$migration->execute([$latestMigration]);
if ((int)$migration->fetchColumn() !== 1) {
    fwrite(STDERR, "Safety stop: production schema is not ready for this editorial synchronization.\n");
    exit(1);
}

$findUser = static function (PDO $pdo, string $userEmail): array {
    $stmt = $pdo->prepare('SELECT id,email,name,status FROM users WHERE LOWER(email)=? LIMIT 1');
    $stmt->execute([$userEmail]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : [];
};

$sourceUser = $findUser($source, $email);
$targetUser = $findUser($target, $email);
if ($sourceUser === [] || $targetUser === []) {
    fwrite(STDERR, "Safety stop: the artist must exist in both databases.\n");
    exit(1);
}
if ((string)$sourceUser['status'] !== 'active' || (string)$targetUser['status'] !== 'active') {
    fwrite(STDERR, "Safety stop: the artist must be active in both databases.\n");
    exit(1);
}

$sourceUserId = (int)$sourceUser['id'];
$targetUserId = (int)$targetUser['id'];
$stats = [
    'updated' => [],
    'inserted' => [],
    'unchanged' => [],
    'preserved_target_only' => [],
    'missing_target' => [],
];

$record = static function (array &$summary, string $bucket, string $table): void {
    $summary[$bucket][$table] = (int)($summary[$bucket][$table] ?? 0) + 1;
};

$sameFields = static function (array $sourceRow, array $targetRow, array $fields): bool {
    foreach ($fields as $field) {
        if ((string)($sourceRow[$field] ?? '') !== (string)($targetRow[$field] ?? '')) {
            return false;
        }
    }
    return true;
};

$syncById = static function (
    PDO $sourcePdo,
    PDO $targetPdo,
    string $table,
    int $sourceUid,
    int $targetUid,
    array $fields,
    array &$summary
) use ($record, $sameFields): void {
    $sourceStmt = $sourcePdo->prepare("SELECT id," . implode(',', $fields) . " FROM {$table} WHERE user_id=? ORDER BY id");
    $sourceStmt->execute([$sourceUid]);
    $targetStmt = $targetPdo->prepare("SELECT id," . implode(',', $fields) . " FROM {$table} WHERE id=? AND user_id=? LIMIT 1");
    $assignments = implode(',', array_map(static fn(string $field): string => "{$field}=?", $fields));
    $update = $targetPdo->prepare("UPDATE {$table} SET {$assignments},updated_at=? WHERE id=? AND user_id=?");

    foreach ($sourceStmt->fetchAll() as $row) {
        $targetStmt->execute([(int)$row['id'], $targetUid]);
        $targetRow = $targetStmt->fetch();
        if (!is_array($targetRow)) {
            $record($summary, 'missing_target', $table);
            continue;
        }
        if ($sameFields($row, $targetRow, $fields)) {
            $record($summary, 'unchanged', $table);
            continue;
        }
        $values = [];
        foreach ($fields as $field) {
            $values[] = $row[$field] ?? null;
        }
        $values[] = date(DATE_ATOM);
        $values[] = (int)$row['id'];
        $values[] = $targetUid;
        $update->execute($values);
        $record($summary, 'updated', $table);
    }

    $sourceIdsStmt = $sourcePdo->prepare("SELECT id FROM {$table} WHERE user_id=?");
    $sourceIdsStmt->execute([$sourceUid]);
    $sourceIds = array_map('intval', $sourceIdsStmt->fetchAll(PDO::FETCH_COLUMN));
    $targetIdsStmt = $targetPdo->prepare("SELECT id FROM {$table} WHERE user_id=?");
    $targetIdsStmt->execute([$targetUid]);
    foreach (array_map('intval', $targetIdsStmt->fetchAll(PDO::FETCH_COLUMN)) as $targetId) {
        if (!in_array($targetId, $sourceIds, true)) {
            $record($summary, 'preserved_target_only', $table);
        }
    }
};

$target->beginTransaction();
try {
    $profileFields = [
        'artist_name',
        'short_bio',
        'statement',
        'visual_language',
        'materials',
        'recurring_themes',
        'palette_notes',
        'target_audience',
        'preferred_regions',
        'preferred_contexts',
        'forbidden_contexts',
        'commercial_positioning',
        'conceptual_keywords',
        'tone_of_voice',
        'marketplace_strategy',
        'social_strategy',
        'pinterest_strategy',
        'photo_file',
    ];
    $sourceProfileStmt = $source->prepare('SELECT ' . implode(',', $profileFields) . ' FROM artist_profiles WHERE user_id=? LIMIT 1');
    $sourceProfileStmt->execute([$sourceUserId]);
    $sourceProfile = $sourceProfileStmt->fetch();
    $targetProfileStmt = $target->prepare('SELECT ' . implode(',', $profileFields) . ' FROM artist_profiles WHERE user_id=? LIMIT 1');
    $targetProfileStmt->execute([$targetUserId]);
    $targetProfile = $targetProfileStmt->fetch();
    if (!is_array($sourceProfile) || !is_array($targetProfile)) {
        throw new RuntimeException('Artist profile is missing in one of the databases.');
    }
    if ($sameFields($sourceProfile, $targetProfile, $profileFields)) {
        $record($stats, 'unchanged', 'artist_profiles');
    } else {
        $profileAssignments = implode(',', array_map(static fn(string $field): string => "{$field}=?", $profileFields));
        $values = array_map(static fn(string $field): mixed => $sourceProfile[$field] ?? null, $profileFields);
        $values[] = date(DATE_ATOM);
        $values[] = $targetUserId;
        $target->prepare("UPDATE artist_profiles SET {$profileAssignments},updated_at=? WHERE user_id=?")->execute($values);
        $record($stats, 'updated', 'artist_profiles');
    }

    $syncById(
        $source,
        $target,
        'artwork_series',
        $sourceUserId,
        $targetUserId,
        [
            'title',
            'slug',
            'description',
            'status',
            'subtitle',
            'long_description',
            'keywords',
            'tags',
            'seo_description',
            'year_start',
            'year_end',
            'header_file',
            'published',
            'header_focal_x',
            'header_focal_y',
            'header_zoom',
            'display_order',
            'conceptual_core',
            'interpretive_limits',
        ],
        $stats
    );
    $syncById(
        $source,
        $target,
        'artworks',
        $sourceUserId,
        $targetUserId,
        [
            'final_title',
            'subtitle',
            'medium',
            'artwork_year',
            'series',
            'width',
            'height',
            'depth',
            'unit',
            'series_id',
            'series_creation_number',
            'reference_set_id',
        ],
        $stats
    );
    $syncById(
        $source,
        $target,
        'artwork_sheets',
        $sourceUserId,
        $targetUserId,
        [
            'user_notes',
            'title',
            'subtitle',
            'description',
            'short_description',
            'keywords',
            'tags',
            'alt_text',
            'caption',
            'generated_json',
        ],
        $stats
    );
    $syncById(
        $source,
        $target,
        'mockup_sheets',
        $sourceUserId,
        $targetUserId,
        [
            'user_notes',
            'title',
            'description',
            'keywords',
            'tags',
            'alt_text',
            'caption',
            'generated_json',
        ],
        $stats
    );

    $sourceArtworkTitles = $source->prepare(
        'SELECT g.id,a.final_title
         FROM artwork_groups g
         JOIN artworks a ON a.id=g.canonical_artwork_id AND a.user_id=g.user_id
         WHERE g.user_id=? AND g.status="active"'
    );
    $sourceArtworkTitles->execute([$sourceUserId]);
    $targetGroup = $target->prepare('SELECT title FROM artwork_groups WHERE id=? AND user_id=? AND status="active"');
    $updateGroup = $target->prepare('UPDATE artwork_groups SET title=?,updated_at=? WHERE id=? AND user_id=? AND status="active"');
    foreach ($sourceArtworkTitles->fetchAll() as $group) {
        $title = trim((string)$group['final_title']);
        if ($title === '') {
            continue;
        }
        $targetGroup->execute([(int)$group['id'], $targetUserId]);
        $current = $targetGroup->fetchColumn();
        if ($current === false) {
            $record($stats, 'missing_target', 'artwork_groups');
            continue;
        }
        if ((string)$current === $title) {
            $record($stats, 'unchanged', 'artwork_groups');
            continue;
        }
        $updateGroup->execute([$title, date(DATE_ATOM), (int)$group['id'], $targetUserId]);
        $record($stats, 'updated', 'artwork_groups');
    }
    $sourceGroupIdsStmt = $source->prepare('SELECT id FROM artwork_groups WHERE user_id=?');
    $sourceGroupIdsStmt->execute([$sourceUserId]);
    $sourceGroupIds = array_map('intval', $sourceGroupIdsStmt->fetchAll(PDO::FETCH_COLUMN));
    $targetGroupIdsStmt = $target->prepare('SELECT id FROM artwork_groups WHERE user_id=?');
    $targetGroupIdsStmt->execute([$targetUserId]);
    foreach (array_map('intval', $targetGroupIdsStmt->fetchAll(PDO::FETCH_COLUMN)) as $targetGroupId) {
        if (!in_array($targetGroupId, $sourceGroupIds, true)) {
            $record($stats, 'preserved_target_only', 'artwork_groups');
        }
    }

    $sourcePublicationStmt = $source->prepare(
        'SELECT p.*,s.title sheet_title,s.description sheet_description,s.short_description sheet_short_description
         FROM publications p
         LEFT JOIN artwork_sheets s ON s.id=p.artwork_sheet_id AND s.user_id=p.user_id
         WHERE p.user_id=?
         ORDER BY p.id'
    );
    $sourcePublicationStmt->execute([$sourceUserId]);
    $publicationFields = [
        'title',
        'description',
        'short_description',
        'language',
        'objective',
        'cta_label',
        'cta_url',
        'profile_snapshot_json',
        'metadata_snapshot_json',
        'content_source',
    ];
    $targetPublicationStmt = $target->prepare('SELECT ' . implode(',', $publicationFields) . ' FROM publications WHERE id=? AND user_id=? LIMIT 1');
    $publicationAssignments = implode(',', array_map(static fn(string $field): string => "{$field}=?", $publicationFields));
    $updatePublication = $target->prepare("UPDATE publications SET {$publicationAssignments},updated_at=? WHERE id=? AND user_id=?");
    foreach ($sourcePublicationStmt->fetchAll() as $publication) {
        if (trim((string)$publication['title']) === '' && trim((string)($publication['sheet_title'] ?? '')) !== '') {
            $publication['title'] = $publication['sheet_title'];
        }
        if (trim((string)$publication['description']) === '' && trim((string)($publication['sheet_description'] ?? '')) !== '') {
            $publication['description'] = $publication['sheet_description'];
        }
        if (trim((string)$publication['short_description']) === '' && trim((string)($publication['sheet_short_description'] ?? '')) !== '') {
            $publication['short_description'] = $publication['sheet_short_description'];
        }
        $targetPublicationStmt->execute([(int)$publication['id'], $targetUserId]);
        $targetPublication = $targetPublicationStmt->fetch();
        if (!is_array($targetPublication)) {
            $record($stats, 'missing_target', 'publications');
            continue;
        }
        if ($sameFields($publication, $targetPublication, $publicationFields)) {
            $record($stats, 'unchanged', 'publications');
            continue;
        }
        $values = array_map(static fn(string $field): mixed => $publication[$field] ?? null, $publicationFields);
        $values[] = date(DATE_ATOM);
        $values[] = (int)$publication['id'];
        $values[] = $targetUserId;
        $updatePublication->execute($values);
        $record($stats, 'updated', 'publications');
    }

    $settingsStmt = $source->prepare('SELECT enabled,source_locale,publication_locale,created_at,updated_at FROM bilingual_editorial_settings WHERE user_id=? LIMIT 1');
    $settingsStmt->execute([$sourceUserId]);
    $settings = $settingsStmt->fetch();
    if (is_array($settings)) {
        $target->prepare(
            'INSERT INTO bilingual_editorial_settings
             (user_id,enabled,source_locale,publication_locale,created_at,updated_at)
             VALUES (?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
             enabled=VALUES(enabled),
             source_locale=VALUES(source_locale),
             publication_locale=VALUES(publication_locale),
             updated_at=VALUES(updated_at)'
        )->execute([
            $targetUserId,
            (int)$settings['enabled'],
            $settings['source_locale'],
            $settings['publication_locale'],
            $settings['created_at'],
            date(DATE_ATOM),
        ]);
        $record($stats, 'updated', 'bilingual_editorial_settings');
    }

    $contentStmt = $source->prepare(
        'SELECT entity_type,entity_id,locale,content_json,private_memo,status,source_hash,created_at,updated_at,
                is_published,published_content_json,published_at
         FROM bilingual_editorial_content
         WHERE user_id=?
         ORDER BY entity_type,entity_id,locale'
    );
    $contentStmt->execute([$sourceUserId]);
    $entityChecks = [
        'series' => $target->prepare('SELECT COUNT(*) FROM artwork_series WHERE id=? AND user_id=?'),
        'artwork' => $target->prepare('SELECT COUNT(*) FROM artworks WHERE id=? AND user_id=?'),
        'mockup' => $target->prepare('SELECT COUNT(*) FROM mockups WHERE id=? AND user_id=?'),
    ];
    $upsertContent = $target->prepare(
        'INSERT INTO bilingual_editorial_content
         (user_id,entity_type,entity_id,locale,content_json,private_memo,status,source_hash,created_at,updated_at,
          is_published,published_content_json,published_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
         content_json=VALUES(content_json),
         private_memo=VALUES(private_memo),
         status=VALUES(status),
         source_hash=VALUES(source_hash),
         updated_at=VALUES(updated_at),
         is_published=VALUES(is_published),
         published_content_json=VALUES(published_content_json),
         published_at=VALUES(published_at)'
    );
    foreach ($contentStmt->fetchAll() as $content) {
        $entityType = (string)$content['entity_type'];
        if (!isset($entityChecks[$entityType])) {
            throw new RuntimeException("Unsupported bilingual entity type: {$entityType}");
        }
        $entityChecks[$entityType]->execute([(int)$content['entity_id'], $targetUserId]);
        if ((int)$entityChecks[$entityType]->fetchColumn() !== 1) {
            throw new RuntimeException("Target entity is missing: {$entityType} #{$content['entity_id']}");
        }
        $upsertContent->execute([
            $targetUserId,
            $entityType,
            (int)$content['entity_id'],
            $content['locale'],
            $content['content_json'],
            $content['private_memo'],
            $content['status'],
            $content['source_hash'],
            $content['created_at'],
            date(DATE_ATOM),
            (int)$content['is_published'],
            $content['published_content_json'],
            $content['published_at'],
        ]);
        $record($stats, 'updated', 'bilingual_editorial_content');
    }

    $sourceItems = $source->prepare(
        'SELECT i.publication_id,i.mockup_sheet_id,i.position,i.role,i.title,i.alt_text,i.caption
         FROM publication_items i
         JOIN publications p ON p.id=i.publication_id
         WHERE p.user_id=?
         ORDER BY i.publication_id,i.position,i.id'
    );
    $sourceItems->execute([$sourceUserId]);
    $targetPublicationExists = $target->prepare('SELECT COUNT(*) FROM publications WHERE id=? AND user_id=?');
    $targetItem = $target->prepare(
        'SELECT id,title,alt_text,caption,role
         FROM publication_items
         WHERE publication_id=? AND mockup_sheet_id=? AND position=?
         ORDER BY id LIMIT 1'
    );
    $updateItem = $target->prepare('UPDATE publication_items SET role=?,title=?,alt_text=?,caption=? WHERE id=?');
    $insertItem = $target->prepare(
        'INSERT INTO publication_items (publication_id,mockup_sheet_id,position,role,title,alt_text,caption)
         VALUES (?,?,?,?,?,?,?)'
    );
    foreach ($sourceItems->fetchAll() as $item) {
        $targetPublicationExists->execute([(int)$item['publication_id'], $targetUserId]);
        if ((int)$targetPublicationExists->fetchColumn() !== 1) {
            $record($stats, 'missing_target', 'publication_items');
            continue;
        }
        $targetItem->execute([
            (int)$item['publication_id'],
            (int)$item['mockup_sheet_id'],
            (int)$item['position'],
        ]);
        $existingItem = $targetItem->fetch();
        if (!is_array($existingItem)) {
            $insertItem->execute([
                (int)$item['publication_id'],
                (int)$item['mockup_sheet_id'],
                (int)$item['position'],
                $item['role'],
                $item['title'],
                $item['alt_text'],
                $item['caption'],
            ]);
            $record($stats, 'inserted', 'publication_items');
            continue;
        }
        if (
            (string)$existingItem['role'] === (string)$item['role']
            && (string)$existingItem['title'] === (string)$item['title']
            && (string)$existingItem['alt_text'] === (string)$item['alt_text']
            && (string)$existingItem['caption'] === (string)$item['caption']
        ) {
            $record($stats, 'unchanged', 'publication_items');
            continue;
        }
        $updateItem->execute([
            $item['role'],
            $item['title'],
            $item['alt_text'],
            $item['caption'],
            (int)$existingItem['id'],
        ]);
        $record($stats, 'updated', 'publication_items');
    }

    if ($apply) {
        $target->commit();
    } else {
        $target->rollBack();
    }
} catch (Throwable $error) {
    if ($target->inTransaction()) {
        $target->rollBack();
    }
    fwrite(STDERR, "Editorial synchronization failed: {$error->getMessage()}\n");
    exit(1);
}

echo json_encode([
    'mode' => $apply ? 'applied' : 'dry-run',
    'source_database' => $sourceDatabase,
    'target_database' => $targetDatabase,
    'artist_email' => $email,
    'source_user_id' => $sourceUserId,
    'target_user_id' => $targetUserId,
    'summary' => $stats,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;

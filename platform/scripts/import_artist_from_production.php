<?php
declare(strict_types=1);

/**
 * Import (production -> local) todo el catalogo de un artista.
 *
 * Espejo inverso de sync_artist_editorial_to_production.php: ese script
 * escribe en produccion desde local; este SOLO LEE de produccion y SOLO
 * ESCRIBE en local. Nunca al reves.
 *
 * Por defecto corre en modo simulacion (no escribe nada). Solo con --apply
 * escribe de verdad en la base local.
 *
 * Uso:
 *   php scripts/import_artist_from_production.php --user-email=mauriziovalch@gmail.com \
 *       --source-host=127.0.0.1 --source-port=3307 [--apply]
 *
 * La contrasena de origen se lee de la variable de entorno
 * PRODUCTION_IMPORT_SOURCE_PASSWORD (nunca como argumento de linea de comandos).
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

$options = getopt('', [
    'user-email:',
    'source-host:',
    'source-port::',
    'source-database::',
    'source-username::',
    'apply',
]);

$email = strtolower(trim((string)($options['user-email'] ?? '')));
$sourceHost = trim((string)($options['source-host'] ?? ''));
$sourcePort = max(1, (int)($options['source-port'] ?? 3307));
$sourceDatabase = trim((string)($options['source-database'] ?? 'mockups'));
$sourceUsername = trim((string)($options['source-username'] ?? 'mockups_app'));
$sourcePassword = (string)(getenv('PRODUCTION_IMPORT_SOURCE_PASSWORD') ?: '');
$apply = array_key_exists('apply', $options);

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "A valid --user-email is required.\n");
    exit(1);
}
if ($sourceHost === '' || $sourcePassword === '') {
    fwrite(STDERR, "Source host and PRODUCTION_IMPORT_SOURCE_PASSWORD are required.\n");
    exit(1);
}
if (app_env('APP_ENV', '') !== 'local') {
    fwrite(STDERR, "Safety stop: this script only runs with APP_ENV=local.\n");
    exit(1);
}

$target = Database::connection();
$targetDatabase = (string)$target->query('SELECT DATABASE()')->fetchColumn();
if (!str_contains(strtolower($targetDatabase), 'local')) {
    fwrite(STDERR, "Safety stop: target database is not a local database ({$targetDatabase}).\n");
    exit(1);
}

$source = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $sourceHost, $sourcePort, $sourceDatabase),
    $sourceUsername,
    $sourcePassword,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);
// Defensive belt: this connection must never be used to write. Every call
// below goes through readOnlyQuery(), which only accepts SELECT statements.
function readOnlyQuery(PDO $pdo, string $sql, array $params = []): array
{
    if (!preg_match('/^\s*SELECT\b/i', $sql)) {
        throw new RuntimeException('Refusing non-SELECT statement on the read-only production connection.');
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

$findUser = static function (PDO $pdo, string $userEmail, bool $readOnly): array {
    $sql = 'SELECT id,email,name,status FROM users WHERE LOWER(email)=? LIMIT 1';
    if ($readOnly) {
        return readOnlyQuery($pdo, $sql, [$userEmail])[0] ?? [];
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userEmail]);
    return $stmt->fetch() ?: [];
};

$sourceUser = $findUser($source, $email, true);
$targetUser = $findUser($target, $email, false);
if ($sourceUser === []) {
    fwrite(STDERR, "Safety stop: the artist was not found in the production database.\n");
    exit(1);
}
if ($targetUser === []) {
    fwrite(STDERR, "Safety stop: the artist must already exist locally (register/create the account first).\n");
    exit(1);
}

$sourceUserId = (int)$sourceUser['id'];
$targetUserId = (int)$targetUser['id'];

$stats = ['inserted' => [], 'updated' => [], 'unchanged' => []];
$record = static function (array &$summary, string $bucket, string $table): void {
    $summary[$bucket][$table] = (int)($summary[$bucket][$table] ?? 0) + 1;
};

/**
 * Generic natural-key sync: reads every source row for this user, matches it
 * against an existing target row via $naturalKeyFn, inserts what's missing
 * (capturing the new local id) or updates what changed, and returns a
 * [sourceId => targetId] map for callers that need to remap foreign keys.
 *
 * @param callable(array):string $naturalKeyFn Builds a matching key from a row.
 * @param array<string,mixed> $extraTargetValues Constant values forced on insert/update (e.g. remapped FKs).
 * @return array<int,int>
 */
function syncTable(
    PDO $source,
    PDO $target,
    string $table,
    int $sourceUserId,
    int $targetUserId,
    array $fields,
    callable $naturalKeyFn,
    array &$stats,
    callable $record,
    bool $apply,
    array $extraTargetValues = []
): array {
    $sourceRows = readOnlyQuery(
        $source,
        "SELECT id," . implode(',', $fields) . " FROM {$table} WHERE user_id=? ORDER BY id",
        [$sourceUserId]
    );

    $targetRows = $target->prepare("SELECT id," . implode(',', $fields) . " FROM {$table} WHERE user_id=?");
    $targetRows->execute([$targetUserId]);
    $targetByKey = [];
    foreach ($targetRows->fetchAll() as $row) {
        $targetByKey[$naturalKeyFn($row)] = $row;
    }

    $idMap = [];
    $insertColumns = array_merge(['user_id'], $fields, array_keys($extraTargetValues));
    $placeholders = implode(',', array_fill(0, count($insertColumns), '?'));
    $insertStmt = $target->prepare("INSERT INTO {$table} (" . implode(',', $insertColumns) . ") VALUES ({$placeholders})");
    $updateAssignments = implode(',', array_map(static fn(string $f): string => "{$f}=?", $fields));
    $updateStmt = $target->prepare("UPDATE {$table} SET {$updateAssignments} WHERE id=?");

    foreach ($sourceRows as $row) {
        $key = $naturalKeyFn($row);
        if ($key === '') {
            continue;
        }
        $existing = $targetByKey[$key] ?? null;
        if ($existing === null) {
            $idMap[(int)$row['id']] = 0; // resolved below once inserted (or left at 0 in dry-run)
            if ($apply) {
                $values = [$targetUserId];
                foreach ($fields as $f) {
                    $values[] = $row[$f] ?? null;
                }
                foreach ($extraTargetValues as $v) {
                    $values[] = $v;
                }
                $insertStmt->execute($values);
                $idMap[(int)$row['id']] = (int)$target->lastInsertId();
            }
            $record($stats, 'inserted', $table);
            continue;
        }

        $idMap[(int)$row['id']] = (int)$existing['id'];
        $changed = false;
        foreach ($fields as $f) {
            if ((string)($row[$f] ?? '') !== (string)($existing[$f] ?? '')) {
                $changed = true;
                break;
            }
        }
        if (!$changed) {
            $record($stats, 'unchanged', $table);
            continue;
        }
        if ($apply) {
            $values = [];
            foreach ($fields as $f) {
                $values[] = $row[$f] ?? null;
            }
            $values[] = (int)$existing['id'];
            $updateStmt->execute($values);
        }
        $record($stats, 'updated', $table);
    }

    return $idMap;
}

echo "Mode: " . ($apply ? 'APPLY (writing to local)' : 'DRY RUN (no writes)') . "\n";
echo "Source: {$sourceDatabase}@{$sourceHost}:{$sourcePort}  Target: {$targetDatabase}\n";
echo "Artist: {$email}  (production id {$sourceUserId} -> local id {$targetUserId})\n\n";

try {
    if ($apply) {
        $target->beginTransaction();
    }

    // --- artist_profiles (1:1, upsert) ---
    $profileFields = [
        'artist_name', 'short_bio', 'statement', 'visual_language', 'materials',
        'recurring_themes', 'palette_notes', 'target_audience', 'preferred_regions',
        'preferred_contexts', 'forbidden_contexts', 'commercial_positioning',
        'conceptual_keywords', 'tone_of_voice', 'marketplace_strategy',
        'social_strategy', 'pinterest_strategy', 'photo_file',
    ];
    $sourceProfile = readOnlyQuery($source, 'SELECT ' . implode(',', $profileFields) . ' FROM artist_profiles WHERE user_id=? LIMIT 1', [$sourceUserId])[0] ?? null;
    if ($sourceProfile) {
        if ($apply) {
            $assignments = implode(',', array_map(static fn(string $f): string => "{$f}=?", $profileFields));
            $values = array_map(static fn(string $f): mixed => $sourceProfile[$f] ?? null, $profileFields);
            $values[] = $targetUserId;
            $target->prepare("UPDATE artist_profiles SET {$assignments} WHERE user_id=?")->execute($values);
        }
        $record($stats, 'updated', 'artist_profiles');
    }

    // --- artwork_series (match by slug) ---
    $seriesFields = [
        'title', 'slug', 'description', 'status', 'subtitle', 'long_description',
        'keywords', 'tags', 'seo_description', 'year_start', 'year_end', 'header_file',
        'published', 'header_focal_x', 'header_focal_y', 'header_zoom', 'display_order',
        'conceptual_core', 'interpretive_limits', 'created_at', 'updated_at',
    ];
    $seriesMap = syncTable(
        $source, $target, 'artwork_series', $sourceUserId, $targetUserId, $seriesFields,
        static fn(array $r): string => strtolower(trim((string)($r['slug'] ?? ''))),
        $stats, $record, $apply
    );

    // --- artworks (match by job_id, unique) ---
    $artworkFields = [
        'job_id', 'root_file', 'main_file', 'final_title', 'subtitle', 'medium',
        'artwork_year', 'series', 'status', 'width', 'height', 'depth', 'unit',
        'root_view_type', 'root_view_status', 'series_creation_number', 'created_at', 'updated_at',
    ];
    // series_id / artwork_group_id / reference_set_id are remapped separately below,
    // so they are excluded from the generic field sync and left untouched by syncTable.
    $artworkMap = syncTable(
        $source, $target, 'artworks', $sourceUserId, $targetUserId, $artworkFields,
        static fn(array $r): string => trim((string)($r['job_id'] ?? '')),
        $stats, $record, $apply
    );

    // Backfill remapped foreign keys (series_id) on artworks now that both maps exist.
    if ($apply) {
        $sourceArtworkSeries = readOnlyQuery($source, 'SELECT id,series_id FROM artworks WHERE user_id=? AND series_id IS NOT NULL AND series_id>0', [$sourceUserId]);
        $updateArtworkSeries = $target->prepare('UPDATE artworks SET series_id=? WHERE id=? AND user_id=?');
        foreach ($sourceArtworkSeries as $row) {
            $localArtworkId = $artworkMap[(int)$row['id']] ?? 0;
            $localSeriesId = $seriesMap[(int)$row['series_id']] ?? 0;
            if ($localArtworkId > 0 && $localSeriesId > 0) {
                $updateArtworkSeries->execute([$localSeriesId, $localArtworkId, $targetUserId]);
            }
        }
    }

    // --- artwork_sheets (match by canonical_artwork_id, remapped) ---
    $sheetFields = [
        'related_artwork_ids', 'source_image_file', 'user_notes', 'title', 'subtitle', 'description',
        'short_description', 'keywords', 'tags', 'alt_text', 'caption', 'status',
        'generated_json', 'created_at', 'updated_at',
    ];
    $sourceSheets = readOnlyQuery($source, 'SELECT id,canonical_artwork_id,' . implode(',', $sheetFields) . ' FROM artwork_sheets WHERE user_id=? ORDER BY id', [$sourceUserId]);
    $targetSheetsStmt = $target->prepare('SELECT id,canonical_artwork_id,' . implode(',', $sheetFields) . ' FROM artwork_sheets WHERE user_id=?');
    $targetSheetsStmt->execute([$targetUserId]);
    $targetSheetsByArtwork = [];
    foreach ($targetSheetsStmt->fetchAll() as $row) {
        $targetSheetsByArtwork[(int)$row['canonical_artwork_id']] = $row;
    }
    $sheetMap = [];
    $insertSheet = $target->prepare('INSERT INTO artwork_sheets (user_id,canonical_artwork_id,' . implode(',', $sheetFields) . ') VALUES (?,?,' . implode(',', array_fill(0, count($sheetFields), '?')) . ')');
    $updateSheet = $target->prepare('UPDATE artwork_sheets SET ' . implode(',', array_map(static fn(string $f) => "{$f}=?", $sheetFields)) . ' WHERE id=?');
    foreach ($sourceSheets as $row) {
        $localArtworkId = $artworkMap[(int)$row['canonical_artwork_id']] ?? 0;
        if ($localArtworkId <= 0) {
            continue;
        }
        $existing = $targetSheetsByArtwork[$localArtworkId] ?? null;
        if ($existing === null) {
            $sheetMap[(int)$row['id']] = 0;
            if ($apply) {
                $values = [$targetUserId, $localArtworkId];
                foreach ($sheetFields as $f) { $values[] = $row[$f] ?? null; }
                $insertSheet->execute($values);
                $sheetMap[(int)$row['id']] = (int)$target->lastInsertId();
            }
            $record($stats, 'inserted', 'artwork_sheets');
            continue;
        }
        $sheetMap[(int)$row['id']] = (int)$existing['id'];
        $changed = false;
        foreach ($sheetFields as $f) {
            if ((string)($row[$f] ?? '') !== (string)($existing[$f] ?? '')) { $changed = true; break; }
        }
        if (!$changed) { $record($stats, 'unchanged', 'artwork_sheets'); continue; }
        if ($apply) {
            $values = [];
            foreach ($sheetFields as $f) { $values[] = $row[$f] ?? null; }
            $values[] = (int)$existing['id'];
            $updateSheet->execute($values);
        }
        $record($stats, 'updated', 'artwork_sheets');
    }

    // --- artwork_groups (match by canonical_artwork_id, remapped) ---
    $sourceGroups = readOnlyQuery($source, 'SELECT id,canonical_artwork_id,title,status,official_root_artwork_ids FROM artwork_groups WHERE user_id=?', [$sourceUserId]);
    $targetGroupsStmt = $target->prepare('SELECT id,canonical_artwork_id,title,status FROM artwork_groups WHERE user_id=?');
    $targetGroupsStmt->execute([$targetUserId]);
    $targetGroupsByArtwork = [];
    foreach ($targetGroupsStmt->fetchAll() as $row) {
        $targetGroupsByArtwork[(int)$row['canonical_artwork_id']] = $row;
    }
    $groupMap = [];
    $insertGroup = $target->prepare('INSERT INTO artwork_groups (user_id,canonical_artwork_id,title,status,official_root_artwork_ids,created_at,updated_at) VALUES (?,?,?,?,?,?,?)');
    $updateGroup = $target->prepare('UPDATE artwork_groups SET title=?,status=?,updated_at=? WHERE id=?');
    foreach ($sourceGroups as $row) {
        $localArtworkId = $artworkMap[(int)$row['canonical_artwork_id']] ?? 0;
        if ($localArtworkId <= 0) { continue; }
        $existing = $targetGroupsByArtwork[$localArtworkId] ?? null;
        if ($existing === null) {
            $groupMap[(int)$row['id']] = 0;
            if ($apply) {
                $now = date(DATE_ATOM);
                $insertGroup->execute([$targetUserId, $localArtworkId, $row['title'], $row['status'], $row['official_root_artwork_ids'] ?? '[]', $now, $now]);
                $groupMap[(int)$row['id']] = (int)$target->lastInsertId();
            }
            $record($stats, 'inserted', 'artwork_groups');
            continue;
        }
        $groupMap[(int)$row['id']] = (int)$existing['id'];
        if ((string)$row['title'] === (string)$existing['title'] && (string)$row['status'] === (string)$existing['status']) {
            $record($stats, 'unchanged', 'artwork_groups');
            continue;
        }
        if ($apply) {
            $updateGroup->execute([$row['title'], $row['status'], date(DATE_ATOM), (int)$existing['id']]);
        }
        $record($stats, 'updated', 'artwork_groups');
    }
    if ($apply) {
        $updateArtworkGroup = $target->prepare('UPDATE artworks SET artwork_group_id=? WHERE id=? AND user_id=?');
        $sourceArtworksGroup = readOnlyQuery($source, 'SELECT id,artwork_group_id FROM artworks WHERE user_id=? AND artwork_group_id IS NOT NULL AND artwork_group_id>0', [$sourceUserId]);
        foreach ($sourceArtworksGroup as $row) {
            $localArtworkId = $artworkMap[(int)$row['id']] ?? 0;
            $localGroupId = $groupMap[(int)$row['artwork_group_id']] ?? 0;
            if ($localArtworkId > 0 && $localGroupId > 0) {
                $updateArtworkGroup->execute([$localGroupId, $localArtworkId, $targetUserId]);
            }
        }
    }

    // --- root_artwork_candidates (match by artwork_id + file_name) ---
    $sourceCandidates = readOnlyQuery($source, 'SELECT rac.id,rac.artwork_id,rac.file_name,rac.view_type,rac.is_selected,rac.created_at FROM root_artwork_candidates rac INNER JOIN artworks a ON a.id=rac.artwork_id WHERE a.user_id=?', [$sourceUserId]);
    $targetCandidatesStmt = $target->prepare('SELECT rac.id,rac.artwork_id,rac.file_name FROM root_artwork_candidates rac INNER JOIN artworks a ON a.id=rac.artwork_id WHERE a.user_id=?');
    $targetCandidatesStmt->execute([$targetUserId]);
    $targetCandidatesByKey = [];
    foreach ($targetCandidatesStmt->fetchAll() as $row) {
        $targetCandidatesByKey[(int)$row['artwork_id'] . '|' . $row['file_name']] = $row;
    }
    $insertCandidate = $target->prepare('INSERT INTO root_artwork_candidates (artwork_id,file_name,view_type,is_selected,created_at) VALUES (?,?,?,?,?)');
    foreach ($sourceCandidates as $row) {
        $localArtworkId = $artworkMap[(int)$row['artwork_id']] ?? 0;
        if ($localArtworkId <= 0) { continue; }
        $key = $localArtworkId . '|' . $row['file_name'];
        if (isset($targetCandidatesByKey[$key])) {
            $record($stats, 'unchanged', 'root_artwork_candidates');
            continue;
        }
        if ($apply) {
            $insertCandidate->execute([$localArtworkId, $row['file_name'], $row['view_type'], (int)$row['is_selected'], $row['created_at']]);
        }
        $record($stats, 'inserted', 'root_artwork_candidates');
    }

    // --- mockups (match by mockup_file) ---
    $mockupFields = [
        'artwork_file', 'mockup_file', 'context_id', 'prompt_file',
        'selector_state_json', 'series_creation_number', 'created_at',
    ];
    $mockupMap = syncTable(
        $source, $target, 'mockups', $sourceUserId, $targetUserId,
        ['artwork_file', 'mockup_file', 'context_id', 'prompt_file', 'selector_state_json', 'created_at'],
        static fn(array $r): string => trim((string)($r['mockup_file'] ?? '')),
        $stats, $record, $apply
    );
    if ($apply) {
        $updateMockupFks = $target->prepare('UPDATE mockups SET source_artwork_id=?,series_id=?,artwork_group_id=? WHERE id=? AND user_id=?');
        $sourceMockupFks = readOnlyQuery($source, 'SELECT id,source_artwork_id,series_id,artwork_group_id FROM mockups WHERE user_id=?', [$sourceUserId]);
        foreach ($sourceMockupFks as $row) {
            $localMockupId = $mockupMap[(int)$row['id']] ?? 0;
            if ($localMockupId <= 0) { continue; }
            $localSourceArtworkId = $artworkMap[(int)($row['source_artwork_id'] ?? 0)] ?? null;
            $localSeriesId = $seriesMap[(int)($row['series_id'] ?? 0)] ?? null;
            $localGroupId = $groupMap[(int)($row['artwork_group_id'] ?? 0)] ?? null;
            $updateMockupFks->execute([$localSourceArtworkId ?: null, $localSeriesId ?: null, $localGroupId ?: null, $localMockupId, $targetUserId]);
        }
    }

    // --- mockup_sheets (match by mockup_id, remapped) ---
    $mockupSheetFields = ['user_notes', 'title', 'description', 'keywords', 'tags', 'alt_text', 'caption', 'status', 'generated_json', 'created_at', 'updated_at'];
    $sourceMockupSheets = readOnlyQuery($source, 'SELECT id,artwork_sheet_id,artwork_id,mockup_id,mockup_file,' . implode(',', $mockupSheetFields) . ' FROM mockup_sheets WHERE user_id=?', [$sourceUserId]);
    $targetMockupSheetsStmt = $target->prepare('SELECT id,mockup_id,' . implode(',', $mockupSheetFields) . ' FROM mockup_sheets WHERE user_id=?');
    $targetMockupSheetsStmt->execute([$targetUserId]);
    $targetMockupSheetsByMockup = [];
    foreach ($targetMockupSheetsStmt->fetchAll() as $row) {
        $targetMockupSheetsByMockup[(int)$row['mockup_id']] = $row;
    }
    $insertMockupSheet = $target->prepare('INSERT INTO mockup_sheets (user_id,artwork_sheet_id,artwork_id,artwork_group_id,mockup_id,mockup_file,' . implode(',', $mockupSheetFields) . ') VALUES (?,?,?,?,?,?,' . implode(',', array_fill(0, count($mockupSheetFields), '?')) . ')');
    $updateMockupSheet = $target->prepare('UPDATE mockup_sheets SET ' . implode(',', array_map(static fn(string $f) => "{$f}=?", $mockupSheetFields)) . ' WHERE id=?');
    foreach ($sourceMockupSheets as $row) {
        $localMockupId = $mockupMap[(int)$row['mockup_id']] ?? 0;
        if ($localMockupId <= 0) { continue; }
        $existing = $targetMockupSheetsByMockup[$localMockupId] ?? null;
        if ($existing === null) {
            if ($apply) {
                $localSheetId = $sheetMap[(int)($row['artwork_sheet_id'] ?? 0)] ?? null;
                $localArtworkId = $artworkMap[(int)($row['artwork_id'] ?? 0)] ?? null;
                $localGroupId = $groupMap[(int)($row['artwork_group_id'] ?? 0)] ?? null;
                $values = [$targetUserId, $localSheetId ?: null, $localArtworkId ?: null, $localGroupId ?: null, $localMockupId, $row['mockup_file']];
                foreach ($mockupSheetFields as $f) { $values[] = $row[$f] ?? null; }
                $insertMockupSheet->execute($values);
            }
            $record($stats, 'inserted', 'mockup_sheets');
            continue;
        }
        $changed = false;
        foreach ($mockupSheetFields as $f) {
            if ((string)($row[$f] ?? '') !== (string)($existing[$f] ?? '')) { $changed = true; break; }
        }
        if (!$changed) { $record($stats, 'unchanged', 'mockup_sheets'); continue; }
        if ($apply) {
            $values = [];
            foreach ($mockupSheetFields as $f) { $values[] = $row[$f] ?? null; }
            $values[] = (int)$existing['id'];
            $updateMockupSheet->execute($values);
        }
        $record($stats, 'updated', 'mockup_sheets');
    }

    // --- publications (match by artwork_sheet_id, remapped) ---
    $pubFields = ['slug', 'title', 'description', 'short_description', 'language', 'objective', 'cta_label', 'cta_url', 'visibility', 'status', 'profile_snapshot_json', 'metadata_snapshot_json', 'published_at', 'created_at', 'updated_at', 'header_file', 'display_order', 'content_source'];
    $sourcePubs = readOnlyQuery($source, 'SELECT id,artwork_sheet_id,' . implode(',', $pubFields) . ' FROM publications WHERE user_id=?', [$sourceUserId]);
    $targetPubsStmt = $target->prepare('SELECT id,artwork_sheet_id,' . implode(',', $pubFields) . ' FROM publications WHERE user_id=?');
    $targetPubsStmt->execute([$targetUserId]);
    $targetPubsBySheet = [];
    foreach ($targetPubsStmt->fetchAll() as $row) {
        $targetPubsBySheet[(int)$row['artwork_sheet_id']] = $row;
    }
    $pubMap = [];
    $insertPub = $target->prepare('INSERT INTO publications (user_id,artwork_sheet_id,' . implode(',', $pubFields) . ') VALUES (?,?,' . implode(',', array_fill(0, count($pubFields), '?')) . ')');
    $updatePub = $target->prepare('UPDATE publications SET ' . implode(',', array_map(static fn(string $f) => "{$f}=?", $pubFields)) . ' WHERE id=?');
    foreach ($sourcePubs as $row) {
        $localSheetId = $sheetMap[(int)($row['artwork_sheet_id'] ?? 0)] ?? 0;
        if ($localSheetId <= 0) { continue; }
        $existing = $targetPubsBySheet[$localSheetId] ?? null;
        if ($existing === null) {
            $pubMap[(int)$row['id']] = 0;
            if ($apply) {
                $values = [$targetUserId, $localSheetId];
                foreach ($pubFields as $f) { $values[] = $row[$f] ?? null; }
                $insertPub->execute($values);
                $pubMap[(int)$row['id']] = (int)$target->lastInsertId();
            }
            $record($stats, 'inserted', 'publications');
            continue;
        }
        $pubMap[(int)$row['id']] = (int)$existing['id'];
        $changed = false;
        foreach ($pubFields as $f) {
            if ((string)($row[$f] ?? '') !== (string)($existing[$f] ?? '')) { $changed = true; break; }
        }
        if (!$changed) { $record($stats, 'unchanged', 'publications'); continue; }
        if ($apply) {
            $values = [];
            foreach ($pubFields as $f) { $values[] = $row[$f] ?? null; }
            $values[] = (int)$existing['id'];
            $updatePub->execute($values);
        }
        $record($stats, 'updated', 'publications');
    }

    // --- publication_items (match by publication_id + mockup_sheet_id + position) ---
    $sourceItems = readOnlyQuery(
        $source,
        'SELECT i.publication_id,i.mockup_sheet_id,i.position,i.role,i.title,i.alt_text,i.caption
         FROM publication_items i JOIN publications p ON p.id=i.publication_id WHERE p.user_id=?',
        [$sourceUserId]
    );
    // Build a mockup_sheet_id map (source id -> local id) from what we just synced.
    $mockupSheetMapStmt = $target->prepare('SELECT ms.id,ms.mockup_id FROM mockup_sheets ms WHERE ms.user_id=?');
    $mockupSheetMapStmt->execute([$targetUserId]);
    $localMockupSheetByMockupId = [];
    foreach ($mockupSheetMapStmt->fetchAll() as $row) {
        $localMockupSheetByMockupId[(int)$row['mockup_id']] = (int)$row['id'];
    }
    $sourceMockupSheetToMockup = [];
    foreach (readOnlyQuery($source, 'SELECT id,mockup_id FROM mockup_sheets WHERE user_id=?', [$sourceUserId]) as $row) {
        $sourceMockupSheetToMockup[(int)$row['id']] = (int)$row['mockup_id'];
    }
    $insertItem = $target->prepare('INSERT INTO publication_items (publication_id,mockup_sheet_id,position,role,title,alt_text,caption) VALUES (?,?,?,?,?,?,?)');
    $updateItem = $target->prepare('UPDATE publication_items SET role=?,title=?,alt_text=?,caption=? WHERE id=?');
    $targetItemStmtCache = $target->prepare('SELECT id,role,title,alt_text,caption FROM publication_items WHERE publication_id=? AND mockup_sheet_id=? AND position=? LIMIT 1');
    foreach ($sourceItems as $row) {
        $localPubId = $pubMap[(int)$row['publication_id']] ?? 0;
        $sourceMockupId = $sourceMockupSheetToMockup[(int)$row['mockup_sheet_id']] ?? 0;
        $localMockupId = $mockupMap[$sourceMockupId] ?? 0;
        $localMockupSheetId = $localMockupSheetByMockupId[$localMockupId] ?? 0;
        if ($localPubId <= 0 || $localMockupSheetId <= 0) { continue; }
        $targetItemStmtCache->execute([$localPubId, $localMockupSheetId, (int)$row['position']]);
        $existing = $targetItemStmtCache->fetch();
        if (!is_array($existing)) {
            if ($apply) {
                $insertItem->execute([$localPubId, $localMockupSheetId, (int)$row['position'], $row['role'], $row['title'], $row['alt_text'], $row['caption']]);
            }
            $record($stats, 'inserted', 'publication_items');
            continue;
        }
        if ((string)$existing['role'] === (string)$row['role'] && (string)$existing['title'] === (string)$row['title']
            && (string)$existing['alt_text'] === (string)$row['alt_text'] && (string)$existing['caption'] === (string)$row['caption']) {
            $record($stats, 'unchanged', 'publication_items');
            continue;
        }
        if ($apply) {
            $updateItem->execute([$row['role'], $row['title'], $row['alt_text'], $row['caption'], (int)$existing['id']]);
        }
        $record($stats, 'updated', 'publication_items');
    }

    // --- social_campaigns (Studio Notes; match by editorial_sync_key, legacy hash fallback) ---
    $noteFields = ['campaign_type', 'title', 'objective', 'source_type', 'source_id', 'source_label', 'status', 'payload_json', 'created_at'];
    $sourceNotes = readOnlyQuery($source, "SELECT id," . implode(',', $noteFields) . " FROM social_campaigns WHERE user_id=? AND campaign_type='website_blog' ORDER BY id", [$sourceUserId]);
    $targetNotesStmt = $target->prepare("SELECT id," . implode(',', $noteFields) . " FROM social_campaigns WHERE user_id=? AND campaign_type='website_blog'");
    $targetNotesStmt->execute([$targetUserId]);
    $targetNotesBySyncKey = [];
    $targetNotesByLegacyIdentity = [];
    foreach ($targetNotesStmt->fetchAll() as $row) {
        $payload = json_decode((string)$row['payload_json'], true);
        $syncKey = is_array($payload) ? trim((string)($payload['editorial_sync_key'] ?? '')) : '';
        if ($syncKey !== '') { $targetNotesBySyncKey[$syncKey] = $row; }
        $legacy = hash('sha256', implode('|', [(string)$row['title'], (string)$row['source_type'], (string)$row['source_id'], (string)$row['created_at']]));
        $targetNotesByLegacyIdentity[$legacy] = $row;
    }
    $insertNote = $target->prepare('INSERT INTO social_campaigns (user_id,' . implode(',', $noteFields) . ',updated_at) VALUES (?,' . implode(',', array_fill(0, count($noteFields), '?')) . ',?)');
    $updateNote = $target->prepare('UPDATE social_campaigns SET ' . implode(',', array_map(static fn(string $f) => "{$f}=?", $noteFields)) . ',updated_at=? WHERE id=?');
    $noteMap = [];
    foreach ($sourceNotes as $row) {
        $payload = json_decode((string)$row['payload_json'], true);
        $syncKey = is_array($payload) ? trim((string)($payload['editorial_sync_key'] ?? '')) : '';
        $legacy = hash('sha256', implode('|', [(string)$row['title'], (string)$row['source_type'], (string)$row['source_id'], (string)$row['created_at']]));
        $existing = ($syncKey !== '' ? ($targetNotesBySyncKey[$syncKey] ?? null) : null) ?? ($targetNotesByLegacyIdentity[$legacy] ?? null);
        if ($existing === null) {
            $noteMap[(int)$row['id']] = 0;
            if ($apply) {
                $values = [$targetUserId];
                foreach ($noteFields as $f) { $values[] = $row[$f] ?? null; }
                $values[] = date(DATE_ATOM);
                $insertNote->execute($values);
                $noteMap[(int)$row['id']] = (int)$target->lastInsertId();
            }
            $record($stats, 'inserted', 'social_campaigns');
            continue;
        }
        $noteMap[(int)$row['id']] = (int)$existing['id'];
        $changed = false;
        foreach ($noteFields as $f) {
            if ((string)($row[$f] ?? '') !== (string)($existing[$f] ?? '')) { $changed = true; break; }
        }
        if (!$changed) { $record($stats, 'unchanged', 'social_campaigns'); continue; }
        if ($apply) {
            $values = [];
            foreach ($noteFields as $f) { $values[] = $row[$f] ?? null; }
            $values[] = date(DATE_ATOM);
            $values[] = (int)$existing['id'];
            $updateNote->execute($values);
        }
        $record($stats, 'updated', 'social_campaigns');
    }

    // --- studio_note_workspace_items (match by note_id, remapped + board_type + locale + position) ---
    $workspaceFields = ['board_type', 'locale', 'label', 'content_json', 'content_hash', 'position', 'created_at', 'updated_at'];
    $sourceWorkspace = readOnlyQuery($source, 'SELECT note_id,' . implode(',', $workspaceFields) . ' FROM studio_note_workspace_items WHERE user_id=?', [$sourceUserId]);
    $targetWorkspaceStmt = $target->prepare('SELECT id,note_id,' . implode(',', $workspaceFields) . ' FROM studio_note_workspace_items WHERE user_id=?');
    $targetWorkspaceStmt->execute([$targetUserId]);
    // The real DB uniqueness constraint is (user_id, note_id, board_type, locale,
    // content_hash, source_job_id) -- match on that, not on position.
    $targetWorkspaceByKey = [];
    foreach ($targetWorkspaceStmt->fetchAll() as $row) {
        $targetWorkspaceByKey[(int)$row['note_id'] . '|' . $row['board_type'] . '|' . $row['locale'] . '|' . $row['content_hash']] = $row;
    }
    $insertWorkspace = $target->prepare('INSERT INTO studio_note_workspace_items (user_id,note_id,' . implode(',', $workspaceFields) . ',source_job_id) VALUES (?,?,' . implode(',', array_fill(0, count($workspaceFields), '?')) . ',0)');
    foreach ($sourceWorkspace as $row) {
        $localNoteId = $noteMap[(int)$row['note_id']] ?? 0;
        if ($localNoteId <= 0) { continue; }
        $key = $localNoteId . '|' . $row['board_type'] . '|' . $row['locale'] . '|' . $row['content_hash'];
        if (isset($targetWorkspaceByKey[$key])) {
            $record($stats, 'unchanged', 'studio_note_workspace_items');
            continue;
        }
        // Mark the key as handled immediately (before executing), not just on success:
        // two different source rows can legitimately map to the same target key within
        // this same run (e.g. two production notes collapsed to one local note via
        // noteMap, both carrying identical placeholder content/content_hash). Without
        // this, the second one would attempt a duplicate INSERT and violate
        // uq_studio_note_workspace_content, since we collapse source_job_id to 0.
        $targetWorkspaceByKey[$key] = true;
        if ($apply) {
            $values = [$targetUserId, $localNoteId];
            foreach ($workspaceFields as $f) { $values[] = $row[$f] ?? null; }
            $insertWorkspace->execute($values);
        }
        $record($stats, 'inserted', 'studio_note_workspace_items');
    }

    // --- bilingual_editorial_content (match by entity_type + remapped entity_id + locale) ---
    $contentFields = ['content_json', 'private_memo', 'status', 'source_hash', 'created_at', 'updated_at', 'is_published', 'published_content_json', 'published_at'];
    $sourceContent = readOnlyQuery($source, 'SELECT entity_type,entity_id,locale,' . implode(',', $contentFields) . ' FROM bilingual_editorial_content WHERE user_id=?', [$sourceUserId]);
    $entityMaps = ['series' => $seriesMap, 'artwork' => $artworkMap, 'mockup' => $mockupMap, 'studio_note' => $noteMap];
    $targetContentStmt = $target->prepare('SELECT id FROM bilingual_editorial_content WHERE user_id=? AND entity_type=? AND entity_id=? AND locale=? LIMIT 1');
    $insertContent = $target->prepare('INSERT INTO bilingual_editorial_content (user_id,entity_type,entity_id,locale,' . implode(',', $contentFields) . ') VALUES (?,?,?,?,' . implode(',', array_fill(0, count($contentFields), '?')) . ')');
    $updateContent = $target->prepare('UPDATE bilingual_editorial_content SET ' . implode(',', array_map(static fn(string $f) => "{$f}=?", $contentFields)) . ' WHERE id=?');
    foreach ($sourceContent as $row) {
        $entityType = (string)$row['entity_type'];
        if (!isset($entityMaps[$entityType])) { continue; }
        $localEntityId = $entityMaps[$entityType][(int)$row['entity_id']] ?? 0;
        if ($localEntityId <= 0) { continue; }
        $targetContentStmt->execute([$targetUserId, $entityType, $localEntityId, $row['locale']]);
        $existingId = $targetContentStmt->fetchColumn();
        if ($existingId === false) {
            if ($apply) {
                $values = [$targetUserId, $entityType, $localEntityId, $row['locale']];
                foreach ($contentFields as $f) { $values[] = $row[$f] ?? null; }
                $insertContent->execute($values);
            }
            $record($stats, 'inserted', 'bilingual_editorial_content');
            continue;
        }
        if ($apply) {
            $values = [];
            foreach ($contentFields as $f) { $values[] = $row[$f] ?? null; }
            $values[] = (int)$existingId;
            $updateContent->execute($values);
        }
        $record($stats, 'updated', 'bilingual_editorial_content');
    }

    // --- 1:1-per-user tables: bilingual_editorial_settings, artist_site_settings ---
    // user_language_policy is intentionally NOT imported: it's a local-only
    // feature (not yet deployed to production) and Maurizio's local row
    // already carries the correct compatibility default (es / es+en).
    $oneToOneTables = [
        'bilingual_editorial_settings' => ['enabled', 'source_locale', 'publication_locale'],
        'artist_site_settings' => ['site_title', 'tagline', 'locale', 'site_status', 'contact_email', 'inquiry_intro', 'currency', 'payment_provider', 'payment_status', 'shipping_regions', 'shipping_policy', 'shipping_rates_json'],
    ];
    foreach ($oneToOneTables as $table => $tableFields) {
        $sourceRow = readOnlyQuery($source, "SELECT " . implode(',', $tableFields) . " FROM {$table} WHERE user_id=? LIMIT 1", [$sourceUserId])[0] ?? null;
        if (!$sourceRow) { continue; }
        $targetExistsStmt = $target->prepare("SELECT COUNT(*) FROM {$table} WHERE user_id=?");
        $targetExistsStmt->execute([$targetUserId]);
        $exists = (int)$targetExistsStmt->fetchColumn() > 0;
        if ($apply) {
            if ($exists) {
                $assignments = implode(',', array_map(static fn(string $f) => "{$f}=?", $tableFields));
                $values = array_map(static fn(string $f) => $sourceRow[$f] ?? null, $tableFields);
                $values[] = $targetUserId;
                $target->prepare("UPDATE {$table} SET {$assignments} WHERE user_id=?")->execute($values);
            } else {
                $now = date(DATE_ATOM);
                $columns = array_merge(['user_id'], $tableFields, ['created_at', 'updated_at']);
                $values = array_merge([$targetUserId], array_map(static fn(string $f) => $sourceRow[$f] ?? null, $tableFields), [$now, $now]);
                $target->prepare("INSERT INTO {$table} (" . implode(',', $columns) . ") VALUES (" . implode(',', array_fill(0, count($columns), '?')) . ")")->execute($values);
            }
        }
        $record($stats, $exists ? 'updated' : 'inserted', $table);
    }

    // --- artist_domains (unique per user) ---
    $domainFields = ['hostname', 'verification_token', 'status', 'verified_at', 'last_checked_at', 'last_error'];
    $sourceDomain = readOnlyQuery($source, 'SELECT ' . implode(',', $domainFields) . ' FROM artist_domains WHERE user_id=? LIMIT 1', [$sourceUserId])[0] ?? null;
    if ($sourceDomain) {
        $targetDomainExistsStmt = $target->prepare('SELECT COUNT(*) FROM artist_domains WHERE user_id=?');
        $targetDomainExistsStmt->execute([$targetUserId]);
        $exists = (int)$targetDomainExistsStmt->fetchColumn() > 0;
        if ($apply) {
            if ($exists) {
                $assignments = implode(',', array_map(static fn(string $f) => "{$f}=?", $domainFields));
                $values = array_map(static fn(string $f) => $sourceDomain[$f] ?? null, $domainFields);
                $values[] = $targetUserId;
                $target->prepare("UPDATE artist_domains SET {$assignments} WHERE user_id=?")->execute($values);
            } else {
                $now = date(DATE_ATOM);
                $target->prepare('INSERT INTO artist_domains (user_id,' . implode(',', $domainFields) . ',created_at,updated_at) VALUES (?,' . implode(',', array_fill(0, count($domainFields), '?')) . ',?,?)')
                    ->execute(array_merge([$targetUserId], array_map(static fn(string $f) => $sourceDomain[$f] ?? null, $domainFields), [$now, $now]));
            }
        }
        $record($stats, $exists ? 'updated' : 'inserted', 'artist_domains');
    }

    // --- artist_site_constellations / artist_site_print_variants (match by remapped artwork_id) ---
    foreach (['artist_site_constellations' => ['enabled', 'country', 'region', 'city', 'postal_code', 'latitude', 'longitude', 'privacy', 'public_note'],
              'artist_site_print_variants' => ['title', 'sku', 'size_label', 'support', 'finish', 'inventory_mode', 'edition_size', 'stock_on_hand', 'stock_reserved', 'price_minor', 'currency', 'status']] as $table => $tableFields) {
        $sourceRows = readOnlyQuery($source, "SELECT id,artwork_id," . implode(',', $tableFields) . " FROM {$table} WHERE user_id=?", [$sourceUserId]);
        $targetRowsStmt = $target->prepare("SELECT id,artwork_id," . implode(',', $tableFields) . " FROM {$table} WHERE user_id=?");
        $targetRowsStmt->execute([$targetUserId]);
        $targetByArtwork = [];
        foreach ($targetRowsStmt->fetchAll() as $row) { $targetByArtwork[(int)$row['artwork_id']] = $row; }
        foreach ($sourceRows as $row) {
            $localArtworkId = $artworkMap[(int)$row['artwork_id']] ?? 0;
            if ($localArtworkId <= 0) { continue; }
            $existing = $targetByArtwork[$localArtworkId] ?? null;
            if ($existing === null) {
                if ($apply) {
                    $now = date(DATE_ATOM);
                    $columns = array_merge(['user_id', 'artwork_id'], $tableFields, ['created_at', 'updated_at']);
                    $values = array_merge([$targetUserId, $localArtworkId], array_map(static fn(string $f) => $row[$f] ?? null, $tableFields), [$now, $now]);
                    $target->prepare("INSERT INTO {$table} (" . implode(',', $columns) . ") VALUES (" . implode(',', array_fill(0, count($columns), '?')) . ")")->execute($values);
                }
                $record($stats, 'inserted', $table);
                continue;
            }
            $changed = false;
            foreach ($tableFields as $f) {
                if ((string)($row[$f] ?? '') !== (string)($existing[$f] ?? '')) { $changed = true; break; }
            }
            if (!$changed) { $record($stats, 'unchanged', $table); continue; }
            if ($apply) {
                $assignments = implode(',', array_map(static fn(string $f) => "{$f}=?", $tableFields));
                $values = array_map(static fn(string $f) => $row[$f] ?? null, $tableFields);
                $values[] = date(DATE_ATOM);
                $values[] = (int)$existing['id'];
                $target->prepare("UPDATE {$table} SET {$assignments},updated_at=? WHERE id=?")->execute($values);
            }
            $record($stats, 'updated', $table);
        }
    }

    if ($apply) {
        $target->commit();
    } elseif ($target->inTransaction()) {
        $target->rollBack();
    }
} catch (Throwable $error) {
    if ($target->inTransaction()) {
        $target->rollBack();
    }
    fwrite(STDERR, "Import failed: {$error->getMessage()}\n{$error->getTraceAsString()}\n");
    exit(1);
}

echo json_encode([
    'mode' => $apply ? 'applied' : 'dry-run',
    'artist_email' => $email,
    'source_user_id' => $sourceUserId,
    'target_user_id' => $targetUserId,
    'summary' => $stats,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;

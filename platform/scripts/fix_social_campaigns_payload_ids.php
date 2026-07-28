<?php
declare(strict_types=1);

/**
 * social_campaigns.payload_json fue copiado verbatim de produccion durante
 * el import (nunca se remapeo). Algunos campos embeben IDs de PRODUCCION:
 * payload.mockup_ids[] y payload.source.id/source.key (cuando source.type
 * es "mockup"). El sitio publico del artista (AppPublishedStudioNotes::
 * mediaFiles()) usa mockup_ids como fallback para la miniatura de la nota,
 * consultando la tabla LOCAL de mockups por ese id -- que no existe o
 * apunta a un mockup local distinto. Este script remapea esos IDs de
 * produccion a los IDs locales equivalentes (match por mockup_file).
 *
 * Dry-run por defecto; --apply para escribir.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

$options = getopt('', ['user-email:', 'source-host:', 'source-port::', 'source-database::', 'source-username::', 'apply']);
$email = strtolower(trim((string)($options['user-email'] ?? '')));
$sourceHost = trim((string)($options['source-host'] ?? ''));
$sourcePort = max(1, (int)($options['source-port'] ?? 3307));
$sourceDatabase = trim((string)($options['source-database'] ?? 'mockups'));
$sourceUsername = trim((string)($options['source-username'] ?? 'mockups_app'));
$sourcePassword = (string)(getenv('PRODUCTION_IMPORT_SOURCE_PASSWORD') ?: '');
$apply = array_key_exists('apply', $options);

if ($email === '' || $sourceHost === '' || $sourcePassword === '') {
    fwrite(STDERR, "Missing required options/env.\n");
    exit(1);
}
if (app_env('APP_ENV', '') !== 'local') {
    fwrite(STDERR, "Safety stop: APP_ENV must be local.\n");
    exit(1);
}

$target = Database::connection();
$targetDbName = (string)$target->query('SELECT DATABASE()')->fetchColumn();
if (stripos($targetDbName, 'local') === false) {
    fwrite(STDERR, "Safety stop: target database '{$targetDbName}' is not local.\n");
    exit(1);
}

$source = new PDO(
    "mysql:host={$sourceHost};port={$sourcePort};dbname={$sourceDatabase};charset=utf8mb4",
    $sourceUsername,
    $sourcePassword,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$targetUserStmt = $target->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
$targetUserStmt->execute([$email]);
$targetUserId = (int)($targetUserStmt->fetchColumn() ?: 0);
$sourceUserStmt = $source->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
$sourceUserStmt->execute([$email]);
$sourceUserId = (int)($sourceUserStmt->fetchColumn() ?: 0);
if ($targetUserId <= 0 || $sourceUserId <= 0) {
    fwrite(STDERR, "User not found on one side.\n");
    exit(1);
}

// Rebuild production note id -> local note id map (by title+source_type+source_id+created_at legacy hash).
$noteMap = [];
$sourceNotes = $source->prepare("SELECT id, title, source_type, source_id, created_at FROM social_campaigns WHERE user_id = ? AND campaign_type='website_blog'");
$sourceNotes->execute([$sourceUserId]);
$targetNotes = $target->prepare("SELECT id, title, source_type, source_id, created_at FROM social_campaigns WHERE user_id = ? AND campaign_type='website_blog'");
$targetNotes->execute([$targetUserId]);
$targetNotesByLegacy = [];
foreach ($targetNotes->fetchAll() as $row) {
    $legacy = hash('sha256', implode('|', [(string)$row['title'], (string)$row['source_type'], (string)$row['source_id'], (string)$row['created_at']]));
    $targetNotesByLegacy[$legacy] = (int)$row['id'];
}
foreach ($sourceNotes->fetchAll() as $row) {
    $legacy = hash('sha256', implode('|', [(string)$row['title'], (string)$row['source_type'], (string)$row['source_id'], (string)$row['created_at']]));
    if (isset($targetNotesByLegacy[$legacy])) {
        $noteMap[(int)$row['id']] = $targetNotesByLegacy[$legacy];
    }
}
fwrite(STDERR, "Note id map built: " . count($noteMap) . " entries.\n");

// Rebuild production mockup id -> local mockup id map (by mockup_file, same as the importer).
$mockupMap = [];
$sourceMockups = $source->prepare('SELECT id, mockup_file FROM mockups WHERE user_id = ?');
$sourceMockups->execute([$sourceUserId]);
$targetMockups = $target->prepare('SELECT id, mockup_file FROM mockups WHERE user_id = ?');
$targetMockups->execute([$targetUserId]);
$targetByFile = [];
foreach ($targetMockups->fetchAll() as $row) { $targetByFile[(string)$row['mockup_file']] = (int)$row['id']; }
foreach ($sourceMockups->fetchAll() as $row) {
    if (isset($targetByFile[(string)$row['mockup_file']])) {
        $mockupMap[(int)$row['id']] = $targetByFile[(string)$row['mockup_file']];
    }
}
fwrite(STDERR, "Mockup id map built: " . count($mockupMap) . " entries.\n");

$rows = $target->query("SELECT id, payload_json FROM social_campaigns WHERE user_id={$targetUserId}")->fetchAll();
$update = $target->prepare('UPDATE social_campaigns SET payload_json=? WHERE id=?');
$changedCount = 0;
$unmappedIds = [];
foreach ($rows as $row) {
    $payload = json_decode((string)$row['payload_json'], true);
    if (!is_array($payload)) { continue; }
    $changed = false;

    if (isset($payload['mockup_ids']) && is_array($payload['mockup_ids'])) {
        $localMockupIds = array_flip($mockupMap); // local id -> production id (idempotency: a local id already looks like this)
        $newIds = [];
        foreach ($payload['mockup_ids'] as $id) {
            $id = (int)$id;
            if (isset($mockupMap[$id])) {
                if ($mockupMap[$id] !== $id) { $changed = true; }
                $newIds[] = $mockupMap[$id];
            } elseif (isset($localMockupIds[$id])) {
                // Already a valid local id (e.g. a prior run already remapped it) -- leave as-is.
                $newIds[] = $id;
            } else {
                $unmappedIds[] = $id;
                // Genuinely unresolvable (not a known production id, not a known local id) -- drop it
                // rather than leave a dangling reference.
                $changed = true;
            }
        }
        $payload['mockup_ids'] = $newIds;
    }

    if (isset($payload['source']) && is_array($payload['source'])) {
        $srcType = (string)($payload['source']['type'] ?? '');
        if ($srcType === 'mockup') {
            $srcId = (int)($payload['source']['id'] ?? 0);
            if ($srcId > 0 && isset($mockupMap[$srcId]) && $mockupMap[$srcId] !== $srcId) {
                $payload['source']['id'] = $mockupMap[$srcId];
                $payload['source']['key'] = 'mockup:' . $mockupMap[$srcId];
                $changed = true;
            }
        } elseif ($srcType === 'studio_note') {
            if (preg_match('/^studio_note:(\d+):(.+)$/', (string)($payload['source']['key'] ?? ''), $km)) {
                $prodNoteId = (int)$km[1];
                if (isset($noteMap[$prodNoteId]) && $noteMap[$prodNoteId] !== $prodNoteId) {
                    $payload['source']['key'] = 'studio_note:' . $noteMap[$prodNoteId] . ':' . $km[2];
                    $changed = true;
                }
            }
        }
    }

    if (isset($payload['media']) && is_array($payload['media'])) {
        foreach ($payload['media'] as $i => $media) {
            if (!is_array($media)) { continue; }
            $mType = (string)($media['type'] ?? '');
            if ($mType === 'studio_note') {
                if (preg_match('/^studio_note:(\d+):(.+)$/', (string)($media['key'] ?? ''), $km)
                    && preg_match('/^studio-note-(\d+)-(\d+)-([a-f0-9]{20}\.(?:jpg|png|webp))$/i', (string)($media['file'] ?? ''), $fm)) {
                    $prodNoteId = (int)$km[1];
                    if (isset($noteMap[$prodNoteId]) && $noteMap[$prodNoteId] !== $prodNoteId) {
                        $localNoteId = $noteMap[$prodNoteId];
                        $payload['media'][$i]['key'] = 'studio_note:' . $localNoteId . ':' . $km[2];
                        $payload['media'][$i]['file'] = 'studio-note-' . $fm[1] . '-' . $localNoteId . '-' . $fm[3];
                        $changed = true;
                    }
                }
            } elseif ($mType === 'mockup') {
                $mId = (int)($media['id'] ?? 0);
                if ($mId > 0 && isset($mockupMap[$mId]) && $mockupMap[$mId] !== $mId) {
                    $payload['media'][$i]['id'] = $mockupMap[$mId];
                    $payload['media'][$i]['key'] = 'mockup:' . $mockupMap[$mId];
                    $changed = true;
                }
            }
        }
    }

    if ($changed) {
        $changedCount++;
        if ($apply) {
            $update->execute([json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $row['id']]);
        }
    }
}

if ($unmappedIds) {
    fwrite(STDERR, "WARNING: production mockup ids with no local match (dropped from mockup_ids): " . implode(',', array_unique($unmappedIds)) . "\n");
}

echo json_encode(['mode' => $apply ? 'applied' : 'dry-run', 'rows_changed' => $changedCount], JSON_PRETTY_PRINT) . "\n";

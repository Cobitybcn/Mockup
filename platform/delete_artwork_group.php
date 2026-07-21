<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function delete_artwork_asset(string $file, string $folder = 'results'): void
{
    $file = basename($file);
    if ($file === '') {
        return;
    }

    $localDirs = $folder === 'prompts'
        ? [defined('PROMPTS_DIR') ? PROMPTS_DIR : (__DIR__ . '/prompts')]
        : [RESULTS_DIR, __DIR__ . '/uploads'];

    foreach ($localDirs as $dir) {
        $path = rtrim((string)$dir, '/\\') . DIRECTORY_SEPARATOR . $file;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    StorageService::delete($folder . '/' . $file);
}

try {
    $user = Auth::requireUser();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new RuntimeException('Method not allowed.');
    }
    Auth::requireValidCsrf(Auth::requestCsrfToken(), 'mutation');

    $artworkId = max(0, (int)($_POST['artwork_id'] ?? 0));
    if ($artworkId <= 0) {
        http_response_code(400);
        throw new RuntimeException('Missing artwork id.');
    }

    $pdo = Database::connection();
    $stmt = $pdo->prepare('SELECT * FROM artworks WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $artworkId]);
    $artwork = $stmt->fetch();
    if (!$artwork) {
        http_response_code(404);
        throw new RuntimeException('Artwork not found.');
    }

    $ownerId = (int)$artwork['user_id'];
    if ($ownerId !== (int)$user['id'] && !Auth::isAdmin($user)) {
        http_response_code(403);
        throw new RuntimeException('Access denied.');
    }

    $groupId = max(0, (int)($artwork['artwork_group_id'] ?? 0));
    if ($groupId <= 0) {
        $groupStmt = $pdo->prepare('SELECT id FROM artwork_groups WHERE user_id = :user_id AND canonical_artwork_id = :artwork_id LIMIT 1');
        $groupStmt->execute(['user_id' => $ownerId, 'artwork_id' => $artworkId]);
        $groupId = max(0, (int)$groupStmt->fetchColumn());
    }

    if ($groupId > 0) {
        $membersStmt = $pdo->prepare('SELECT id, root_file, main_file FROM artworks WHERE user_id = :user_id AND (artwork_group_id = :group_id OR id = :artwork_id)');
        $membersStmt->execute(['user_id' => $ownerId, 'group_id' => $groupId, 'artwork_id' => $artworkId]);
    } else {
        $membersStmt = $pdo->prepare('SELECT id, root_file, main_file FROM artworks WHERE user_id = :user_id AND id = :artwork_id');
        $membersStmt->execute(['user_id' => $ownerId, 'artwork_id' => $artworkId]);
    }
    $members = $membersStmt->fetchAll();
    $artworkIds = array_values(array_unique(array_map(static fn(array $row): int => (int)$row['id'], $members)));
    if (!$artworkIds) {
        throw new RuntimeException('Artwork group is empty.');
    }

    $artworkFiles = [];
    foreach ($members as $member) {
        foreach (['root_file', 'main_file'] as $column) {
            $file = basename((string)($member[$column] ?? ''));
            if ($file !== '') {
                $artworkFiles[$file] = true;
            }
        }
    }

    $idMarks = implode(',', array_fill(0, count($artworkIds), '?'));
    $candidateStmt = $pdo->prepare("SELECT file_name FROM root_artwork_candidates WHERE artwork_id IN ($idMarks)");
    $candidateStmt->execute($artworkIds);
    foreach ($candidateStmt->fetchAll(PDO::FETCH_COLUMN) as $file) {
        $file = basename((string)$file);
        if ($file !== '') {
            $artworkFiles[$file] = true;
        }
    }

    $mockupWhere = ['user_id = ?'];
    $mockupParams = [$ownerId];
    $association = [];
    if ($groupId > 0) {
        $association[] = 'artwork_group_id = ?';
        $mockupParams[] = $groupId;
    }
    $association[] = "source_artwork_id IN ($idMarks)";
    array_push($mockupParams, ...$artworkIds);
    if ($artworkFiles) {
        $fileMarks = implode(',', array_fill(0, count($artworkFiles), '?'));
        $association[] = "artwork_file IN ($fileMarks)";
        array_push($mockupParams, ...array_keys($artworkFiles));
    }
    $mockupWhere[] = '(' . implode(' OR ', $association) . ')';
    $mockupStmt = $pdo->prepare('SELECT id, mockup_file, prompt_file FROM mockups WHERE ' . implode(' AND ', $mockupWhere));
    $mockupStmt->execute($mockupParams);
    $mockups = $mockupStmt->fetchAll();
    $mockupIds = array_values(array_map(static fn(array $row): int => (int)$row['id'], $mockups));

    Database::withBusyRetry(function () use ($pdo, $ownerId, $groupId, $artworkIds, $mockupIds): void {
        $pdo->beginTransaction();
        try {
            if ($mockupIds) {
                $marks = implode(',', array_fill(0, count($mockupIds), '?'));
                $pdo->prepare("DELETE FROM mockups WHERE user_id = ? AND id IN ($marks)")
                    ->execute(array_merge([$ownerId], $mockupIds));
            }
            if ($groupId > 0) {
                $pdo->prepare('DELETE FROM artwork_groups WHERE id = :id AND user_id = :user_id')
                    ->execute(['id' => $groupId, 'user_id' => $ownerId]);
            }
            $marks = implode(',', array_fill(0, count($artworkIds), '?'));
            $pdo->prepare("DELETE FROM artworks WHERE user_id = ? AND id IN ($marks)")
                ->execute(array_merge([$ownerId], $artworkIds));
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }, 12);

    foreach ($mockupIds as $mockupId) {
        MockupFavorites::removeForUser($ownerId, $mockupId);
    }
    foreach ($mockups as $mockup) {
        $mockupFile = basename((string)($mockup['mockup_file'] ?? ''));
        if ($mockupFile !== '') {
            $check = $pdo->prepare('SELECT COUNT(*) FROM mockups WHERE mockup_file = :file');
            $check->execute(['file' => $mockupFile]);
            if ((int)$check->fetchColumn() === 0) {
                delete_artwork_asset($mockupFile);
            }
        }
        $promptFile = basename((string)($mockup['prompt_file'] ?? ''));
        if ($promptFile !== '') {
            $check = $pdo->prepare('SELECT COUNT(*) FROM mockups WHERE prompt_file = :file');
            $check->execute(['file' => $promptFile]);
            if ((int)$check->fetchColumn() === 0) {
                delete_artwork_asset($promptFile, 'prompts');
            }
        }
    }
    foreach (array_keys($artworkFiles) as $file) {
        $check = $pdo->prepare('SELECT COUNT(*) FROM artworks WHERE root_file = :file OR main_file = :file');
        $check->execute(['file' => $file]);
        $artworkRefs = (int)$check->fetchColumn();
        $check = $pdo->prepare('SELECT COUNT(*) FROM mockups WHERE artwork_file = :file');
        $check->execute(['file' => $file]);
        if ($artworkRefs === 0 && (int)$check->fetchColumn() === 0) {
            delete_artwork_asset($file);
            $meta = pathinfo($file, PATHINFO_FILENAME) . '.meta.json';
            delete_artwork_asset($meta);
        }
    }

    echo json_encode([
        'ok' => true,
        'deleted_artwork_id' => $artworkId,
        'deleted_artworks' => count($artworkIds),
        'deleted_mockups' => count($mockupIds),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (http_response_code() < 400) {
        http_response_code(500);
    }
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

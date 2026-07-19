<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function delete_mockup_evaluation(int $artworkId, int $mockupId): void
{
    if ($artworkId <= 0 || $mockupId <= 0) {
        return;
    }

    $path = __DIR__ . '/analysis/mockup-combination-evaluations/' . $artworkId . '.evaluations.json';
    if (!is_file($path)) {
        return;
    }

    $data = json_decode((string)file_get_contents($path), true);
    if (!is_array($data) || !is_array($data['evaluations'] ?? null)) {
        return;
    }

    unset($data['evaluations'][(string)$mockupId]);
    $data['updated_at'] = date(DATE_ATOM);
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

try {
    $user = Auth::requireUser();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $mockupId = max(0, (int)($_POST['mockup_id'] ?? 0));
    if ($mockupId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing mockup id.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo = Database::connection();
    $stmt = $pdo->prepare('SELECT * FROM mockups WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $mockupId]);
    $mockup = $stmt->fetch();
    if (!$mockup) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Mockup not found.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ((int)$mockup['user_id'] !== (int)$user['id'] && !Auth::isAdmin($user)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Access denied.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $mockupFile = basename((string)($mockup['mockup_file'] ?? ''));
    $promptFile = basename((string)($mockup['prompt_file'] ?? ''));
    $mockupOwnerId = (int)$mockup['user_id'];
    $artworkId = 0;
    $artwork = null;

    $artworkStmt = $pdo->prepare('SELECT id FROM artworks WHERE user_id = :user_id AND root_file = :root_file LIMIT 1');
    $artworkStmt->execute([
        'user_id' => $mockupOwnerId,
        'root_file' => basename((string)($mockup['artwork_file'] ?? '')),
    ]);
    $artwork = $artworkStmt->fetch();
    if ($artwork) {
        $artworkId = (int)$artwork['id'];
    }

    Database::withBusyRetry(function () use ($pdo, $mockupId, $mockupOwnerId): void {
        Database::beginWriteTransaction($pdo);
        try {
            $delete = $pdo->prepare('DELETE FROM mockups WHERE id = :id');
            $delete->execute(['id' => $mockupId]);

            // Keep the generation audit row, but detach the deleted result so the
            // results page cannot reconstruct a dead thumbnail from job history.
            $detachJob = $pdo->prepare('
                UPDATE mockup_generation_jobs
                SET mockup_id = NULL, mockup_file = NULL, updated_at = :updated_at
                WHERE user_id = :user_id AND mockup_id = :mockup_id
            ');
            $detachJob->execute([
                'updated_at' => date('c'),
                'user_id' => $mockupOwnerId,
                'mockup_id' => $mockupId,
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }, 12);

    MockupFavorites::removeForUser($mockupOwnerId, $mockupId);
    if ((int)$user['id'] !== $mockupOwnerId) {
        MockupFavorites::removeForUser((int)$user['id'], $mockupId);
    }
    delete_mockup_evaluation($artworkId, $mockupId);

    if ($mockupFile !== '') {
        $count = $pdo->prepare('SELECT COUNT(*) FROM mockups WHERE mockup_file = :file');
        $count->execute(['file' => $mockupFile]);
        if ((int)$count->fetchColumn() === 0) {
            if (StorageService::isGcsActive()) {
                StorageService::delete('results/' . $mockupFile);
            }
            $path = RESULTS_DIR . DIRECTORY_SEPARATOR . $mockupFile;
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    if ($promptFile !== '' && defined('PROMPTS_DIR')) {
        $count = $pdo->prepare('SELECT COUNT(*) FROM mockups WHERE prompt_file = :file');
        $count->execute(['file' => $promptFile]);
        if ((int)$count->fetchColumn() === 0) {
            if (StorageService::isGcsActive()) {
                StorageService::delete('mockup-prompts/' . $promptFile);
            }
            $path = PROMPTS_DIR . DIRECTORY_SEPARATOR . $promptFile;
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    echo json_encode(['ok' => true, 'deleted_mockup_id' => $mockupId], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

$user = Auth::user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Session expired.'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt = Database::connection()->prepare("
    SELECT j.id, j.artwork_id, j.context_id, j.status, j.mockup_id, j.error,
           j.selector_state_json, j.created_at, j.updated_at,
           COALESCE(NULLIF(g.title, ''), NULLIF(a.final_title, ''), 'Artwork') AS artwork_title
    FROM mockup_generation_jobs j
    LEFT JOIN artworks a ON a.id = j.artwork_id AND a.user_id = j.user_id
    LEFT JOIN artwork_groups g ON g.id = j.artwork_group_id AND g.user_id = j.user_id
    WHERE j.user_id = :user_id
    ORDER BY j.id DESC
    LIMIT 80
");
$stmt->execute(['user_id' => (int)$user['id']]);

$activeStatuses = ['pending_enqueue', 'queued', 'processing'];
$items = [];
foreach ($stmt->fetchAll() as $row) {
    $state = json_decode((string)($row['selector_state_json'] ?? ''), true);
    if (!is_array($state) || (string)($state['generation_source'] ?? '') !== 'mockup_combination_review') {
        continue;
    }
    $status = (string)$row['status'];
    $combination = (array)($state['combination'] ?? []);
    $board = max(1, min(3, (int)($state['scene_board_index'] ?? $combination['camera_slot_scene_board_index'] ?? 1)));
    $provider = ServiceFactory::generationProvider((string)($state['generation_provider'] ?? ''));
    $category = trim((string)($state['world_mother_category'] ?? $combination['world_mother_category'] ?? ''));
    $generationRunId = strtolower(trim((string)($state['generation_run_id'] ?? '')));
    if ($generationRunId !== '' && !preg_match('/^[a-z0-9-]{8,64}$/', $generationRunId)) {
        $generationRunId = '';
    }
    $resultsUrl = 'mockup_combination_results.php?id=' . (int)$row['artwork_id']
        . '&board=' . $board
        . '&generation_provider=' . rawurlencode($provider)
        . ($category !== '' ? '&world_mother_category=' . rawurlencode($category) : '')
        . ($generationRunId !== '' ? '&generation_run=' . rawurlencode($generationRunId) : '')
        . '&highlight_job=' . (int)$row['id'];
    $improvement = (array)($combination['improvement_controls'] ?? []);
    $items[] = [
        'id' => (int)$row['id'],
        'artwork_id' => (int)$row['artwork_id'],
        'artwork_title' => (string)$row['artwork_title'],
        'status' => $status,
        'active' => in_array($status, $activeStatuses, true),
        'kind' => (int)($improvement['existing_mockup_id'] ?? 0) > 0 ? 'regeneration' : 'generation',
        'generation_run_id' => $generationRunId,
        'scene_category' => $category,
        'replaces_mockup_id' => (int)($improvement['existing_mockup_id'] ?? 0) ?: null,
        'mockup_id' => (int)($row['mockup_id'] ?? 0) ?: null,
        'error' => (string)($row['error'] ?? ''),
        'results_url' => $resultsUrl,
        'created_at' => (string)$row['created_at'],
        'updated_at' => (string)$row['updated_at'],
    ];
}

$active = array_values(array_filter($items, static fn (array $item): bool => $item['active']));
echo json_encode([
    'ok' => true,
    'active_count' => count($active),
    'active' => $active,
    'items' => $items,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

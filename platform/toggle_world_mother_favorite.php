<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $user = Auth::requireUser();
    $category = trim(str_replace(['\\', '/'], '', (string)($_POST['category'] ?? $_GET['category'] ?? '')));
    if ($category === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing scene mother category.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $dir = __DIR__ . '/storage/world_mother_favorites';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create favorites directory.');
    }

    $path = $dir . '/user_' . (int)$user['id'] . '.json';
    $favorites = [];
    if (is_file($path)) {
        $decoded = json_decode((string)file_get_contents($path), true);
        if (is_array($decoded)) {
            $favorites = array_values(array_unique(array_filter(
                array_map('strval', $decoded),
                static fn (string $slug): bool => $slug !== ''
            )));
        }
    }

    $normalizedCategory = WorldMotherGenerator::safeSlug($category);
    $favorite = !in_array($category, $favorites, true)
        && !in_array($normalizedCategory, array_map([WorldMotherGenerator::class, 'safeSlug'], $favorites), true);
    if ($favorite) {
        $favorites[] = $category;
    } else {
        $favorites = array_values(array_filter(
            $favorites,
            static fn (string $slug): bool => $slug !== $category && WorldMotherGenerator::safeSlug($slug) !== $normalizedCategory
        ));
    }

    sort($favorites, SORT_STRING);
    file_put_contents($path, json_encode($favorites, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    echo json_encode([
        'ok' => true,
        'category' => $category,
        'favorite' => $favorite,
        'favorites' => $favorites,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

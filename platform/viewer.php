<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
$pdo = Database::connection();
$isAdmin = Auth::isAdmin($user);
$canUseSocial = FeatureAccess::allows($user, FeatureAccess::SOCIAL_MANAGE);

$id = (int)($_GET['id'] ?? 0);
$file = basename((string)($_GET['file'] ?? ''));
$bilingualExperiment = (string)($_GET['bilingual_experiment'] ?? '') === '1';

if ($id > 0) {
    $sql = 'SELECT * FROM mockups WHERE id = :id';
    $params = ['id' => $id];
    if (!$isAdmin) {
        $sql .= ' AND user_id = :user_id';
        $params['user_id'] = (int)$user['id'];
    }
    $stmt = $pdo->prepare($sql . ' LIMIT 1');
    $stmt->execute($params);
} else {
    $sql = 'SELECT * FROM mockups WHERE mockup_file = :file';
    $params = ['file' => $file];
    if (!$isAdmin) {
        $sql .= ' AND user_id = :user_id';
        $params['user_id'] = (int)$user['id'];
    }
    $stmt = $pdo->prepare($sql . ' LIMIT 1');
    $stmt->execute($params);
}

$mockup = $stmt->fetch();
$isStandaloneFile = false;
$standaloneFiles = [];

if (!$mockup) {
    $standaloneFile = $file;
    $standalonePath = $standaloneFile !== '' ? RESULTS_DIR . DIRECTORY_SEPARATOR . $standaloneFile : '';
    $artwork = null;
    if ($standaloneFile !== '' && is_file($standalonePath)) {
        $sql = 'SELECT * FROM artworks WHERE (root_file = :file OR main_file = :file)';
        $params = ['file' => $standaloneFile];
        if (!$isAdmin) {
            $sql .= ' AND user_id = :user_id';
            $params['user_id'] = (int)$user['id'];
        }
        $stmt = $pdo->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        $artwork = $stmt->fetch();

        if (!$artwork && preg_match('/^(.*)_v\d+\.(png|jpe?g|webp)$/i', $standaloneFile, $matches)) {
            $prefixPattern = $matches[1] . '_v%';
            $sql = 'SELECT * FROM artworks WHERE (root_file LIKE :pattern OR main_file LIKE :pattern)';
            $params = ['pattern' => $prefixPattern];
            if (!$isAdmin) {
                $sql .= ' AND user_id = :user_id';
                $params['user_id'] = (int)$user['id'];
            }
            $stmt = $pdo->prepare($sql . ' LIMIT 1');
            $stmt->execute($params);
            $artwork = $stmt->fetch();
        }

        if (!$artwork && preg_match('/^base_artwork_gemini_job_(\d+_\d+)_v\d+\.(png|jpe?g|webp)$/i', $standaloneFile, $matches)) {
            $jobId = 'job_' . $matches[1];
            $sql = 'SELECT * FROM artworks WHERE job_id = :job_id';
            $params = ['job_id' => $jobId];
            if (!$isAdmin) {
                $sql .= ' AND user_id = :user_id';
                $params['user_id'] = (int)$user['id'];
            }
            $stmt = $pdo->prepare($sql . ' LIMIT 1');
            $stmt->execute($params);
            $artwork = $stmt->fetch();
        }

        if (!$artwork) {
            $sql = 'SELECT a.* FROM root_artwork_candidates rac INNER JOIN artworks a ON a.id = rac.artwork_id WHERE rac.file_name = :file';
            $params = ['file' => $standaloneFile];
            if (!$isAdmin) {
                $sql .= ' AND a.user_id = :user_id';
                $params['user_id'] = (int)$user['id'];
            }
            $stmt = $pdo->prepare($sql . ' LIMIT 1');
            $stmt->execute($params);
            $artwork = $stmt->fetch();
        }
    }

    if (!is_array($artwork)) {
        http_response_code(404);
        exit('Image not found.');
    }

    $isStandaloneFile = true;
    $mockup = [
        'id' => 0,
        'artwork_file' => (string)($artwork['root_file'] ?: $artwork['main_file'] ?: $standaloneFile),
        'mockup_file' => $standaloneFile,
        'context_id' => 'Root artwork',
        'created_at' => (string)($artwork['updated_at'] ?? $artwork['created_at'] ?? date('c')),
    ];
}

function viewer_safe_back_url(string $candidate): string
{
    $candidate = trim(str_replace(["\r", "\n"], '', $candidate));
    if ($candidate === '' || str_starts_with($candidate, '//')) {
        return '';
    }

    $parts = parse_url($candidate);
    if ($parts === false) {
        return '';
    }

    if (isset($parts['scheme']) || isset($parts['host'])) {
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        if (!isset($parts['host']) || strcasecmp((string)$parts['host'], $host) !== 0) {
            return '';
        }

        $path = str_replace('\\', '/', (string)($parts['path'] ?? ''));
        $scriptDir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
        if ($scriptDir !== '' && $scriptDir !== '.' && str_starts_with($path, $scriptDir . '/')) {
            $path = substr($path, strlen($scriptDir) + 1);
        } else {
            $path = ltrim($path, '/');
        }

        $candidate = $path
            . (isset($parts['query']) ? '?' . (string)$parts['query'] : '')
            . (isset($parts['fragment']) ? '#' . (string)$parts['fragment'] : '');
    } elseif (preg_match('/^[a-z][a-z0-9+.-]*:/i', $candidate)) {
        return '';
    } else {
        $candidate = ltrim(str_replace('\\', '/', $candidate), '/');
        $scriptDir = trim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
        if ($scriptDir !== '' && $scriptDir !== '.' && str_starts_with($candidate, $scriptDir . '/')) {
            $candidate = substr($candidate, strlen($scriptDir) + 1);
        }
    }

    $path = (string)(parse_url($candidate, PHP_URL_PATH) ?: '');
    $page = basename($path);
    $allowedPages = [
        'artwork.php' => true,
        'artwork_details.php' => true,
        'dashboard.php' => true,
        'root_album.php' => true,
        'form2.php' => true,
        'mockups.php' => true,
        'mockup_combination_results.php' => true,
        'mockup_combinations_review.php' => true,
        'mockup_variation_lab.php' => true,
        'root_album.php' => true,
    ];

    return isset($allowedPages[$page]) ? $candidate : '';
}

$backUrl = 'mockups.php';
$requestedBack = trim((string)($_GET['back'] ?? ''));
$safeBackUrl = viewer_safe_back_url($requestedBack);
$hasSafeOriginBack = false;
if ($safeBackUrl !== '') {
    $backUrl = $safeBackUrl;
    $hasSafeOriginBack = true;
} elseif ($requestedBack === '') {
    $safeRefererUrl = viewer_safe_back_url((string)($_SERVER['HTTP_REFERER'] ?? ''));
    if ($safeRefererUrl !== '') {
        $backUrl = $safeRefererUrl;
        $hasSafeOriginBack = true;
    }
}
if (!isset($artwork) || !is_array($artwork)) {
    $artworkStmt = $pdo->prepare('
        SELECT *
        FROM artworks
        WHERE user_id = :user_id
        AND (root_file = :artwork_file OR main_file = :artwork_file)
        LIMIT 1
    ');
    $artworkStmt->execute([
        'user_id' => (int)$user['id'],
        'artwork_file' => (string)$mockup['artwork_file'],
    ]);
    $artwork = $artworkStmt->fetch();
}
$artworkId = is_array($artwork) ? (int)$artwork['id'] : 0;

if ($artworkId && !$hasSafeOriginBack) {
    $backUrl = 'artwork.php?id=' . rawurlencode((string)$artworkId);
}
$viewerBackParam = $backUrl !== '' ? '&back=' . rawurlencode($backUrl) : '';

$prevHref = '';
$nextHref = '';
$hasScopedNavigation = false;
if ($isStandaloneFile) {
    $currentFile = basename((string)$mockup['mockup_file']);
    $prefix = '';
    if (preg_match('/^(.*)_v\d+\.(png|jpe?g|webp)$/i', $currentFile, $matches)) {
        $prefix = (string)$matches[1];
    }
    if ($prefix !== '') {
        foreach ([1, 2, 3] as $version) {
            foreach (['png', 'jpg', 'jpeg', 'webp'] as $ext) {
                $candidate = $prefix . '_v' . $version . '.' . $ext;
                if (is_file(RESULTS_DIR . DIRECTORY_SEPARATOR . $candidate)) {
                    $standaloneFiles[] = $candidate;
                    break;
                }
            }
        }
        $index = array_search($currentFile, $standaloneFiles, true);
        if ($index !== false) {
            if (isset($standaloneFiles[$index - 1])) {
                $prevHref = 'viewer.php?file=' . rawurlencode($standaloneFiles[$index - 1]) . $viewerBackParam;
            }
            if (isset($standaloneFiles[$index + 1])) {
                $nextHref = 'viewer.php?file=' . rawurlencode($standaloneFiles[$index + 1]) . $viewerBackParam;
            }
        }
    }
} else {
    $scopedCombinationArtworkId = 0;
    $backPath = (string)(parse_url($backUrl, PHP_URL_PATH) ?: '');
    if (basename($backPath) === 'mockup_combination_results.php') {
        parse_str((string)(parse_url($backUrl, PHP_URL_QUERY) ?: ''), $backQuery);
        $scopedCombinationArtworkId = max(0, (int)($backQuery['id'] ?? 0));
    }

    if ($scopedCombinationArtworkId > 0) {
        $hasScopedNavigation = true;
        $scopeStmt = $pdo->prepare('
            SELECT id, selector_state_json
            FROM mockups
            WHERE user_id = :user_id
            AND (
                artwork_file = :artwork_file
                OR selector_state_json LIKE :audit_path
            )
            ORDER BY id DESC
        ');
        $scopeStmt->execute([
            'user_id' => (int)$mockup['user_id'],
            'artwork_file' => basename((string)($artwork['root_file'] ?? $mockup['artwork_file'] ?? '')),
            'audit_path' => '%analysis/mockup-combination-audit/' . $scopedCombinationArtworkId . '/%',
        ]);

        $scopedRows = [];
        foreach ($scopeStmt->fetchAll() ?: [] as $scopeRow) {
            $state = json_decode((string)($scopeRow['selector_state_json'] ?? ''), true);
            if (!is_array($state) || ($state['generation_source'] ?? '') !== 'mockup_combination_review') {
                continue;
            }
            $combo = (array)($state['combination'] ?? []);
            $order = (int)($combo['camera_slot_board_order'] ?? 0);
            if ($order <= 0) {
                $order = (int)($combo['combination_index'] ?? 999);
            }
            $scopedRows[] = [
                'id' => (int)$scopeRow['id'],
                'order' => $order > 0 ? $order : 999,
            ];
        }

        usort($scopedRows, static function (array $a, array $b): int {
            return ((int)$a['order'] <=> (int)$b['order'])
                ?: ((int)$b['id'] <=> (int)$a['id']);
        });

        $scopedIds = array_column($scopedRows, 'id');
        $currentIndex = array_search((int)$mockup['id'], $scopedIds, true);
        if ($currentIndex !== false) {
            if (isset($scopedIds[$currentIndex - 1])) {
                $prevHref = 'viewer.php?id=' . rawurlencode((string)$scopedIds[$currentIndex - 1]) . $viewerBackParam;
            }
            if (isset($scopedIds[$currentIndex + 1])) {
                $nextHref = 'viewer.php?id=' . rawurlencode((string)$scopedIds[$currentIndex + 1]) . $viewerBackParam;
            }
        }
    }
}

if (!$isStandaloneFile && !$hasScopedNavigation && $prevHref === '' && $nextHref === '') {
    $prevStmt = $pdo->prepare('
        SELECT id
        FROM mockups
        WHERE user_id = :user_id
        AND (
            created_at > :created_at
            OR (created_at = :created_at AND id > :id)
        )
        ORDER BY created_at ASC, id ASC
        LIMIT 1
    ');
    $prevStmt->execute([
        'user_id' => (int)$user['id'],
        'created_at' => (string)$mockup['created_at'],
        'id' => (int)$mockup['id'],
    ]);
    $prevId = $prevStmt->fetchColumn();
    $prevHref = $prevId ? 'viewer.php?id=' . rawurlencode((string)$prevId) . $viewerBackParam : '';

    $nextStmt = $pdo->prepare('
        SELECT id
        FROM mockups
        WHERE user_id = :user_id
        AND (
            created_at < :created_at
            OR (created_at = :created_at AND id < :id)
        )
        ORDER BY created_at DESC, id DESC
        LIMIT 1
    ');
    $nextStmt->execute([
        'user_id' => (int)$user['id'],
        'created_at' => (string)$mockup['created_at'],
        'id' => (int)$mockup['id'],
    ]);
    $nextId = $nextStmt->fetchColumn();
    $nextHref = $nextId ? 'viewer.php?id=' . rawurlencode((string)$nextId) . $viewerBackParam : '';
}

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function media_url(?string $file): string
{
    return $file ? 'media.php?file=' . rawurlencode(basename($file)) : '';
}

function download_url(?string $file): string
{
    return $file ? 'media.php?file=' . rawurlencode(basename($file)) . '&download=1' : '';
}

function read_json_file(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $data = json_decode((string)file_get_contents($path), true);

    return is_array($data) ? $data : [];
}

$rootFile = is_array($artwork) ? basename((string)($artwork['root_file'] ?? '')) : basename((string)$mockup['artwork_file']);
$rootBase = $rootFile ? pathinfo($rootFile, PATHINFO_FILENAME) : '';
$analysis = $rootBase ? read_json_file(ANALYSIS_DIR . DIRECTORY_SEPARATOR . $rootBase . '.analysis.json') : [];
$profile = is_array($analysis['artwork_profile'] ?? null) ? $analysis['artwork_profile'] : [];
$artistProfile = is_array($profile['_artist_profile'] ?? null) ? $profile['_artist_profile'] : ArtistProfile::findForUser((int)$user['id']);
$contextTitle = Display::contextTitle($mockup['context_id']);
$viewerMockupId = (int)($mockup['id'] ?? 0);
$viewerFavoriteLookup = $viewerMockupId > 0 ? MockupFavorites::lookupForUser((int)$user['id']) : [];
$viewerIsFavorite = $viewerMockupId > 0 && isset($viewerFavoriteLookup[$viewerMockupId]);
$pinterestDraftNotice=(string)($_SESSION['pinterest_draft_notice']??'');unset($_SESSION['pinterest_draft_notice']);
$pinterestDraftError=(string)($_SESSION['pinterest_draft_error']??'');unset($_SESSION['pinterest_draft_error']);
$metaDraftNotice=(string)($_SESSION['meta_draft_notice']??'');unset($_SESSION['meta_draft_notice']);
$metaDraftError=(string)($_SESSION['meta_draft_error']??'');unset($_SESSION['meta_draft_error']);
$_SESSION['pinterest_draft_csrf']=bin2hex(random_bytes(24));
$_SESSION['meta_draft_csrf']=bin2hex(random_bytes(24));
$editorial = MockupEditorialContent::build(is_array($artwork) ? $artwork : [], $analysis, $artistProfile, $contextTitle);
$pinBoard = $editorial['board'];
$pinTitle = $editorial['title'];
$pinDescription = $editorial['description'];
$pinAlt = $editorial['altText'];
$pinKeywords = $editorial['keywords'];
$pinHashtags = $editorial['hashtags'];
$otherSocial = $editorial['social'];
$titleLine = $editorial['titleLine'];

// La ficha editorial vigente vive en el viewer. La vinculamos con la obra madre
// y persistimos su copy actual para que el publicador use este mockup exacto.
$publicationSheetId = 0;
$publicationMockupSheetId = 0;
if ($artworkId > 0 && $viewerMockupId > 0) {
    try {
        $artworkSheetService = new ArtworkSheetService($pdo);
        $publicationArtworkSheet = $artworkSheetService->sheetForArtwork($artworkId, (int)$user['id']);
        $publicationMockupSheet = $artworkSheetService->attachMockupFile(
            (int)$publicationArtworkSheet['id'],
            (int)$user['id'],
            (string)$mockup['mockup_file']
        );
        $publicationSheetId = (int)$publicationArtworkSheet['id'];
        $publicationMockupSheetId = (int)$publicationMockupSheet['id'];
        if (trim((string)($publicationMockupSheet['title'] ?? '')) === '') {
            $artworkSheetService->saveMockupSheet($publicationMockupSheetId, (int)$user['id'], [
                'title' => $pinTitle,
                'description' => $pinDescription,
                'keywords' => implode(', ', $pinKeywords),
                'tags' => implode(', ', array_map(static fn(string $tag): string => ltrim($tag, '#'), $pinHashtags)),
                'alt_text' => $pinAlt,
                'caption' => (string)($otherSocial['Instagram'] ?? $titleLine),
                'status' => 'review',
            ]);
        }
    } catch (Throwable $publicationError) {
        $publicationSheetId = 0;
        $publicationMockupSheetId = 0;
    }
}
$mockupV2 = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_mockup_v2') {
    try {
        if ($publicationSheetId <= 0 || $publicationMockupSheetId <= 0) throw new RuntimeException('Mockup sheet is not available.');
        $artworkSheetService->generateMockupSheet($publicationSheetId, $artworkId, (string)$mockup['mockup_file'], (int)$user['id'], 'Generate neutral mockup analysis v2 and channel adapters.');
        header('Location: viewer.php?id=' . $viewerMockupId . $viewerBackParam . '&mockup_v2_generated=1');
        exit;
    } catch (Throwable $e) {
        header('Location: viewer.php?id=' . $viewerMockupId . $viewerBackParam . '&mockup_v2_error=' . rawurlencode($e->getMessage()));
        exit;
    }
}
if (is_array($publicationMockupSheet ?? null)) {
    $storedMockupGenerated = json_decode((string)($publicationMockupSheet['generated_json'] ?? ''), true);
    if (is_array($storedMockupGenerated['mockup_analysis_v2'] ?? null)) $mockupV2 = $storedMockupGenerated['mockup_analysis_v2'];
}
if ($mockupV2) {
    $neutralV2=(array)($mockupV2['neutral']??[]); $channelsV2=(array)($mockupV2['channels']??[]); $pinterestV2=(array)($channelsV2['pinterest']??[]);
    $pinTitle=trim((string)($pinterestV2['title']??''))?:$pinTitle; $pinDescription=trim((string)($pinterestV2['description']??''))?:$pinDescription; $pinAlt=trim((string)($neutralV2['alt_text']??''))?:$pinAlt;
    $pinKeywords=(array)($pinterestV2['keywords']??[])?:$pinKeywords; $v2Boards=(array)($pinterestV2['board_suggestions']??[]); if($v2Boards)$pinBoard=(string)$v2Boards[0];
    $instagramV2=(array)($channelsV2['instagram']??[]); $facebookV2=(array)($channelsV2['facebook']??[]); $tiktokV2=(array)($channelsV2['tiktok']??[]);
    $otherSocial=['Instagram'=>trim((string)($instagramV2['caption']??'')."\n\n".implode(' ',(array)($instagramV2['hashtags']??[]))),'Facebook'=>trim((string)($facebookV2['headline']??'')."\n\n".(string)($facebookV2['post_text']??'')),'TikTok · future'=>trim((string)($tiktokV2['caption_seed']??'')."\n".(string)($tiktokV2['video_notes']??''))];
}
$mockupEditorialSheet = is_array($publicationMockupSheet ?? null) ? $publicationMockupSheet : [];
$mockupEditorialTitle = trim((string)($mockupEditorialSheet['title'] ?? '')) ?: trim((string)$pinTitle) ?: $contextTitle;
$mockupEditorialFields = [
    ['es' => 'Descripción', 'en' => 'Description', 'value' => trim((string)($mockupEditorialSheet['description'] ?? '')) ?: $pinDescription, 'large' => true, 'es_placeholder' => 'Escribí la descripción editorial de este mockup…', 'en_placeholder' => 'No English description is currently available.'],
    ['es' => 'Palabras clave', 'en' => 'Keywords', 'value' => trim((string)($mockupEditorialSheet['keywords'] ?? '')) ?: implode(', ', $pinKeywords), 'large' => false, 'es_placeholder' => 'Escena, arquitectura, luz, atmósfera…', 'en_placeholder' => 'No English keywords are currently available.'],
    ['es' => 'Etiquetas', 'en' => 'Tags', 'value' => trim((string)($mockupEditorialSheet['tags'] ?? '')) ?: implode(', ', array_map(static fn(string $tag): string => ltrim($tag, '#'), $pinHashtags)), 'large' => false, 'es_placeholder' => 'Etiquetas editoriales…', 'en_placeholder' => 'No English tags are currently available.'],
    ['es' => 'Texto alternativo', 'en' => 'Alt text', 'value' => trim((string)($mockupEditorialSheet['alt_text'] ?? '')) ?: $pinAlt, 'large' => false, 'es_placeholder' => 'Descripción visual accesible del mockup…', 'en_placeholder' => 'No English alt text is currently available.'],
    ['es' => 'Caption', 'en' => 'Caption', 'value' => trim((string)($mockupEditorialSheet['caption'] ?? '')) ?: (string)($otherSocial['Instagram'] ?? $titleLine), 'large' => false, 'es_placeholder' => 'Texto breve para publicar este mockup…', 'en_placeholder' => 'No English caption is currently available.'],
];
$mockupChannelData = is_array($mockupV2['channels'] ?? null) ? (array)$mockupV2['channels'] : [];
$mockupSocialSpecs = [
    'website' => [
        'label' => 'Website',
        'fields' => [
            'description' => ['es' => 'Descripción', 'en' => 'Description'],
            'caption' => ['es' => 'Caption', 'en' => 'Caption'],
            'alt_text' => ['es' => 'Texto alternativo', 'en' => 'Alt text'],
            'seo_keywords' => ['es' => 'Palabras clave SEO', 'en' => 'SEO keywords'],
            'long_tail_keywords' => ['es' => 'Términos de búsqueda', 'en' => 'Long-tail keywords'],
        ],
    ],
    'instagram' => [
        'label' => 'Instagram',
        'fields' => [
            'hook' => ['es' => 'Apertura', 'en' => 'Hook'],
            'caption' => ['es' => 'Caption', 'en' => 'Caption'],
            'hashtags' => ['es' => 'Hashtags', 'en' => 'Hashtags'],
            'cta' => ['es' => 'Llamada a la acción', 'en' => 'Call to action'],
        ],
    ],
    'facebook' => [
        'label' => 'Facebook',
        'fields' => [
            'headline' => ['es' => 'Titular', 'en' => 'Headline'],
            'post_text' => ['es' => 'Texto de publicación', 'en' => 'Post text'],
            'link_description' => ['es' => 'Descripción del enlace', 'en' => 'Link description'],
            'cta' => ['es' => 'Llamada a la acción', 'en' => 'Call to action'],
        ],
    ],
    'pinterest' => [
        'label' => 'Pinterest',
        'fields' => [
            'title' => ['es' => 'Título del pin', 'en' => 'Pin title'],
            'description' => ['es' => 'Descripción del pin', 'en' => 'Pin description'],
            'board_suggestions' => ['es' => 'Tableros sugeridos', 'en' => 'Board suggestions'],
            'topic_suggestions' => ['es' => 'Temas sugeridos', 'en' => 'Topic suggestions'],
            'keywords' => ['es' => 'Palabras clave', 'en' => 'Keywords'],
        ],
    ],
    'tiktok' => [
        'label' => 'TikTok · video',
        'fields' => [
            'visual_hook' => ['es' => 'Apertura visual', 'en' => 'Visual hook'],
            'suggested_motion' => ['es' => 'Movimiento sugerido', 'en' => 'Suggested motion'],
            'sequence_role' => ['es' => 'Función en la secuencia', 'en' => 'Sequence role'],
            'caption_seed' => ['es' => 'Base del caption', 'en' => 'Caption seed'],
            'video_notes' => ['es' => 'Notas de video', 'en' => 'Video notes'],
        ],
    ],
];
$mockupSocialChannels = [];
$mockupSocialValue = static function (mixed $value): string {
    if (is_array($value)) {
        return implode("\n", array_map(static fn(mixed $item): string => is_scalar($item) ? (string)$item : (string)json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $value));
    }
    if (is_bool($value)) {
        return $value ? 'Yes' : 'No';
    }

    return trim((string)$value);
};
foreach ($mockupSocialSpecs as $channelKey => $channelSpec) {
    $channelValues = (array)($mockupChannelData[$channelKey] ?? []);
    $fields = [];
    foreach ($channelSpec['fields'] as $fieldKey => $fieldLabels) {
        $fields[] = [
            'es' => $fieldLabels['es'],
            'en' => $fieldLabels['en'],
            'value' => $mockupSocialValue($channelValues[$fieldKey] ?? ''),
        ];
    }
    $mockupSocialChannels[] = ['label' => $channelSpec['label'], 'fields' => $fields];
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Mockup Viewer - Artwork Mockups</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500;600&family=Plus+Jakarta+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --font-serif: 'Cormorant Garamond', Georgia, serif;
            --font-sans: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            --bg: #FAF9F6;
            --surface: #FFFFFF;
            --surface-soft: #F4F3EE;
            --line: #E5E3DD;
            --ink: #141412;
            --muted: #7A7872;
            --accent: #9A7B56;
            --accent-hover: #7E6342;
            --radius: 4px;
            --shadow: 0 4px 30px rgba(20, 20, 18, 0.03);
        }

        body {
            margin: 0;
            min-height: 100vh;
            background: var(--bg);
            color: var(--ink);
            font-family: var(--font-sans);
        }

        .viewer-top {
            position: sticky;
            z-index: 5;
            top: 0;
            left: 0;
            right: 0;
            height: 64px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 0 22px;
            background: rgba(250, 249, 246, .94);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--line);
        }

        .brand {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            color: var(--ink);
            font-family: var(--font-serif);
            font-size: 18px;
            letter-spacing: 0.12em;
            text-decoration: none;
            text-transform: uppercase;
        }

        .viewer-left {
            display: inline-flex;
            align-items: center;
            gap: 22px;
        }

        .icon-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 34px;
            height: 34px;
            color: var(--ink);
            text-decoration: none;
            opacity: .84;
            border: 0;
            background: transparent;
            cursor: pointer;
        }

        .icon-link.back::before {
            content: '';
            width: 12px;
            height: 12px;
            border-left: 3px solid currentColor;
            border-bottom: 3px solid currentColor;
            transform: rotate(45deg);
            margin-left: 4px;
        }

        .icon-link:hover {
            opacity: 1;
            color: var(--accent);
        }

        .brand-mark {
            width: 12px;
            height: 12px;
            border: 3px solid var(--accent);
            display: inline-block;
        }

        .viewer-actions {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .viewer-actions a {
            color: var(--ink);
            text-decoration: none;
            font-weight: 500;
            font-size: 13px;
            border-bottom: 1px solid transparent;
            transition: all 0.2s ease;
        }

        .viewer-actions a:hover {
            color: var(--accent);
            border-color: var(--accent);
        }

        .viewer-favorite-btn {
            width: 40px;
            height: 40px;
            border: 1px solid rgba(183, 127, 134, .72);
            border-radius: 999px;
            background: rgba(255, 250, 247, .86);
            color: var(--accent);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            line-height: 1;
            cursor: pointer;
            box-shadow: 0 10px 28px rgba(28, 23, 20, .12);
            transition: background .16s ease, color .16s ease, border-color .16s ease;
        }

        .viewer-favorite-btn:hover,
        .viewer-favorite-btn:focus-visible,
        .viewer-favorite-btn.active {
            background: #b77f86;
            border-color: #b77f86;
            color: #fffaf7;
            outline: none;
        }

        .viewer-favorite-btn[disabled] {
            cursor: wait;
            opacity: .62;
        }

        .viewer-actions .icon-link {
            border-bottom: 0;
            position: relative;
        }

        .stage {
            min-height: calc(100vh - 64px);
            display: grid;
            place-items: center;
            padding: 24px 56px;
            background: #111;
        }

        .stage img {
            max-width: 100%;
            max-height: calc(100vh - 112px);
            object-fit: contain;
            box-shadow: 0 28px 80px rgba(0,0,0,.6);
            border-radius: 4px;
        }

        .nav-arrow {
            position: fixed;
            z-index: 4;
            top: 50%;
            transform: translateY(-50%);
            width: 54px;
            height: 86px;
            display: grid;
            place-items: center;
            color: #fff;
            text-decoration: none;
            font-size: 58px;
            line-height: 1;
            background: rgba(255,255,255,.05);
            border: 1px solid rgba(255,255,255,.1);
            transition: all 0.2s ease;
        }

        .nav-arrow:hover {
            background: var(--accent);
            border-color: var(--accent);
        }

        .nav-arrow.prev {
            left: 22px;
        }

        .nav-arrow.next {
            right: 22px;
        }

        .viewer-caption {
            position: static;
            z-index: 5;
            padding: 18px 24px;
            display: flex;
            justify-content: center;
            gap: 18px;
            color: var(--muted);
            background: var(--surface);
            border-bottom: 1px solid var(--line);
            font-size: 13px;
        }

        .publication {
            max-width: 1240px;
            margin: 0 auto;
            padding: 44px 24px 70px;
        }

        .panel {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 28px;
            margin-bottom: 24px;
        }

        .panel.pinterest {
            background: linear-gradient(180deg, var(--surface) 0%, #fbf8f2 100%);
            border-color: rgba(154, 123, 86, 0.32);
        }

        .mockup-bilingual-title {
            margin-bottom:18px;
            padding:18px 20px;
            border:1px solid var(--line);
            background:var(--surface);
        }

        .mockup-bilingual-label {
            display:block;
            margin:0 0 15px;
            color:var(--muted);
            font-size:9px;
            font-weight:700;
            letter-spacing:.08em;
            text-transform:uppercase;
        }

        .mockup-bilingual-heading {
            margin:0;
            padding:0 0 14px;
            border-bottom:1px solid var(--line);
            color:var(--ink);
            font:500 clamp(42px,4.5vw,58px)/1.05 var(--font-serif);
            letter-spacing:-.01em;
            overflow-wrap:anywhere;
        }

        .mockup-bilingual-heading:focus { outline:0; }

        .mockup-bilingual-title-memo {
            margin:15px 0 0;
            color:var(--accent);
            font:italic 500 21px/1.5 var(--font-serif);
        }

        .mockup-bilingual-editorial {
            margin-bottom:24px;
            border:1px solid var(--line);
            background:var(--surface);
        }

        .mockup-bilingual-editorial > summary {
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:20px;
            padding:18px 20px;
            cursor:pointer;
            list-style:none;
        }

        .mockup-bilingual-editorial > summary::-webkit-details-marker { display:none; }
        .mockup-bilingual-summary strong { display:block; color:var(--ink); font:500 23px/1.1 var(--font-serif); }
        .mockup-bilingual-summary span { display:block; margin-top:5px; color:var(--muted); font-size:12px; }
        .mockup-bilingual-state { color:var(--muted); font-size:9px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; white-space:nowrap; }
        .mockup-bilingual-state::after { content:'+'; display:inline-block; margin-left:14px; color:var(--accent); font:500 22px/1 var(--font-serif); vertical-align:-2px; }
        .mockup-bilingual-editorial[open] .mockup-bilingual-state::after { content:'−'; }

        .mockup-bilingual-spread {
            display:grid;
            grid-template-columns:repeat(2,minmax(0,1fr));
            grid-template-rows:auto repeat(5,auto);
            column-gap:12px;
            row-gap:0;
            padding:14px;
            border-top:1px solid var(--line);
        }

        .mockup-bilingual-page {
            display:grid;
            grid-row:1 / span 6;
            grid-template-rows:subgrid;
            min-width:0;
            padding:18px;
            border:1px solid var(--line);
            border-top:3px solid #c89aa1;
            background:var(--surface-soft);
        }

        .mockup-bilingual-page--source { grid-column:1; }
        .mockup-bilingual-page--english { grid-column:2; border-top-color:#9fb19a; }
        .mockup-bilingual-language { display:block; color:var(--muted); font-size:9px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; }
        .mockup-bilingual-field { min-height:96px; margin-top:16px; padding-top:13px; border-top:1px solid var(--line); }
        .mockup-bilingual-field--large { min-height:230px; }
        .mockup-bilingual-field label { display:block; color:var(--muted); font-size:9px; font-weight:700; letter-spacing:.07em; text-transform:uppercase; }
        .mockup-bilingual-copy { min-height:62px; margin-top:10px; color:var(--ink); font-size:14px; line-height:1.65; white-space:pre-wrap; }
        .mockup-bilingual-field--large .mockup-bilingual-copy { min-height:190px; }
        .mockup-bilingual-copy:empty::before { content:attr(data-placeholder); color:var(--muted); font-style:italic; }
        .mockup-bilingual-copy:focus { outline:0; }

        .mockup-bilingual-memo { margin:0 14px 14px; padding:14px 6px 2px; border-top:1px solid var(--line); }
        .mockup-bilingual-memo summary { cursor:pointer; color:var(--muted); font-size:9px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; }
        .mockup-bilingual-memo .mockup-bilingual-copy { min-height:82px; }

        .mockup-social-editorial {
            margin-bottom:24px;
            border:1px solid var(--line);
            background:var(--surface);
        }

        .mockup-social-editorial > summary {
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:20px;
            padding:18px 20px;
            cursor:pointer;
            list-style:none;
        }

        .mockup-social-editorial > summary::-webkit-details-marker { display:none; }
        .mockup-social-editorial[open] .mockup-bilingual-state::after { content:'−'; }
        .mockup-social-channels { padding:0 14px 14px; border-top:1px solid var(--line); }

        .mockup-social-channel { border-bottom:1px solid var(--line); }
        .mockup-social-channel > summary { display:flex; align-items:center; justify-content:space-between; gap:16px; padding:16px 6px; cursor:pointer; list-style:none; }
        .mockup-social-channel > summary::-webkit-details-marker { display:none; }
        .mockup-social-channel-title { color:var(--ink); font:500 20px/1.15 var(--font-serif); }
        .mockup-social-channel-note { color:var(--muted); font-size:9px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; }
        .mockup-social-channel-note::after { content:'+'; display:inline-block; margin-left:12px; color:var(--accent); font:500 18px/1 var(--font-serif); }
        .mockup-social-channel[open] .mockup-social-channel-note::after { content:'−'; }

        .mockup-social-grid {
            display:grid;
            grid-template-columns:repeat(2,minmax(0,1fr));
            column-gap:12px;
            padding:0 0 14px;
        }

        .mockup-social-cell {
            min-width:0;
            padding:14px 18px;
            border-right:1px solid var(--line);
            border-left:1px solid var(--line);
            border-top:1px solid var(--line);
            background:var(--surface-soft);
        }

        .mockup-social-cell--source { grid-column:1; }
        .mockup-social-cell--english { grid-column:2; }
        .mockup-social-language { border-top:3px solid #c89aa1; }
        .mockup-social-language.mockup-social-cell--english { border-top-color:#9fb19a; }
        .mockup-social-cell--last { border-bottom:1px solid var(--line); }
        .mockup-social-cell label { display:block; color:var(--muted); font-size:9px; font-weight:700; letter-spacing:.07em; text-transform:uppercase; }
        .mockup-social-copy { min-height:58px; margin-top:9px; color:var(--ink); font-size:14px; line-height:1.65; white-space:pre-wrap; }
        .mockup-social-copy:empty::before { content:attr(data-placeholder); color:var(--muted); font-style:italic; }
        .mockup-social-copy:focus { outline:0; }

        .section-heading {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 18px;
            border-bottom: 1px dashed var(--line);
            padding-bottom: 14px;
            margin-bottom: 20px;
        }

        h1,
        h2,
        h3 {
            font-family: var(--font-serif);
            font-weight: 500;
            line-height: 1.2;
            margin: 0;
        }

        h2 {
            font-size: 30px;
        }

        h3 {
            font-size: 22px;
            margin-bottom: 10px;
        }

        p,
        small {
            color: var(--muted);
        }

        .pin-fields,
        .social-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        .pin-field,
        .social-card {
            background: var(--surface-soft);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 16px;
        }

        .pin-field.full {
            grid-column: 1 / -1;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: var(--ink);
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
        }

        textarea,
        input[type="text"] {
            width: 100%;
            box-sizing: border-box;
            border: 1px solid var(--line);
            background: var(--surface);
            color: var(--ink);
            border-radius: var(--radius);
            padding: 12px 14px;
            font: inherit;
            font-size: 13px;
        }

        textarea {
            min-height: 120px;
            resize: vertical;
        }

        .copy-block {
            white-space: pre-wrap;
            color: var(--ink);
            line-height: 1.7;
        }

        .keyword-wrap {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .keyword-chip {
            display: inline-flex;
            align-items: center;
            border: 1px solid var(--line);
            background: var(--surface);
            color: var(--ink);
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 12px;
        }

        .copy-button {
            display: inline-block;
            width: auto;
            margin: 12px 8px 0 0;
            border: 1px solid var(--line);
            background: transparent;
            color: var(--ink);
            padding: 8px 10px;
            text-decoration: none;
            font: inherit;
            font-weight: 700;
            font-size: 10px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            cursor: pointer;
            border-radius: var(--radius);
        }

        .copy-button:hover {
            background: #fbf7ef;
            border-color: var(--accent);
        }

        @media (max-width: 760px) {
            .stage {
                padding: 28px 18px;
                min-height: 60vh;
            }

            .nav-arrow {
                width: 44px;
                height: 68px;
                font-size: 42px;
            }

            .nav-arrow.prev {
                left: 8px;
            }

            .nav-arrow.next {
                right: 8px;
            }

            .viewer-caption {
                display: block;
                text-align: center;
            }

            .pin-fields,
            .social-grid {
                grid-template-columns: 1fr;
            }

            .mockup-bilingual-spread { grid-template-columns:1fr; grid-template-rows:none; }
            .mockup-bilingual-page { display:block; grid-column:auto; grid-row:auto; }
            .mockup-social-grid { grid-template-columns:1fr; }
            .mockup-social-cell { grid-column:1; }
        }
    </style>
    <link rel="stylesheet" href="media-controls.css?v=2">
</head>
<body>
    <header class="viewer-top">
        <div class="viewer-left">
            <a class="brand" href="root_album.php">Artwork Mockups <span class="brand-mark"></span></a>
        </div>
        <nav class="viewer-actions">
            <a class="icon-link back" href="<?= h($backUrl) ?>" aria-label="Back to details" title="Back to details"></a>
            <a href="mockups.php">Mockups</a>
            <?php if ($viewerMockupId > 0): ?>
                <a href="website_studio_notes.php?source=mockup:<?= $viewerMockupId ?>#new-studio-note">Create Studio Note</a>
            <?php endif; ?>
            <?php if ($viewerMockupId > 0): ?>
                <button
                    class="viewer-favorite-btn media-icon-button <?= $viewerIsFavorite ? 'active' : '' ?>"
                    type="button"
                    title="<?= $viewerIsFavorite ? 'Remove favorite' : 'Add favorite' ?>"
                    aria-label="<?= $viewerIsFavorite ? 'Remove favorite' : 'Add favorite' ?>"
                    data-favorite-mockup
                    data-mockup-id="<?= (int)$viewerMockupId ?>"
                ><svg class="media-action-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3.7 2.55 5.17 5.71.83-4.13 4.03.97 5.69L12 16.73l-5.1 2.69.97-5.69L3.74 9.7l5.71-.83L12 3.7Z"/></svg></button>
            <?php endif; ?>
            <a class="icon-link download media-icon-button" href="<?= h(download_url($mockup['mockup_file'])) ?>" aria-label="Download mockup" title="Download mockup"><svg class="media-action-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12M7.5 10.5 12 15l4.5-4.5M5 19h14"/></svg></a>
        </nav>
    </header>

    <?php if ($prevHref !== ''): ?>
        <a class="nav-arrow prev" href="<?= h($prevHref) ?>" aria-label="Previous image">&lsaquo;</a>
    <?php endif; ?>

    <main class="stage">
        <img src="<?= h(media_url($mockup['mockup_file'])) ?>" alt="Mockup">
    </main>

    <?php if ($nextHref !== ''): ?>
        <a class="nav-arrow next" href="<?= h($nextHref) ?>" aria-label="Next image">&rsaquo;</a>
    <?php endif; ?>

    <footer class="viewer-caption">
        <span><?= h(Display::contextTitle($mockup['context_id'])) ?></span>
        <span><?= h(date('m/d/Y H:i', strtotime((string)$mockup['created_at']))) ?></span>
    </footer>

    <section class="publication">
        <?php if(isset($_GET['mockup_v2_generated'])):?><div class="notice success">Mockup analysis v2 generated as a draft. Nothing was published.</div><?php endif;?>
        <?php if(isset($_GET['mockup_v2_error'])):?><div class="notice error"><?=h((string)$_GET['mockup_v2_error'])?></div><?php endif;?>
        <?php if($canUseSocial && $pinterestDraftNotice!==''):?><div class="notice success"><?=h($pinterestDraftNotice)?></div><?php endif;?>
        <?php if($canUseSocial && $pinterestDraftError!==''):?><div class="notice error"><?=h($pinterestDraftError)?></div><?php endif;?>
        <?php if($canUseSocial && $metaDraftNotice!==''):?><div class="notice success"><?=h($metaDraftNotice)?></div><?php endif;?>
        <?php if($canUseSocial && $metaDraftError!==''):?><div class="notice error"><?=h($metaDraftError)?></div><?php endif;?>
        <?php if($bilingualExperiment && $viewerMockupId>0): ?>
            <section class="mockup-bilingual-title" aria-label="Título universal del mockup">
                <span class="mockup-bilingual-label">Título universal</span>
                <h1 class="mockup-bilingual-heading" contenteditable="true" role="textbox" aria-label="Título del mockup"><?=h($mockupEditorialTitle)?></h1>
                <p class="mockup-bilingual-title-memo">STRATA X — LIMEN · MOCKUP I — NUHRĀ (ܢܘܗܪܐ) · no traducir</p>
            </section>

            <details class="mockup-bilingual-editorial">
                <summary>
                    <span class="mockup-bilingual-summary">
                        <strong>Espacio editorial</strong>
                        <span>Contenido original en español y versión publicada en inglés.</span>
                    </span>
                    <span class="mockup-bilingual-state">Español + English</span>
                </summary>
                <div class="mockup-bilingual-spread">
                    <article class="mockup-bilingual-page mockup-bilingual-page--source">
                        <span class="mockup-bilingual-language">Español · fuente</span>
                        <?php foreach($mockupEditorialFields as $field): ?>
                            <section class="mockup-bilingual-field <?=$field['large']?'mockup-bilingual-field--large':''?>">
                                <label><?=h($field['es'])?></label>
                                <div class="mockup-bilingual-copy" contenteditable="true" role="textbox" aria-multiline="true" data-placeholder="<?=h($field['es_placeholder'])?>"></div>
                            </section>
                        <?php endforeach; ?>
                    </article>
                    <article class="mockup-bilingual-page mockup-bilingual-page--english">
                        <span class="mockup-bilingual-language">English · current version</span>
                        <?php foreach($mockupEditorialFields as $field): ?>
                            <section class="mockup-bilingual-field <?=$field['large']?'mockup-bilingual-field--large':''?>">
                                <label><?=h($field['en'])?></label>
                                <div class="mockup-bilingual-copy" contenteditable="true" role="textbox" aria-multiline="true" data-placeholder="<?=h($field['en_placeholder'])?>"><?=h($field['value'])?></div>
                            </section>
                        <?php endforeach; ?>
                    </article>
                </div>
                <details class="mockup-bilingual-memo">
                    <summary>Memo privado del mockup</summary>
                    <div class="mockup-bilingual-copy" contenteditable="true" role="textbox" aria-multiline="true" data-placeholder="Ideas y decisiones específicas de esta escena…"></div>
                </details>
            </details>

            <details class="mockup-social-editorial">
                <summary>
                    <span class="mockup-bilingual-summary">
                        <strong>Publicación y redes</strong>
                        <span>Adaptaciones específicas de este mockup para cada canal.</span>
                    </span>
                    <span class="mockup-bilingual-state">5 canales</span>
                </summary>
                <div class="mockup-social-channels">
                    <?php foreach($mockupSocialChannels as $socialChannel): ?>
                        <details class="mockup-social-channel">
                            <summary>
                                <span class="mockup-social-channel-title"><?=h($socialChannel['label'])?></span>
                                <span class="mockup-social-channel-note">Español + English</span>
                            </summary>
                            <div class="mockup-social-grid">
                                <div class="mockup-social-cell mockup-social-cell--source mockup-social-language"><span class="mockup-bilingual-language">Español · fuente</span></div>
                                <div class="mockup-social-cell mockup-social-cell--english mockup-social-language"><span class="mockup-bilingual-language">English · current version</span></div>
                                <?php foreach($socialChannel['fields'] as $fieldIndex => $socialField): ?>
                                    <?php $lastSocialField = $fieldIndex === array_key_last($socialChannel['fields']); ?>
                                    <section class="mockup-social-cell mockup-social-cell--source <?=$lastSocialField?'mockup-social-cell--last':''?>">
                                        <label><?=h($socialField['es'])?></label>
                                        <div class="mockup-social-copy" contenteditable="true" role="textbox" aria-multiline="true" data-placeholder="Escribí la versión en español…"></div>
                                    </section>
                                    <section class="mockup-social-cell mockup-social-cell--english <?=$lastSocialField?'mockup-social-cell--last':''?>">
                                        <label><?=h($socialField['en'])?></label>
                                        <div class="mockup-social-copy" contenteditable="true" role="textbox" aria-multiline="true" data-placeholder="No English content is currently available."><?=h($socialField['value'])?></div>
                                    </section>
                                <?php endforeach; ?>
                            </div>
                        </details>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endif; ?>
        <?php if($viewerMockupId>0 && !$bilingualExperiment): ?>
            <section class="panel">
                <div class="section-heading">
                    <div><h2>Admin · Mockup Analysis v2</h2><p>Neutral scene analysis derived from the approved artwork identity and this exact mockup.</p></div>
                    <form method="post"><input type="hidden" name="action" value="generate_mockup_v2"><button type="submit"><?= $mockupV2 ? 'Regenerate v2' : 'Generate mockup analysis v2' ?></button></form>
                </div>
                <?php if($mockupV2): $neutral=(array)($mockupV2['neutral']??[]); $channels=(array)($mockupV2['channels']??[]); $scene=(array)($neutral['scene']??[]); ?>
                    <div class="pin-fields">
                        <div class="pin-field full"><label>Neutral mockup description</label><h3><?=h($neutral['context_title']??'')?></h3><p><?=h($neutral['contextual_description']??'')?></p></div>
                        <div class="pin-field"><label>Scene</label><p><?=h($scene['space_type']??'')?> · <?=h($scene['architecture']??'')?></p><p><?=h($scene['lighting']??'')?> · <?=h($scene['camera']??'')?></p></div>
                        <div class="pin-field"><label>Keywords / Long tails / Tags</label><p><?=h(implode(', ',(array)($neutral['keywords']??[])))?></p><p><?=h(implode(', ',(array)($neutral['long_tail_keywords']??[])))?></p><p><?=h(implode(', ',(array)($neutral['tags']??[])))?></p></div>
                    </div>
                    <?php if($canUseSocial): ?><div class="social-grid">
                        <?php foreach(['website'=>'Website','instagram'=>'Instagram','facebook'=>'Facebook','tiktok'=>'TikTok · future'] as $key=>$label): $channel=(array)($channels[$key]??[]); ?>
                            <article class="social-card">
                                <h3><?=h($label)?></h3>
                                <?php foreach($channel as $field=>$value): ?>
                                    <div style="margin-top:10px;">
                                        <strong style="display:block;font-size:9px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin-bottom:4px;"><?=h(str_replace('_',' ',$field))?></strong>
                                        <?php if(is_array($value)): ?>
                                            <?php if($field==='video_structure'||$field==='on_screen_text'): ?>
                                                <ol style="margin:0;padding-left:18px;font-size:12px;line-height:1.5;"><?php foreach($value as $item): ?><li><?=h(is_scalar($item)?$item:json_encode($item,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))?></li><?php endforeach; ?></ol>
                                            <?php else: ?>
                                                <div class="keyword-wrap"><?php foreach($value as $item): ?><span class="keyword-chip"><?=h(is_scalar($item)?$item:json_encode($item,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))?></span><?php endforeach; ?></div>
                                            <?php endif; ?>
                                        <?php elseif(is_bool($value)): ?>
                                            <span><?= $value ? 'Yes' : 'No' ?></span>
                                        <?php else: ?>
                                            <p class="copy-block" style="margin:0;white-space:pre-line;"><?=h((string)$value)?></p>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                                <?php if($key==='facebook'||$key==='instagram'): ?>
                                    <form method="post" action="meta_mockup_draft.php" style="margin-top:12px;">
                                        <input type="hidden" name="mockup_id" value="<?=$viewerMockupId?>"><input type="hidden" name="channel" value="<?=h($key)?>">
                                        <input type="hidden" name="csrf" value="<?=h($_SESSION['meta_draft_csrf'])?>">
                                        <label>Destination <?= $key==='instagram' ? '(optional; profile/link strategy)' : 'link' ?></label>
                                        <input type="url" name="destination_url" placeholder="https://mauriziovalch.com/artwork/..." <?= $key==='facebook' ? 'required' : '' ?>>
                                        <button type="submit" class="copy-button">Prepare <?=h(ucfirst($key))?> draft</button>
                                    </form>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div><?php endif; ?>
                    <details style="margin-top:14px;"><summary style="cursor:pointer;font-size:11px;color:var(--muted);">Technical JSON</summary><pre style="white-space:pre-wrap;overflow:auto;max-height:320px;font-size:10px;"><?=h(json_encode($mockupV2,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))?></pre></details>
                <?php else: ?><p>No v2 draft exists for this mockup yet.</p><?php endif; ?>
            </section>
        <?php endif; ?>
        <?php if($mockupV2 && $canUseSocial && !$bilingualExperiment): ?><section class="panel pinterest">
            <div class="section-heading">
                <div>
                    <h2>Pinterest Pin Content</h2>
                    <p>Optimized fields for this exact mockup image.</p>
                </div>
                <p><?= h($contextTitle) ?></p>
            </div>

            <div class="pin-fields">
                <div class="pin-field">
                    <label>Pinterest Board / Category Suggestion</label>
                    <p class="copy-block"><?= h($pinBoard) ?></p>
                </div>

                <div class="pin-field">
                    <label>Pin Title</label>
                    <p class="copy-block"><?= h($pinTitle) ?></p>
                    <button class="copy-button" type="button" data-copy="<?= h($pinTitle) ?>">Copy Pin Title</button>
                </div>

                <div class="pin-field full">
                    <label>Pin Description</label>
                    <textarea readonly><?= h($pinDescription) ?></textarea>
                    <button class="copy-button" type="button" data-copy="<?= h($pinDescription) ?>">Copy Pin Description</button>
                </div>

                <div class="pin-field full">
                    <label>Alt Text / Accessibility Text</label>
                    <textarea readonly><?= h($pinAlt) ?></textarea>
                    <small>Use this for Pinterest's “Explain what people can see in the Pin” field.</small>
                    <button class="copy-button" type="button" data-copy="<?= h($pinAlt) ?>">Copy Alt Text</button>
                </div>

                <form class="pin-field full" method="post" action="pinterest_mockup_draft.php">
                    <label>Destination Link</label>
                    <input type="hidden" name="csrf" value="<?=h($_SESSION['pinterest_draft_csrf'])?>"><input type="hidden" name="mockup_id" value="<?=$viewerMockupId?>">
                    <input id="destination_link" name="destination_url" type="url" required placeholder="https://artist-website.com/artwork">
                    <?php if($isAdmin):?><label>Pinterest identity</label><select name="purpose"><option value="artist">Artist account</option><option value="platform">Artwork Mockups platform account</option></select><?php else:?><input type="hidden" name="purpose" value="artist"><?php endif;?>
                    <small>The draft keeps this exact mockup content. Nothing is published.</small>
                    <button class="button-link secondary" type="submit">Prepare Pinterest draft</button>
                </form>

                <div class="pin-field">
                    <label>Suggested Pinterest Keywords</label>
                    <div class="keyword-wrap">
                        <?php foreach ($pinKeywords as $keyword): ?>
                            <span class="keyword-chip"><?= h($keyword) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <button class="copy-button" type="button" data-copy="<?= h(implode(', ', $pinKeywords)) ?>">Copy Keywords</button>
                </div>

                <div class="pin-field">
                    <label>Suggested Hashtags</label>
                    <p class="copy-block"><?= h(implode(' ', $pinHashtags)) ?></p>
                    <button class="copy-button" type="button" data-copy="<?= h(implode(' ', $pinHashtags)) ?>">Copy Hashtags</button>
                </div>
            </div>
        </section><?php endif; ?>

    </section>

    <script>
        document.querySelectorAll('[data-copy]').forEach((button) => {
            button.addEventListener('click', async () => {
                const original = button.textContent;
                try {
                    await navigator.clipboard.writeText(button.dataset.copy || '');
                    button.textContent = 'Copied';
                    setTimeout(() => button.textContent = original, 1200);
                } catch (error) {
                    button.textContent = 'Copy failed';
                    setTimeout(() => button.textContent = original, 1200);
                }
            });
        });

        document.querySelectorAll('[data-copy-source]').forEach((button) => {
            button.addEventListener('click', async () => {
                const original = button.textContent;
                const source = document.getElementById(button.dataset.copySource || '');
                try {
                    await navigator.clipboard.writeText(source ? source.value : '');
                    button.textContent = 'Copied';
                    setTimeout(() => button.textContent = original, 1200);
                } catch (error) {
                    button.textContent = 'Copy failed';
                    setTimeout(() => button.textContent = original, 1200);
                }
            });
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowLeft') {
                const prev = document.querySelector('.nav-arrow.prev');
                if (prev) window.location.href = prev.href;
            }

            if (event.key === 'ArrowRight') {
                const next = document.querySelector('.nav-arrow.next');
                if (next) window.location.href = next.href;
            }

            if (event.key === 'Escape') {
                window.location.href = <?= json_encode($backUrl, JSON_UNESCAPED_SLASHES) ?>;
            }
        });

        document.addEventListener('click', (event) => {
            const button = event.target.closest('[data-favorite-mockup]');
            if (!button) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();

            const body = new FormData();
            body.append('mockup_id', button.getAttribute('data-mockup-id') || '');
            button.disabled = true;

            fetch('toggle_mockup_favorite.php', { method: 'POST', body })
                .then(response => response.json().then(payload => ({ ok: response.ok, payload })))
                .then(result => {
                    if (!result.ok || !result.payload.ok) {
                        throw new Error(result.payload.error || 'Could not update favorite.');
                    }

                    button.classList.toggle('active', !!result.payload.favorite);
                    button.title = result.payload.favorite ? 'Remove favorite' : 'Add favorite';
                    button.setAttribute('aria-label', button.title);
                })
                .catch(error => alert(error.message || 'Could not update favorite.'))
                .finally(() => {
                    button.disabled = false;
                });
        });
    </script>
</body>
</html>

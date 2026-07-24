<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
$pdo = Database::connection();
$isAdmin = Auth::isAdmin($user);
$mockupId = max(0, (int)($_GET['id'] ?? 0));

header('X-Robots-Tag: noindex, nofollow', true);

function mbe_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function mbe_media(mixed $file, int $width = 0): string
{
    $file = basename((string)$file);
    if ($file === '') return '';
    $url = 'media.php?file=' . rawurlencode($file);
    return $width > 0 ? $url . '&thumb=1&w=' . max(240, min(1200, $width)) : $url;
}

function mbe_text(mixed $value): string
{
    if (is_array($value)) {
        return implode("\n", array_map(static fn (mixed $item): string => is_scalar($item)
            ? (string)$item
            : (string)json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $value));
    }
    if (is_bool($value)) return $value ? 'Yes' : 'No';
    return trim((string)$value);
}

$mockupSql = '
    SELECT m.*, a.final_title AS artwork_title, s.title AS series_title
    FROM mockups m
    LEFT JOIN artworks a ON a.id=m.source_artwork_id AND a.user_id=m.user_id
    LEFT JOIN artwork_series s ON s.id=COALESCE(m.series_id,a.series_id) AND s.user_id=m.user_id
    WHERE m.id=:id
';
$mockupParams = ['id' => $mockupId];
if (!$isAdmin) {
    $mockupSql .= ' AND m.user_id=:user_id';
    $mockupParams['user_id'] = (int)$user['id'];
}
$mockupStatement = $pdo->prepare($mockupSql . ' LIMIT 1');
$mockupStatement->execute($mockupParams);
$mockup = $mockupStatement->fetch(PDO::FETCH_ASSOC);
if (!is_array($mockup)) {
    http_response_code(404);
    exit('Mockup not found.');
}

$ownerId = (int)$mockup['user_id'];
$artworkId = (int)($mockup['source_artwork_id'] ?? 0);
$mockupFile = basename((string)$mockup['mockup_file']);
$contextTitle = Display::contextTitle((string)($mockup['context_id'] ?? 'Mockup'));
$bilingualEditorialService = new BilingualEditorialService($pdo);
if (!$bilingualEditorialService->isEnabled($ownerId) && !$isAdmin) {
    header('Location: viewer.php?id=' . $mockupId);
    exit;
}

$sheetStatement = $pdo->prepare('
    SELECT * FROM mockup_sheets
    WHERE user_id=:user_id AND (mockup_id=:mockup_id OR mockup_file=:mockup_file)
    ORDER BY updated_at DESC, id DESC LIMIT 1
');
$sheetStatement->execute([
    'user_id' => $ownerId,
    'mockup_id' => $mockupId,
    'mockup_file' => $mockupFile,
]);
$sheet = $sheetStatement->fetch(PDO::FETCH_ASSOC) ?: [];

$artworkTitle = trim((string)($mockup['artwork_title'] ?? ''));
$seriesTitle = trim((string)($mockup['series_title'] ?? ''));
$title = trim((string)($sheet['title'] ?? ''));
if ($title === '') $title = $artworkTitle !== '' ? $artworkTitle . ' · ' . $contextTitle : $contextTitle;
$mockupRelationshipParts = [];
if ($artworkTitle !== '') $mockupRelationshipParts[] = $artworkTitle;
if ($seriesTitle !== '') $mockupRelationshipParts[] = 'Serie ' . $seriesTitle;
$mockupRelationshipLine = implode(' · ', $mockupRelationshipParts);

$mockupSpanishEditorial = $bilingualEditorialService->get($ownerId, 'mockup', $mockupId, 'es');
$mockupSpanishContent = $mockupSpanishEditorial['content'];
$mockupEnglishEditorial = $bilingualEditorialService->get($ownerId, 'mockup', $mockupId, 'en');
$mockupEnglishContent = $mockupEnglishEditorial['content'];
$mockupEditorialHasText = static function (mixed $value) use (&$mockupEditorialHasText): bool {
    if (is_array($value)) {
        foreach ($value as $item) {
            if ($mockupEditorialHasText($item)) return true;
        }
        return false;
    }
    return trim((string)$value) !== '';
};
$mockupHasSpanishContent = $mockupEditorialHasText((array)$mockupSpanishContent);
$mockupEditorialStateLabel = ($mockupEnglishEditorial['status'] ?? '') === 'stale' ? 'English · actualizar' : (($mockupEnglishEditorial['status'] ?? '') === 'unprepared' ? 'English · pendiente' : 'ES + EN');

$editorialFields = [
    ['key'=>'description','es'=>'Descripción','en'=>'Description','large'=>true,'placeholder'=>'Escribí la descripción editorial de este mockup…','en_placeholder'=>'International English mockup description…'],
    ['key'=>'tags','es'=>'Tags de catálogo','en'=>'Catalogue tags','large'=>false,'placeholder'=>'Tipo, estilos, técnicas, materiales, soporte, color, superficie, formato y contexto…','en_placeholder'=>'Type, styles, techniques, materials, support, color, surface, format and context…'],
    ['key'=>'search_terms','es'=>'Búsquedas y long tails','en'=>'Searches and long tails','large'=>false,'placeholder'=>'Búsquedas amplias y específicas que usaría un comprador…','en_placeholder'=>'Broad and specific searches an international buyer would use…'],
    ['key'=>'seo_title','es'=>'Título SEO','en'=>'SEO title','large'=>false,'placeholder'=>'Obra + contexto claro + artista…','en_placeholder'=>'Artwork + clear context + artist…'],
    ['key'=>'seo_description','es'=>'Descripción SEO','en'=>'SEO description','large'=>false,'placeholder'=>'Descripción breve y útil para buscadores…','en_placeholder'=>'Useful international search-result description…'],
    ['key'=>'alt_text','es'=>'Texto alternativo','en'=>'Alt text','large'=>false,'placeholder'=>'Descripción visual accesible del mockup…','en_placeholder'=>'Accessible English visual description…'],
    ['key'=>'caption','es'=>'Pie de imagen','en'=>'Caption','large'=>false,'placeholder'=>'Texto breve para publicar este mockup…','en_placeholder'=>'International publication caption…'],
];

$socialSpecs = [
    'website'=>['Website',['description'=>['Descripción','Description'],'caption'=>['Caption','Caption'],'alt_text'=>['Texto alternativo','Alt text']]],
    'instagram'=>['Instagram',['hook'=>['Apertura','Hook'],'caption'=>['Caption','Caption'],'hashtags'=>['Hashtags','Hashtags'],'cta'=>['Llamada a la acción','Call to action']]],
    'facebook'=>['Facebook',['headline'=>['Titular','Headline'],'post_text'=>['Texto de publicación','Post text'],'link_description'=>['Descripción del enlace','Link description'],'cta'=>['Llamada a la acción','Call to action']]],
    'pinterest'=>['Pinterest',['title'=>['Título del pin','Pin title'],'description'=>['Descripción del pin','Pin description'],'board_suggestions'=>['Tableros sugeridos','Board suggestions'],'topic_suggestions'=>['Temas sugeridos','Topic suggestions'],'keywords'=>['Palabras clave','Keywords']]],
    'tiktok'=>['TikTok · video',['visual_hook'=>['Apertura visual','Visual hook'],'suggested_motion'=>['Movimiento sugerido','Suggested motion'],'sequence_role'=>['Función en la secuencia','Sequence role'],'caption_seed'=>['Base del caption','Caption seed'],'video_notes'=>['Notas de video','Video notes']]],
];
$socialChannels = [];
foreach ($socialSpecs as $key => [$label, $specs]) {
    $spanishChannel = (array)($mockupSpanishContent['social'][$key] ?? []);
    $englishChannel = (array)($mockupEnglishContent['social'][$key] ?? []);
    $fields = [];
    foreach ($specs as $fieldKey => [$es, $en]) {
        $fields[] = ['key'=>$fieldKey,'es'=>$es,'en'=>$en,'es_value'=>mbe_text($spanishChannel[$fieldKey] ?? ''),'en_value'=>mbe_text($englishChannel[$fieldKey] ?? '')];
    }
    $socialChannels[] = ['key'=>$key,'label'=>$label,'fields'=>$fields];
}

$relatedSql = '
    SELECT m.id,m.mockup_file,m.context_id,m.created_at
    FROM mockups m
    WHERE m.user_id=:user_id
';
$relatedParams = ['user_id' => $ownerId];
if ($artworkId > 0) {
    $relatedSql .= ' AND m.source_artwork_id=:artwork_id';
    $relatedParams['artwork_id'] = $artworkId;
}
$relatedSql .= ' ORDER BY m.created_at DESC,m.id DESC LIMIT 60';
$relatedStatement = $pdo->prepare($relatedSql);
$relatedStatement->execute($relatedParams);
$relatedMockups = $relatedStatement->fetchAll(PDO::FETCH_ASSOC);
$albumCountSql = 'SELECT COUNT(*) FROM mockups WHERE user_id=:user_id';
$albumCountParams = ['user_id' => $ownerId];
if ($artworkId > 0) {
    $albumCountSql .= ' AND source_artwork_id=:artwork_id';
    $albumCountParams['artwork_id'] = $artworkId;
}
$albumCountStatement = $pdo->prepare($albumCountSql);
$albumCountStatement->execute($albumCountParams);
$albumCount = (int)$albumCountStatement->fetchColumn();

$favoriteIds = MockupFavorites::idsForUser($ownerId);
$favoriteLookup = array_fill_keys($favoriteIds, true);
$favoriteMockups = [];
if ($favoriteIds) {
    $favoriteParams = ['user_id' => $ownerId];
    $favoritePlaceholders = [];
    foreach ($favoriteIds as $index => $favoriteId) {
        $key = 'favorite_' . $index;
        $favoriteParams[$key] = $favoriteId;
        $favoritePlaceholders[] = ':' . $key;
    }
    $favoriteArtworkFilter = '';
    if ($artworkId > 0) {
        $favoriteArtworkFilter = ' AND source_artwork_id=:artwork_id';
        $favoriteParams['artwork_id'] = $artworkId;
    }
    $favoriteStatement = $pdo->prepare('
        SELECT id,mockup_file,context_id,created_at
        FROM mockups
        WHERE user_id=:user_id AND id IN (' . implode(',', $favoritePlaceholders) . ')' . $favoriteArtworkFilter . '
    ');
    foreach ($favoriteParams as $key => $value) {
        $favoriteStatement->bindValue(':' . $key, $value, PDO::PARAM_INT);
    }
    $favoriteStatement->execute();
    $favoriteRows = [];
    foreach ($favoriteStatement->fetchAll(PDO::FETCH_ASSOC) as $favoriteRow) {
        $favoriteRows[(int)$favoriteRow['id']] = $favoriteRow;
    }
    foreach ($favoriteIds as $favoriteId) {
        if (isset($favoriteRows[$favoriteId])) $favoriteMockups[] = $favoriteRows[$favoriteId];
        if (count($favoriteMockups) >= 3) break;
    }
}
$viewerBack = 'mockup_bilingual_experiment.php?id=' . $mockupId;
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?= mbe_h($title) ?> · Mockup Sheet</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="media-controls.css?v=2">
    <link rel="stylesheet" href="bilingual-editorial.css?v=20260724-1">
    <style>
        .mockup-experiment-workspace{display:grid;gap:22px}
        .mockup-heading-group{min-width:0}
        .mockup-heading-group .mockup-page-header{margin-bottom:0}
        .mockup-page-header{display:flex;align-items:flex-start;justify-content:space-between;gap:18px}
        .mockup-title-block{width:min(1120px,100%)}
        .mockup-title-label{display:block;margin:0 0 15px;color:var(--muted);font-size:9px;font-weight:700;letter-spacing:.08em;text-transform:uppercase}
        .mockup-title-heading{margin:0;padding:0 0 14px;border-bottom:1px solid var(--line);color:var(--ink);font:500 clamp(42px,4.5vw,58px)/1.05 var(--font-serif);letter-spacing:-.01em;overflow-wrap:anywhere}
        .mockup-title-heading:focus{outline:0}
        .mockup-title-memo{margin:15px 0 0;color:var(--accent);font:italic 500 21px/1.5 var(--font-serif)}
        .mockup-title-relationship{margin:12px 0 0;color:#c4a15f;font:italic 500 26px/1.4 var(--font-serif)}
        .mockup-studio-note{display:inline-flex;flex:0 0 140px;width:140px;height:140px;box-sizing:border-box;align-items:center;justify-content:center;padding:18px;border:1px solid #94a88f;border-radius:4px;background:#9fb198;color:#fffdf8;box-shadow:0 8px 18px rgba(68,83,63,.12);font-size:11px;font-weight:800;letter-spacing:.09em;line-height:1.35;text-align:center;text-decoration:none;text-transform:uppercase}
        .mockup-studio-note:hover{border-color:#81987b;background:#8fa487;color:#fff}
        .mockup-overview-panel{padding:20px 22px}
        .mockup-overview-grid{display:grid;grid-template-columns:minmax(700px,1.65fr) minmax(420px,.95fr);gap:14px;align-items:start}
        .mockup-overview-main{display:grid;gap:14px;min-width:0;align-content:start}
        .mockup-favorites-card,.mockup-related-panel{min-width:0;padding:14px;border:1px solid var(--line);border-radius:var(--radius);background:var(--surface-soft)}
        .mockup-related-panel{align-self:start}
        .mockup-section-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:10px}
        .mockup-section-heading h2,.mockup-section-heading h3{margin:0}
        .mockup-section-heading h2{font:500 16px/1.2 var(--font-serif)}
        .mockup-section-heading h3{color:var(--muted);font:700 10px/1.2 var(--font-sans);letter-spacing:.08em;text-transform:uppercase}
        .mockup-section-count{color:var(--muted);font-size:9px;font-weight:400;letter-spacing:normal;text-transform:none}
        .related-mockups-upload-link{flex:0 0 auto;padding:4px 7px;border:1px solid var(--line);border-radius:999px;background:var(--surface);color:var(--accent);font-size:8px;font-weight:700;letter-spacing:.04em;text-decoration:none;text-transform:uppercase}
        .related-mockups-upload-link:hover{border-color:var(--accent)}
        .mockup-favorites-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;align-items:start}
        .mockup-favorite-card,.mockup-related-card{position:relative;display:block;min-width:0;background:var(--surface)}
        .mockup-favorite-card{border:1px solid var(--line);border-radius:3px;overflow:hidden}
        .mockup-favorite-card.is-current{border-color:var(--accent);box-shadow:0 0 0 1px var(--accent)}
        .mockup-favorite-card img{display:block;width:100%;height:auto;aspect-ratio:4/5;object-fit:cover}
        .mockup-favorite-caption{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:7px 8px;color:var(--muted);font-size:9px;text-transform:uppercase}
        .mockup-current-pill{position:absolute;z-index:2;top:8px;right:8px;padding:5px 8px;border:1px solid rgba(255,255,255,.72);border-radius:999px;background:rgba(154,123,86,.78);color:#fff;font-size:8px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;backdrop-filter:blur(8px)}
        .mockup-related-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}
        .mockup-related-card{padding:5px;border:1px solid var(--line);border-radius:3px}
        .mockup-related-card.is-current{border-color:var(--accent)}
        .mockup-related-card>a{display:block}
        .mockup-related-card img{display:block;width:100%;aspect-ratio:4/5;object-fit:cover}
        .mockup-card-action{position:absolute;z-index:3;right:5px;bottom:5px;left:5px;min-height:22px;padding:4px;border:1px solid rgba(255,255,255,.34);border-radius:3px;background:linear-gradient(180deg,rgba(83,86,87,.38),rgba(42,45,46,.48));color:rgba(255,255,255,.92);font-size:8px;font-weight:700;text-align:center;text-transform:uppercase;backdrop-filter:blur(9px) saturate(.72)}
        .mockup-favorite-card .mockup-card-action{bottom:31px}
        .mockup-favorite-toggle{position:absolute;z-index:4;top:8px;left:8px;width:28px;height:28px;min-height:28px;margin:0;padding:0;display:grid;place-items:center;border:1px solid rgba(255,255,255,.62);border-radius:50%;background:rgba(25,25,23,.38);color:#fff;box-shadow:none;backdrop-filter:blur(7px)}
        .mockup-related-card .mockup-favorite-toggle{top:9px;left:9px;width:22px;height:22px;min-height:22px}
        .mockup-favorite-toggle svg{width:14px;height:14px;fill:none;stroke:currentColor;stroke-width:1.6}
        .mockup-favorite-toggle.active{background:rgba(154,123,86,.82);color:#fff}
        .mockup-empty{min-height:300px;display:grid;place-items:center;border:1px dashed var(--line);color:var(--muted);font-size:13px;font-style:italic;text-align:center}
        .editorial-drawer{border:1px solid var(--line);border-radius:var(--radius);background:var(--surface)}
        .editorial-drawer>summary{display:flex;align-items:center;justify-content:space-between;gap:20px;padding:18px 20px;cursor:pointer;list-style:none}
        .editorial-drawer>summary::-webkit-details-marker{display:none}
        .editorial-summary strong{display:block;color:var(--ink);font:500 23px/1.1 var(--font-serif)}
        .editorial-summary span{display:block;margin-top:5px;color:var(--muted);font-size:12px}
        .editorial-state{color:var(--muted);font-size:9px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;white-space:nowrap}
        .editorial-state:after{content:'+';display:inline-block;margin-left:14px;color:var(--accent);font:500 22px/1 var(--font-serif);vertical-align:-2px}
        .editorial-drawer[open] .editorial-state:after{content:'−'}
        .editorial-spread{display:grid;grid-template-columns:minmax(0,1fr) 72px minmax(0,1fr);grid-template-rows:auto repeat(7,auto);padding:14px;border-top:1px solid var(--line)}
        .editorial-page{display:grid;grid-row:1/span 8;grid-template-rows:subgrid;min-width:0;padding:18px;border:1px solid var(--line);border-top:3px solid #c89aa1;background:var(--surface-soft)}
        .editorial-page--source{grid-column:1}
        .editorial-page--english{grid-column:3;border-top-color:#9fb19a}
        .bilingual-adaptation-arrow{grid-column:2;grid-row:1;align-self:start;justify-self:center;display:flex;align-items:center;justify-content:center;gap:4px;width:64px!important;min-width:64px;height:40px;min-height:40px;margin:8px 0 0!important;padding:0 7px;border:1px solid #d8c17e;border-radius:20px;background:#f1e4b5;color:#665735;box-shadow:none;cursor:pointer}
        .bilingual-adaptation-arrow[hidden]{display:none!important}
        .bilingual-adaptation-arrow span:not(.bilingual-adaptation-label){font-size:8px;font-weight:700;letter-spacing:.06em}
        .bilingual-adaptation-arrow svg{width:14px;height:14px;flex:0 0 14px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
        .bilingual-adaptation-arrow:disabled{cursor:wait;opacity:.55}
        .bilingual-adaptation-label{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
        .editorial-language{display:block;color:var(--muted);font-size:9px;font-weight:700;letter-spacing:.08em;text-transform:uppercase}
        .editorial-field{min-height:96px;margin-top:16px;padding-top:13px;border-top:1px solid var(--line)}
        .editorial-field--large{min-height:230px}
        .editorial-field label,.social-cell label{display:block;color:var(--muted);font-size:9px;font-weight:700;letter-spacing:.07em;text-transform:uppercase}
        .editorial-copy,.social-copy{min-height:62px;margin-top:10px;color:var(--ink);font-size:14px;line-height:1.65;white-space:pre-wrap}
        .editorial-field--large .editorial-copy{min-height:190px}
        .editorial-copy:empty:before,.social-copy:empty:before{content:attr(data-placeholder);color:var(--muted);font-style:italic}
        .editorial-copy:focus,.social-copy:focus{outline:0}
        .editorial-memo{margin:0 14px 14px;padding:14px 6px 2px;border-top:1px solid var(--line)}
        .editorial-memo summary{cursor:pointer;color:var(--muted);font-size:9px;font-weight:700;letter-spacing:.08em;text-transform:uppercase}
        .bilingual-publication-bar{display:flex;align-items:center;justify-content:space-between;gap:18px;margin:0 14px 14px;padding:14px 18px;border-top:1px solid var(--line);background:#fbf7e8}
        .bilingual-publication-bar span{color:var(--muted);font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase}
        .bilingual-publication-bar button{min-height:38px;margin:0;padding:9px 16px;border:1px solid #d8c17e;border-radius:3px;background:#ead99f;color:#554a30;box-shadow:none;font-size:10px;font-weight:700;letter-spacing:.05em;text-transform:uppercase}
        .social-channels{padding:0 14px 14px;border-top:1px solid var(--line)}
        .social-channel{border-bottom:1px solid var(--line)}
        .social-channel>summary{display:flex;justify-content:space-between;gap:16px;padding:16px 6px;cursor:pointer;list-style:none}
        .social-channel>summary::-webkit-details-marker{display:none}
        .social-channel-title{color:var(--ink);font:500 20px/1.15 var(--font-serif)}
        .social-channel-note{color:var(--muted);font-size:9px;font-weight:700;letter-spacing:.08em;text-transform:uppercase}
        .social-channel-note:after{content:'+';margin-left:12px;color:var(--accent);font:500 18px/1 var(--font-serif)}
        .social-channel[open] .social-channel-note:after{content:'−'}
        .social-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));padding-bottom:14px}
        .social-cell{min-width:0;padding:14px 18px;border:1px solid var(--line);border-bottom:0;background:var(--surface-soft)}
        .social-cell--source{grid-column:1}.social-cell--english{grid-column:2}.social-language{border-top:3px solid #c89aa1}.social-cell--english.social-language{border-top-color:#9fb19a}.social-cell--last{border-bottom:1px solid var(--line)}
        @media(max-width:1100px){.mockup-overview-grid{grid-template-columns:1fr}.mockup-related-grid{grid-template-columns:repeat(5,minmax(0,1fr))}}
        @media(max-width:760px){.mockup-page-header{display:block}.mockup-studio-note{width:112px;height:112px;margin-top:18px}.mockup-favorites-grid{grid-template-columns:1fr}.mockup-related-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.editorial-spread,.social-grid{grid-template-columns:1fr;grid-template-rows:none}.editorial-page{display:block;grid-column:auto;grid-row:auto}.bilingual-adaptation-arrow{grid-column:1;grid-row:auto;margin:10px 0!important}.social-cell{grid-column:1}}
    </style>
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="main-area">
        <header class="app-header"><a class="user-chip" href="account.php"><?= mbe_h($user['email'] ?? '') ?></a></header>
        <div class="workspace mockup-experiment-workspace" data-bilingual-editor data-entity-type="mockup" data-entity-id="<?= $mockupId ?>" data-english-status="<?= mbe_h((string)($mockupEnglishEditorial['status'] ?? 'unprepared')) ?>" data-csrf="<?= mbe_h(Auth::csrfToken('bilingual_editorial')) ?>" data-endpoint="bilingual_editorial.php">
            <div class="mockup-heading-group">
            <div class="workspace-header mockup-page-header">
                <div class="mockup-title-block">
                    <span class="mockup-title-label">Título universal</span>
                    <h1 class="mockup-title-heading" contenteditable="true" role="textbox" data-universal-title aria-label="Título del mockup"><?= mbe_h($title) ?></h1>
                    <p class="mockup-title-memo">STRATA X — LIMEN · MOCKUP I — NUHRĀ (ܢܘܗܪܐ) · no traducir</p>
                </div>
                <a class="mockup-studio-note" href="viewer.php?id=<?= $mockupId ?>&amp;back=<?= rawurlencode($viewerBack) ?>">Open Viewer</a>
            </div>
            <?php if ($mockupRelationshipLine !== ''): ?><p class="mockup-title-relationship"><?= mbe_h($mockupRelationshipLine) ?></p><?php endif; ?>
            </div>

            <section class="panel mockup-overview-panel" aria-label="Mockup album">
                <div class="mockup-overview-grid">
                    <div class="mockup-overview-main">
                    <section class="mockup-favorites-card">
                        <div class="mockup-section-heading"><h2>Favorite Mockups</h2><span class="mockup-section-count"><?= count($favoriteMockups) ?> selected</span></div>
                        <?php if ($favoriteMockups): ?>
                            <div class="mockup-favorites-grid">
                                <?php foreach ($favoriteMockups as $favorite): ?>
                                    <?php $favoriteId=(int)$favorite['id']; $favoriteLabel=Display::contextTitle((string)$favorite['context_id']); ?>
                                    <article class="mockup-favorite-card <?= $favoriteId===$mockupId?'is-current':'' ?>">
                                        <a href="mockup_bilingual_experiment.php?id=<?= $favoriteId ?>" aria-label="Abrir ficha de <?= mbe_h($favoriteLabel) ?>">
                                            <img src="<?= mbe_h(mbe_media($favorite['mockup_file'], 900)) ?>" alt="<?= mbe_h($favoriteLabel) ?>">
                                        </a>
                                        <?php if ($favoriteId===$mockupId): ?><span class="mockup-current-pill">Current</span><?php endif; ?>
                                        <button class="mockup-favorite-toggle active" type="button" title="Remove favorite" aria-label="Remove favorite" data-favorite-mockup data-mockup-id="<?= $favoriteId ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3.7 2.55 5.17 5.71.83-4.13 4.03.97 5.69L12 16.73l-5.1 2.69.97-5.69L3.74 9.7l5.71-.83L12 3.7Z"/></svg></button>
                                        <a class="mockup-card-action" href="viewer.php?id=<?= $favoriteId ?>&amp;back=<?= rawurlencode('mockup_bilingual_experiment.php?id='.$favoriteId) ?>">Viewer</a>
                                        <div class="mockup-favorite-caption"><span><?= mbe_h($favoriteLabel) ?></span><span>#<?= $favoriteId ?></span></div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="mockup-empty">Marcá tus mejores mockups con la estrella.<br>Van a aparecer aquí en gran formato.</div>
                        <?php endif; ?>
                    </section>

            <details class="editorial-drawer">
                <summary><span class="editorial-summary"><strong>Espacio editorial</strong><span>Español maestro e inglés internacional para publicación.</span></span><span class="editorial-state" data-bilingual-save-state><?= mbe_h($mockupEditorialStateLabel) ?></span></summary>
                <div class="editorial-spread">
                    <article class="editorial-page editorial-page--source"><span class="editorial-language">Español · fuente</span><?php foreach($editorialFields as $field): ?><section class="editorial-field <?= $field['large']?'editorial-field--large':'' ?>"><label><?= mbe_h($field['es']) ?></label><div class="editorial-copy" contenteditable="true" role="textbox" aria-multiline="true" data-editorial-locale="es" data-editorial-field="<?= mbe_h($field['key']) ?>" data-placeholder="<?= mbe_h($field['placeholder']) ?>"><?= mbe_h($mockupSpanishContent[$field['key']] ?? '') ?></div></section><?php endforeach; ?></article>
                    <button class="bilingual-adaptation-arrow" type="button" data-editorial-adapt hidden>
                        <span data-adaptation-source-short>ES</span>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M14 7l5 5-5 5"/></svg>
                        <span data-adaptation-target-short>EN</span>
                        <span class="bilingual-adaptation-label" data-adaptation-label>Adaptar al inglés internacional sin traducción literal</span>
                    </button>
                    <article class="editorial-page editorial-page--english"><span class="editorial-language">English · publicación internacional</span><?php foreach($editorialFields as $field): ?><section class="editorial-field <?= $field['large']?'editorial-field--large':'' ?>"><label><?= mbe_h($field['en']) ?></label><div class="editorial-copy" contenteditable="true" role="textbox" aria-multiline="true" data-editorial-locale="en" data-editorial-field="<?= mbe_h($field['key']) ?>" data-placeholder="<?= mbe_h($field['en_placeholder']) ?>"><?= mbe_h($mockupEnglishContent[$field['key']] ?? '') ?></div></section><?php endforeach; ?></article>
                </div>
                <div class="bilingual-preparation-bar">
                    <div>
                        <strong><?= $mockupHasSpanishContent ? 'Preparar website y canales' : 'Preparar contenido' ?></strong>
                        <span><?= $mockupHasSpanishContent ? 'Regenera y guarda el inglés internacional desde el español maestro.' : 'Genera el español maestro, los canales y el inglés internacional en una sola acción.' ?></span>
                    </div>
                    <?php if ($mockupHasSpanishContent): ?>
                        <button type="button" data-editorial-refresh>Preparar website y canales</button>
                    <?php else: ?>
                        <button type="button" data-editorial-generate>Preparar contenido</button>
                    <?php endif; ?>
                </div>
                <details class="editorial-memo"><summary>Memo privado del mockup</summary><div class="editorial-copy" contenteditable="true" role="textbox" aria-multiline="true" data-private-memo data-editorial-locale="es" data-placeholder="Ideas y decisiones específicas de esta escena…"><?= mbe_h($mockupSpanishEditorial['private_memo'] ?? '') ?></div></details>
            </details>

            <details class="editorial-drawer">
                <summary><span class="editorial-summary"><strong>Publicación y redes</strong><span>Adaptaciones específicas de este mockup para cada canal.</span></span><span class="editorial-state">5 canales</span></summary>
                <div class="social-channels">
                    <?php foreach($socialChannels as $socialChannel): ?><details class="social-channel"><summary><span class="social-channel-title"><?= mbe_h($socialChannel['label']) ?></span><span class="social-channel-note">ES + EN</span></summary><div class="social-grid"><div class="social-cell social-cell--source social-language"><span class="editorial-language">Español · fuente</span></div><div class="social-cell social-cell--english social-language"><span class="editorial-language">English · publicación</span></div><?php foreach($socialChannel['fields'] as $index=>$field): ?><?php $last=$index===array_key_last($socialChannel['fields']); $path='social.'.$socialChannel['key'].'.'.$field['key']; ?><section class="social-cell social-cell--source <?= $last?'social-cell--last':'' ?>"><label><?= mbe_h($field['es']) ?></label><div class="social-copy" contenteditable="true" role="textbox" data-editorial-locale="es" data-editorial-field="<?= mbe_h($path) ?>" data-placeholder="Escribí la versión en español…"><?= mbe_h($field['es_value']) ?></div></section><section class="social-cell social-cell--english <?= $last?'social-cell--last':'' ?>"><label><?= mbe_h($field['en']) ?></label><div class="social-copy" contenteditable="true" role="textbox" data-editorial-locale="en" data-editorial-field="<?= mbe_h($path) ?>" data-placeholder="International English version…"><?= mbe_h($field['en_value']) ?></div></section><?php endforeach; ?></div></details><?php endforeach; ?>
                </div>
            </details>
                    </div>

                    <aside class="mockup-related-panel">
                        <div class="mockup-section-heading"><h3>Mockup Album <span class="mockup-section-count">· <?= $albumCount ?></span></h3><a class="related-mockups-upload-link" href="mockup_upload.php?id=<?= $artworkId ?>">+ Import</a></div>
                        <div class="mockup-related-grid">
                            <?php foreach ($relatedMockups as $related): ?>
                                <?php $relatedId=(int)$related['id']; $relatedLabel=Display::contextTitle((string)$related['context_id']); $isFavorite=isset($favoriteLookup[$relatedId]); ?>
                                <article class="mockup-related-card <?= $relatedId===$mockupId?'is-current':'' ?>">
                                    <a href="mockup_bilingual_experiment.php?id=<?= $relatedId ?>" aria-label="Abrir ficha de <?= mbe_h($relatedLabel) ?>"><img src="<?= mbe_h(mbe_media($related['mockup_file'], 520)) ?>" alt="<?= mbe_h($relatedLabel) ?>" loading="lazy"></a>
                                    <button class="mockup-favorite-toggle <?= $isFavorite?'active':'' ?>" type="button" title="<?= $isFavorite?'Remove favorite':'Add favorite' ?>" aria-label="<?= $isFavorite?'Remove favorite':'Add favorite' ?>" data-favorite-mockup data-mockup-id="<?= $relatedId ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3.7 2.55 5.17 5.71.83-4.13 4.03.97 5.69L12 16.73l-5.1 2.69.97-5.69L3.74 9.7l5.71-.83L12 3.7Z"/></svg></button>
                                    <?php if ($relatedId===$mockupId): ?><a class="mockup-card-action" href="viewer.php?id=<?= $relatedId ?>&amp;back=<?= rawurlencode($viewerBack) ?>">Viewer</a><?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </aside>
                </div>
            </section>
        </div>
    </main>
</div>
<script src="bilingual-editorial.js?v=20260724-1"></script>
<script>
document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-favorite-mockup]');
    if (!button || button.disabled) return;
    event.preventDefault();
    button.disabled = true;
    const body = new FormData();
    body.append('mockup_id', button.dataset.mockupId || '');
    try {
        const response = await fetch('toggle_mockup_favorite.php', {method:'POST', body});
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.error || 'Could not update favorite.');
        window.location.reload();
    } catch (error) {
        window.alert(error.message || 'Could not update favorite.');
        button.disabled = false;
    }
});
</script>
</body>
</html>

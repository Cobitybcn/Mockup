<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
$pdo = Database::connection();
$isAdmin = Auth::isAdmin($user);
$mockupId = max(0, (int)($_GET['id'] ?? 0));

header('X-Robots-Tag: noindex, nofollow', true);

function mockup_experiment_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function mockup_experiment_media(string $file): string
{
    $file = basename($file);
    return $file === '' ? '' : 'media.php?file=' . rawurlencode($file);
}

$mockupSql = 'SELECT * FROM mockups WHERE id = :id';
$mockupParams = ['id' => $mockupId];
if (!$isAdmin) {
    $mockupSql .= ' AND user_id = :user_id';
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
$mockupFile = basename((string)$mockup['mockup_file']);
$artworkId = (int)($mockup['source_artwork_id'] ?? 0);
$artwork = [];
if ($artworkId > 0) {
    $statement = $pdo->prepare('SELECT * FROM artworks WHERE id = :id AND user_id = :user_id LIMIT 1');
    $statement->execute(['id' => $artworkId, 'user_id' => $ownerId]);
    $artwork = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
}

$sheetStatement = $pdo->prepare('
    SELECT *
    FROM mockup_sheets
    WHERE user_id = :user_id
      AND (mockup_id = :mockup_id OR mockup_file = :mockup_file)
    ORDER BY updated_at DESC, id DESC
    LIMIT 1
');
$sheetStatement->execute([
    'user_id' => $ownerId,
    'mockup_id' => $mockupId,
    'mockup_file' => $mockupFile,
]);
$sheet = $sheetStatement->fetch(PDO::FETCH_ASSOC) ?: [];

$contextTitle = Display::contextTitle((string)($mockup['context_id'] ?? 'Mockup'));
$artworkTitle = trim((string)($artwork['final_title'] ?? ''));
$title = trim((string)($sheet['title'] ?? '')) ?: ($artworkTitle !== '' ? $artworkTitle . ' · ' . $contextTitle : $contextTitle);
$generated = json_decode((string)($sheet['generated_json'] ?? ''), true);
$generated = is_array($generated) ? $generated : [];
$analysis = is_array($generated['mockup_analysis_v2'] ?? null) ? (array)$generated['mockup_analysis_v2'] : [];
$channels = is_array($analysis['channels'] ?? null) ? (array)$analysis['channels'] : [];

$editorialFields = [
    ['es' => 'Descripción', 'en' => 'Description', 'value' => (string)($sheet['description'] ?? ''), 'large' => true, 'es_placeholder' => 'Escribí la descripción editorial de este mockup…'],
    ['es' => 'Palabras clave', 'en' => 'Keywords', 'value' => (string)($sheet['keywords'] ?? ''), 'large' => false, 'es_placeholder' => 'Escena, arquitectura, luz y atmósfera…'],
    ['es' => 'Etiquetas', 'en' => 'Tags', 'value' => (string)($sheet['tags'] ?? ''), 'large' => false, 'es_placeholder' => 'Etiquetas editoriales…'],
    ['es' => 'Texto alternativo', 'en' => 'Alt text', 'value' => (string)($sheet['alt_text'] ?? ''), 'large' => false, 'es_placeholder' => 'Descripción visual accesible del mockup…'],
    ['es' => 'Caption', 'en' => 'Caption', 'value' => (string)($sheet['caption'] ?? ''), 'large' => false, 'es_placeholder' => 'Texto breve para publicar este mockup…'],
];

$socialSpecs = [
    'website' => ['Website', ['description' => ['Descripción', 'Description'], 'caption' => ['Caption', 'Caption'], 'alt_text' => ['Texto alternativo', 'Alt text'], 'seo_keywords' => ['Palabras clave SEO', 'SEO keywords'], 'long_tail_keywords' => ['Términos de búsqueda', 'Long-tail keywords']]],
    'instagram' => ['Instagram', ['hook' => ['Apertura', 'Hook'], 'caption' => ['Caption', 'Caption'], 'hashtags' => ['Hashtags', 'Hashtags'], 'cta' => ['Llamada a la acción', 'Call to action']]],
    'facebook' => ['Facebook', ['headline' => ['Titular', 'Headline'], 'post_text' => ['Texto de publicación', 'Post text'], 'link_description' => ['Descripción del enlace', 'Link description'], 'cta' => ['Llamada a la acción', 'Call to action']]],
    'pinterest' => ['Pinterest', ['title' => ['Título del pin', 'Pin title'], 'description' => ['Descripción del pin', 'Pin description'], 'board_suggestions' => ['Tableros sugeridos', 'Board suggestions'], 'topic_suggestions' => ['Temas sugeridos', 'Topic suggestions'], 'keywords' => ['Palabras clave', 'Keywords']]],
    'tiktok' => ['TikTok · video', ['visual_hook' => ['Apertura visual', 'Visual hook'], 'suggested_motion' => ['Movimiento sugerido', 'Suggested motion'], 'sequence_role' => ['Función en la secuencia', 'Sequence role'], 'caption_seed' => ['Base del caption', 'Caption seed'], 'video_notes' => ['Notas de video', 'Video notes']]],
];
$normalizeValue = static function (mixed $value): string {
    if (is_array($value)) {
        return implode("\n", array_map(static fn(mixed $item): string => is_scalar($item) ? (string)$item : (string)json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $value));
    }
    if (is_bool($value)) return $value ? 'Yes' : 'No';
    return trim((string)$value);
};
$socialChannels = [];
foreach ($socialSpecs as $key => [$label, $fieldSpecs]) {
    $channel = (array)($channels[$key] ?? []);
    $fields = [];
    foreach ($fieldSpecs as $fieldKey => [$spanishLabel, $englishLabel]) {
        $fields[] = ['es' => $spanishLabel, 'en' => $englishLabel, 'value' => $normalizeValue($channel[$fieldKey] ?? '')];
    }
    $socialChannels[] = ['label' => $label, 'fields' => $fields];
}

$relatedStatement = $pdo->prepare('
    SELECT id, mockup_file, context_id
    FROM mockups
    WHERE user_id = :user_id
      AND source_artwork_id = :artwork_id
    ORDER BY created_at DESC, id DESC
    LIMIT 40
');
$relatedStatement->execute(['user_id' => $ownerId, 'artwork_id' => $artworkId]);
$relatedMockups = $relatedStatement->fetchAll(PDO::FETCH_ASSOC);
$viewerBack = 'mockup_bilingual_experiment.php?id=' . $mockupId;
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?= mockup_experiment_h($title) ?> · Mockup Sheet</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="media-controls.css?v=2">
    <style>
        .mockup-sheet-experiment { display:grid; gap:22px; }
        .mockup-sheet-header { display:flex; align-items:flex-start; justify-content:space-between; gap:18px; }
        .mockup-sheet-title { width:min(1120px,100%); }
        .mockup-sheet-label { display:block; margin:0 0 15px; color:var(--muted); font-size:9px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; }
        .mockup-sheet-heading { margin:0; padding:0 0 14px; border-bottom:1px solid var(--line); color:var(--ink); font:500 clamp(42px,4.5vw,58px)/1.05 var(--font-serif); letter-spacing:-.01em; overflow-wrap:anywhere; }
        .mockup-sheet-heading:focus { outline:0; }
        .mockup-sheet-title-memo { margin:15px 0 0; color:var(--accent); font:italic 500 21px/1.5 var(--font-serif); }
        .mockup-sheet-viewer-link { display:inline-flex; flex:0 0 140px; width:140px; height:140px; box-sizing:border-box; align-items:center; justify-content:center; padding:18px; border:1px solid #9fb19a; border-radius:3px; background:#a9bca4; color:#fff; font-size:11px; font-weight:700; line-height:1.2; text-align:center; text-decoration:none; text-transform:uppercase; }
        .mockup-sheet-viewer-link:hover { background:#8fa487; color:#fff; }
        .mockup-sheet-overview { display:grid; grid-template-columns:minmax(0,1fr) 360px; gap:12px; padding:14px; border:1px solid var(--line); background:var(--surface); }
        .mockup-sheet-image { display:grid; place-items:center; min-height:520px; background:var(--surface-soft); overflow:hidden; }
        .mockup-sheet-image img { display:block; width:100%; height:100%; max-height:680px; object-fit:contain; }
        .mockup-sheet-album { min-width:0; padding:12px; border:1px solid var(--line); background:var(--surface-soft); }
        .mockup-sheet-album-head { display:flex; align-items:baseline; justify-content:space-between; gap:12px; margin-bottom:10px; }
        .mockup-sheet-album h2 { margin:0; font:500 20px/1.1 var(--font-serif); }
        .mockup-sheet-album-count { color:var(--muted); font-size:9px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; }
        .mockup-sheet-album-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:8px; max-height:620px; overflow:auto; }
        .mockup-sheet-thumb { position:relative; display:block; border:1px solid var(--line); background:var(--surface); }
        .mockup-sheet-thumb.is-current { border-color:var(--accent); box-shadow:inset 0 -3px var(--accent); }
        .mockup-sheet-thumb img { display:block; width:100%; aspect-ratio:4/5; object-fit:cover; }
        .mockup-sheet-thumb span { position:absolute; right:5px; bottom:5px; left:5px; padding:5px; background:rgba(255,255,255,.88); color:var(--muted); font-size:8px; font-weight:700; text-transform:uppercase; }
        .editorial-drawer { border:1px solid var(--line); background:var(--surface); }
        .editorial-drawer > summary { display:flex; align-items:center; justify-content:space-between; gap:20px; padding:18px 20px; cursor:pointer; list-style:none; }
        .editorial-drawer > summary::-webkit-details-marker { display:none; }
        .editorial-summary strong { display:block; color:var(--ink); font:500 23px/1.1 var(--font-serif); }
        .editorial-summary span { display:block; margin-top:5px; color:var(--muted); font-size:12px; }
        .editorial-state { color:var(--muted); font-size:9px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; white-space:nowrap; }
        .editorial-state::after { content:'+'; display:inline-block; margin-left:14px; color:var(--accent); font:500 22px/1 var(--font-serif); vertical-align:-2px; }
        .editorial-drawer[open] .editorial-state::after { content:'−'; }
        .editorial-spread { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); grid-template-rows:auto repeat(5,auto); column-gap:12px; row-gap:0; padding:14px; border-top:1px solid var(--line); }
        .editorial-page { display:grid; grid-row:1/span 6; grid-template-rows:subgrid; min-width:0; padding:18px; border:1px solid var(--line); border-top:3px solid #c89aa1; background:var(--surface-soft); }
        .editorial-page--source { grid-column:1; }
        .editorial-page--english { grid-column:2; border-top-color:#9fb19a; }
        .editorial-language { display:block; color:var(--muted); font-size:9px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; }
        .editorial-field { min-height:96px; margin-top:16px; padding-top:13px; border-top:1px solid var(--line); }
        .editorial-field--large { min-height:230px; }
        .editorial-field label,.social-cell label { display:block; color:var(--muted); font-size:9px; font-weight:700; letter-spacing:.07em; text-transform:uppercase; }
        .editorial-copy,.social-copy { min-height:62px; margin-top:10px; color:var(--ink); font-size:14px; line-height:1.65; white-space:pre-wrap; }
        .editorial-field--large .editorial-copy { min-height:190px; }
        .editorial-copy:empty::before,.social-copy:empty::before { content:attr(data-placeholder); color:var(--muted); font-style:italic; }
        .editorial-copy:focus,.social-copy:focus { outline:0; }
        .social-channels { padding:0 14px 14px; border-top:1px solid var(--line); }
        .social-channel { border-bottom:1px solid var(--line); }
        .social-channel > summary { display:flex; justify-content:space-between; gap:16px; padding:16px 6px; cursor:pointer; list-style:none; }
        .social-channel > summary::-webkit-details-marker { display:none; }
        .social-channel-title { color:var(--ink); font:500 20px/1.15 var(--font-serif); }
        .social-channel-note { color:var(--muted); font-size:9px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; }
        .social-channel-note::after { content:'+'; margin-left:12px; color:var(--accent); font:500 18px/1 var(--font-serif); }
        .social-channel[open] .social-channel-note::after { content:'−'; }
        .social-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); column-gap:12px; padding-bottom:14px; }
        .social-cell { min-width:0; padding:14px 18px; border:1px solid var(--line); border-bottom:0; background:var(--surface-soft); }
        .social-cell--source { grid-column:1; }
        .social-cell--english { grid-column:2; }
        .social-language { border-top:3px solid #c89aa1; }
        .social-language.social-cell--english { border-top-color:#9fb19a; }
        .social-cell--last { border-bottom:1px solid var(--line); }
        @media(max-width:980px) { .mockup-sheet-overview{grid-template-columns:1fr}.mockup-sheet-album-grid{grid-template-columns:repeat(4,minmax(0,1fr));max-height:none} }
        @media(max-width:760px) { .mockup-sheet-header{display:block}.mockup-sheet-viewer-link{width:112px;height:112px;margin-top:18px}.mockup-sheet-image{min-height:320px}.editorial-spread,.social-grid{grid-template-columns:1fr;grid-template-rows:none}.editorial-page{display:block;grid-column:auto;grid-row:auto}.social-cell{grid-column:1}.mockup-sheet-album-grid{grid-template-columns:repeat(2,minmax(0,1fr))} }
    </style>
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="main-area">
        <header class="app-header"><a class="user-chip" href="account.php"><?= mockup_experiment_h($user['email'] ?? '') ?></a></header>
        <div class="workspace mockup-sheet-experiment">
            <div class="workspace-header mockup-sheet-header">
                <div class="mockup-sheet-title">
                    <span class="mockup-sheet-label">Título universal</span>
                    <h1 class="mockup-sheet-heading" contenteditable="true" role="textbox" aria-label="Título del mockup"><?= mockup_experiment_h($title) ?></h1>
                    <p class="mockup-sheet-title-memo">STRATA X — LIMEN · MOCKUP I — NUHRĀ (ܢܘܗܪܐ) · no traducir</p>
                </div>
                <a class="mockup-sheet-viewer-link" href="viewer.php?id=<?= $mockupId ?>&amp;viewer_final=1&amp;back=<?= rawurlencode($viewerBack) ?>">Open Viewer</a>
            </div>

            <section class="mockup-sheet-overview" aria-label="Mockup and album">
                <a class="mockup-sheet-image" href="viewer.php?id=<?= $mockupId ?>&amp;viewer_final=1&amp;back=<?= rawurlencode($viewerBack) ?>">
                    <img src="<?= mockup_experiment_h(mockup_experiment_media($mockupFile)) ?>" alt="<?= mockup_experiment_h($title) ?>">
                </a>
                <aside class="mockup-sheet-album">
                    <div class="mockup-sheet-album-head"><h2>Mockup Album</h2><span class="mockup-sheet-album-count"><?= count($relatedMockups) ?> mockups</span></div>
                    <div class="mockup-sheet-album-grid">
                        <?php foreach ($relatedMockups as $related): ?>
                            <?php $relatedId=(int)$related['id']; $relatedFile=basename((string)$related['mockup_file']); ?>
                            <a class="mockup-sheet-thumb <?= $relatedId===$mockupId?'is-current':'' ?>" href="mockup_bilingual_experiment.php?id=<?= $relatedId ?>">
                                <img src="<?= mockup_experiment_h(mockup_experiment_media($relatedFile)) ?>" alt="<?= mockup_experiment_h(Display::contextTitle((string)$related['context_id'])) ?>" loading="lazy">
                                <span><?= mockup_experiment_h(Display::contextTitle((string)$related['context_id'])) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </aside>
            </section>

            <details class="editorial-drawer">
                <summary><span class="editorial-summary"><strong>Espacio editorial</strong><span>Contenido original en español y versión publicada en inglés.</span></span><span class="editorial-state">Español + English</span></summary>
                <div class="editorial-spread">
                    <article class="editorial-page editorial-page--source"><span class="editorial-language">Español · fuente</span><?php foreach($editorialFields as $field): ?><section class="editorial-field <?=$field['large']?'editorial-field--large':''?>"><label><?=mockup_experiment_h($field['es'])?></label><div class="editorial-copy" contenteditable="true" role="textbox" aria-multiline="true" data-placeholder="<?=mockup_experiment_h($field['es_placeholder'])?>"></div></section><?php endforeach; ?></article>
                    <article class="editorial-page editorial-page--english"><span class="editorial-language">English · current version</span><?php foreach($editorialFields as $field): ?><section class="editorial-field <?=$field['large']?'editorial-field--large':''?>"><label><?=mockup_experiment_h($field['en'])?></label><div class="editorial-copy" contenteditable="true" role="textbox" aria-multiline="true" data-placeholder="No English content is currently available."><?=mockup_experiment_h($field['value'])?></div></section><?php endforeach; ?></article>
                </div>
            </details>

            <details class="editorial-drawer">
                <summary><span class="editorial-summary"><strong>Publicación y redes</strong><span>Adaptaciones específicas de este mockup para cada canal.</span></span><span class="editorial-state">5 canales</span></summary>
                <div class="social-channels">
                    <?php foreach($socialChannels as $socialChannel): ?><details class="social-channel"><summary><span class="social-channel-title"><?=mockup_experiment_h($socialChannel['label'])?></span><span class="social-channel-note">Español + English</span></summary><div class="social-grid"><div class="social-cell social-cell--source social-language"><span class="editorial-language">Español · fuente</span></div><div class="social-cell social-cell--english social-language"><span class="editorial-language">English · current version</span></div><?php foreach($socialChannel['fields'] as $fieldIndex=>$field): ?><?php $last=$fieldIndex===array_key_last($socialChannel['fields']); ?><section class="social-cell social-cell--source <?=$last?'social-cell--last':''?>"><label><?=mockup_experiment_h($field['es'])?></label><div class="social-copy" contenteditable="true" role="textbox" data-placeholder="Escribí la versión en español…"></div></section><section class="social-cell social-cell--english <?=$last?'social-cell--last':''?>"><label><?=mockup_experiment_h($field['en'])?></label><div class="social-copy" contenteditable="true" role="textbox" data-placeholder="No English content is currently available."><?=mockup_experiment_h($field['value'])?></div></section><?php endforeach; ?></div></details><?php endforeach; ?>
                </div>
            </details>
        </div>
    </main>
</div>
</body>
</html>

<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$studioReferencesLabEnabled = filter_var(app_env('STUDIO_REFERENCES_LAB_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN);
if (!$studioReferencesLabEnabled) {
    http_response_code(404);
    exit('Visual DNA LAB is disabled.');
}

$user = Auth::requireUser();
if (!Auth::isAdmin($user)) {
    http_response_code(403);
    exit('You do not have access to the Visual DNA ADMIN LAB.');
}

$_SESSION['reference_set_csrf'] ??= bin2hex(random_bytes(32));
$referenceSetService = new ReferenceSetService(Database::connection());
$referenceSetService->ensureStarterSets((int)$user['id']);
$referenceAssetService = new ReferenceAssetService(Database::connection());

if (!function_exists('srl_h')) {
    function srl_h(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function srl_demo_image(string $motif, array $colors, int $variant = 0): string
{
    [$background, $primary, $secondary, $accent] = array_pad($colors, 4, '#f4efe8');
    $shapes = '';

    if ($motif === 'architecture') {
        $shapes = '<rect x="28" y="28" width="264" height="164" rx="4" fill="' . $secondary . '"/>'
            . '<path d="M78 192V103a42 42 0 0 1 84 0v89M184 192V82a32 32 0 0 1 64 0v110" fill="' . $primary . '"/>'
            . '<rect x="52" y="192" width="216" height="18" fill="' . $accent . '"/>';
    } elseif ($motif === 'light') {
        $shapes = '<rect x="38" y="26" width="104" height="176" fill="' . $primary . '"/>'
            . '<rect x="50" y="40" width="80" height="122" fill="' . $accent . '"/>'
            . '<path d="M130 162L278 202H130Z" fill="' . $secondary . '"/>'
            . '<path d="M90 40V162M50 101H130" stroke="' . $background . '" stroke-width="6"/>';
    } elseif ($motif === 'materials') {
        $shapes = '<rect x="34" y="30" width="78" height="174" fill="' . $primary . '"/>'
            . '<rect x="121" y="30" width="78" height="174" fill="' . $secondary . '"/>'
            . '<rect x="208" y="30" width="78" height="174" fill="' . $accent . '"/>'
            . '<path d="M45 62h56M45 92h56M45 122h56M132 54l56 35M132 92l56 35M219 58h56M219 82h56M219 106h56M219 130h56" stroke="' . $background . '" stroke-width="5" opacity=".72"/>';
    } elseif ($motif === 'atmosphere') {
        $shapes = '<circle cx="92" cy="112" r="68" fill="' . $primary . '"/>'
            . '<circle cx="176" cy="85" r="54" fill="' . $secondary . '"/>'
            . '<circle cx="230" cy="150" r="74" fill="' . $accent . '"/>'
            . '<rect x="24" y="176" width="272" height="34" fill="' . $primary . '" opacity=".64"/>';
    } elseif ($motif === 'furniture') {
        $shapes = '<rect x="62" y="76" width="196" height="76" rx="18" fill="' . $primary . '"/>'
            . '<rect x="48" y="132" width="224" height="42" rx="8" fill="' . $secondary . '"/>'
            . '<path d="M76 174v34M244 174v34M93 76V48M227 76V48" stroke="' . $accent . '" stroke-width="12"/>'
            . '<circle cx="160" cy="115" r="22" fill="' . $accent . '"/>';
    } elseif ($motif === 'characters') {
        $shapes = '<circle cx="160" cy="60" r="32" fill="' . $accent . '"/>'
            . '<path d="M112 198c2-69 17-106 48-106s46 37 48 106Z" fill="' . $primary . '"/>'
            . '<path d="M76 200c4-50 15-77 37-83M244 200c-4-50-15-77-37-83" stroke="' . $secondary . '" stroke-width="18" stroke-linecap="round"/>';
    } elseif ($motif === 'vegetation') {
        $shapes = '<path d="M160 210V42" stroke="' . $accent . '" stroke-width="8"/>'
            . '<ellipse cx="112" cy="80" rx="52" ry="25" transform="rotate(24 112 80)" fill="' . $primary . '"/>'
            . '<ellipse cx="208" cy="104" rx="52" ry="25" transform="rotate(-28 208 104)" fill="' . $secondary . '"/>'
            . '<ellipse cx="116" cy="150" rx="58" ry="27" transform="rotate(20 116 150)" fill="' . $secondary . '"/>'
            . '<ellipse cx="208" cy="174" rx="52" ry="24" transform="rotate(-22 208 174)" fill="' . $primary . '"/>';
    } elseif ($motif === 'textures') {
        for ($row = 0; $row < 5; $row++) {
            for ($column = 0; $column < 7; $column++) {
                $fill = (($row + $column + $variant) % 3 === 0) ? $accent : ((($row + $column) % 2 === 0) ? $primary : $secondary);
                $shapes .= '<rect x="' . (22 + $column * 41) . '" y="' . (22 + $row * 40) . '" width="34" height="32" rx="3" fill="' . $fill . '"/>';
            }
        }
    } elseif ($motif === 'composition') {
        $shapes = '<rect x="34" y="28" width="252" height="176" fill="' . $secondary . '"/>'
            . '<rect x="56" y="48" width="86" height="136" fill="' . $primary . '"/>'
            . '<rect x="154" y="48" width="110" height="62" fill="' . $accent . '"/>'
            . '<circle cx="210" cy="154" r="38" fill="' . $background . '"/>';
    } else {
        $shapes = '<rect x="28" y="26" width="74" height="182" fill="' . $primary . '"/>'
            . '<rect x="111" y="26" width="74" height="182" fill="' . $secondary . '"/>'
            . '<rect x="194" y="26" width="98" height="182" fill="' . $accent . '"/>';
    }

    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 232" role="img">'
        . '<rect width="320" height="232" fill="' . $background . '"/>'
        . $shapes
        . '</svg>';

    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

$references = [
    ['id' => 'arched-courtyard', 'title' => 'Arched Courtyard', 'category' => 'Architecture', 'motif' => 'architecture', 'colors' => ['#eee5d8', '#9f7560', '#d1b99d', '#6f574c']],
    ['id' => 'quiet-concrete', 'title' => 'Quiet Concrete', 'category' => 'Architecture', 'motif' => 'architecture', 'colors' => ['#e8e7e2', '#737872', '#b6b3aa', '#555953']],
    ['id' => 'late-window', 'title' => 'Late Window', 'category' => 'Light', 'motif' => 'light', 'colors' => ['#363937', '#787d72', '#cab485', '#e8cf91']],
    ['id' => 'soft-overcast', 'title' => 'Soft Overcast', 'category' => 'Atmosphere', 'motif' => 'atmosphere', 'colors' => ['#e9ece8', '#aab8af', '#cbd3cb', '#8b9a92']],
    ['id' => 'pigment-plaster', 'title' => 'Pigment Plaster', 'category' => 'Materials', 'motif' => 'materials', 'colors' => ['#f0e9df', '#b77f78', '#d4b59d', '#8a756a']],
    ['id' => 'aged-copper', 'title' => 'Aged Copper', 'category' => 'Materials', 'motif' => 'materials', 'colors' => ['#e8e3d8', '#9c745f', '#728b7e', '#c2a276']],
    ['id' => 'low-lounge', 'title' => 'Low Lounge', 'category' => 'Furniture', 'motif' => 'furniture', 'colors' => ['#ebe5dc', '#92766b', '#c1a491', '#655d55']],
    ['id' => 'still-presence', 'title' => 'Still Presence', 'category' => 'Characters', 'motif' => 'characters', 'colors' => ['#e5e0da', '#877178', '#bca29b', '#4f4a48']],
    ['id' => 'olive-shadow', 'title' => 'Olive Shadow', 'category' => 'Vegetation', 'motif' => 'vegetation', 'colors' => ['#e7e7dc', '#87977a', '#adb697', '#5f7058']],
    ['id' => 'worn-tile', 'title' => 'Worn Tile', 'category' => 'Textures', 'motif' => 'textures', 'colors' => ['#eee6d8', '#b98b74', '#d3b59e', '#718895'], 'variant' => 1],
    ['id' => 'off-axis-balance', 'title' => 'Off-axis Balance', 'category' => 'Composition', 'motif' => 'composition', 'colors' => ['#eee9df', '#9a7d75', '#c6b8a6', '#7b8d91']],
    ['id' => 'earth-pigments', 'title' => 'Earth Pigments', 'category' => 'Color', 'motif' => 'color', 'colors' => ['#eee7dc', '#a56f65', '#b99a73', '#748678']],
];

foreach ($references as &$reference) {
    $reference['image'] = srl_demo_image(
        (string)$reference['motif'],
        (array)$reference['colors'],
        (int)($reference['variant'] ?? 0)
    );
}
unset($reference);

$categories = ['Architecture', 'Light', 'Materials', 'Atmosphere', 'Furniture', 'Characters', 'Vegetation', 'Textures', 'Composition', 'Color'];
$decisionCategories = [
    ['label' => 'ARCHITECTURE', 'value' => 'Architecture', 'tone' => 'clay'],
    ['label' => 'LIGHT', 'value' => 'Light', 'tone' => 'ochre'],
    ['label' => 'MATERIALS', 'value' => 'Materials', 'tone' => 'rose'],
    ['label' => 'ATMOSPHERE', 'value' => 'Atmosphere', 'tone' => 'sage'],
    ['label' => 'CHARACTERS', 'value' => 'Characters', 'tone' => 'lilac'],
    ['label' => 'TEXTURES', 'value' => 'Textures', 'tone' => 'blue'],
];

$zones = [
    ['id' => 'architecture-space', 'title' => 'Architecture & Space', 'accepts' => 'Architecture, Furniture, Composition', 'category' => 'Architecture'],
    ['id' => 'light-atmosphere', 'title' => 'Light & Atmosphere', 'accepts' => 'Light, Atmosphere, Color', 'category' => 'Light'],
    ['id' => 'materials-details', 'title' => 'Materials & Details', 'accepts' => 'Materials, Textures, Vegetation', 'category' => 'Materials'],
    ['id' => 'presence', 'title' => 'Presence', 'accepts' => 'Characters, Composition', 'category' => 'Characters'],
];

$savedSets = [
    ['title' => 'MEDITERRANEAN LIGHT', 'tone' => 'ochre', 'indexes' => [0, 2, 8]],
    ['title' => 'CATALAN MODERNISM', 'tone' => 'rose', 'indexes' => [0, 4, 10]],
    ['title' => 'INDUSTRIAL SILENCE', 'tone' => 'blue', 'indexes' => [1, 3, 5]],
    ['title' => 'AUTUMN INTERIOR', 'tone' => 'sage', 'indexes' => [2, 6, 11]],
];

$references = array_values($referenceAssetService->catalogMapForUser((int)$user['id']));
$categories = array_merge(StudioReferenceCatalog::categories(), ['Other']);
$savedSets = $referenceSetService->listForUser((int)$user['id'], true);
$generationSets = array_values(array_filter($savedSets, static function (array $set): bool {
    foreach ((array)($set['items'] ?? []) as $item) {
        if ((int)($item['reference_asset_id'] ?? 0) > 0) {
            return true;
        }
    }
    return false;
}));

$artworkStmt = Database::connection()->prepare("SELECT a.id, a.root_file, g.title AS group_title, a.final_title
    FROM artwork_groups g
    INNER JOIN artworks a ON a.id = g.canonical_artwork_id AND a.user_id = g.user_id
    WHERE g.user_id = :user_id AND g.status = 'active' AND a.root_file IS NOT NULL AND a.root_file <> ''
    ORDER BY g.updated_at DESC, g.id DESC
    LIMIT 80");
$artworkStmt->execute(['user_id' => (int)$user['id']]);
$labArtworks = $artworkStmt->fetchAll();
foreach ($labArtworks as &$labArtwork) {
    $labArtwork['title'] = trim((string)($labArtwork['group_title'] ?? ''))
        ?: (trim((string)($labArtwork['final_title'] ?? '')) ?: 'Artwork #' . (int)$labArtwork['id']);
}
unset($labArtwork);

function srl_render_icon(string $name): void
{
    $paths = [
        'favorite' => '<path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10Z"/>',
        'duplicate' => '<rect x="8" y="8" width="10" height="10" rx="2"/><path d="M6 15H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v1"/>',
        'delete' => '<path d="M5 7h14M9 7V4h6v3M8 10v7M12 10v7M16 10v7M7 7l1 14h8l1-14"/>',
    ];
    echo '<svg class="media-action-icon" viewBox="0 0 24 24" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}

function srl_render_reference_card(array $reference): void
{
    $title = (string)$reference['title'];
    $assetId = (int)($reference['reference_asset_id'] ?? 0);
    ?>
    <article class="srl-reference-card" draggable="true" tabindex="0" role="button"
        aria-label="Select <?= srl_h($title) ?> for assignment"
        data-reference-card
        data-reference-id="<?= srl_h($reference['id']) ?>"
        data-source-reference-id="<?= srl_h($reference['id']) ?>"
        data-reference-asset-id="<?= $assetId ?>"
        data-persisted="<?= $assetId > 0 ? '1' : '0' ?>"
        data-title="<?= srl_h($title) ?>"
        data-category="<?= srl_h($reference['category']) ?>">
        <div class="srl-card-image">
            <img src="<?= srl_h($reference['image']) ?>" alt="Visual reference: <?= srl_h($title) ?>" draggable="false">
            <button class="media-icon-button media-thumb-action media-thumb-action--left srl-image-action" type="button" data-card-action="favorite" aria-label="Favorite <?= srl_h($title) ?>" aria-pressed="false" title="Favorite">
                <?php srl_render_icon('favorite'); ?>
            </button>
        </div>
        <div class="srl-card-copy">
            <span><strong><?= srl_h($title) ?></strong><small><?= srl_h($reference['category']) ?></small></span>
            <em>YOUR REFERENCE</em>
        </div>
    </article>
    <?php
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Visual DNA - Artwork Mockups</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="media-controls.css?v=1">
    <link rel="stylesheet" href="studio_references_lab.css?v=4">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="main-area">
        <header class="app-header"><a class="user-chip" href="account.php"><?= srl_h($user['email']) ?></a></header>

        <div class="srl-page" data-studio-references-lab
            data-save-endpoint="reference_set_save.php"
            data-upload-endpoint="visual_dna_reference_upload.php"
            data-import-endpoint="visual_dna_reference_import.php"
            data-generate-endpoint="visual_dna_generate.php"
            data-status-endpoint="visual_dna_generation_status.php"
            data-csrf="<?= srl_h($_SESSION['reference_set_csrf']) ?>">
            <header class="srl-page-header">
                <div>
                    <span class="srl-eyebrow">ADMIN LAB</span>
                    <h1>Visual DNA</h1>
                    <p>Create reusable visual intentions for scenes, mockups and video workflows.</p>
                </div>
                <span class="srl-lab-status">REFERENCE SET EDITOR</span>
            </header>

            <section class="srl-panel srl-library" aria-labelledby="srl-library-title" data-reference-external-drop>
                <header class="srl-panel-header">
                    <div>
                        <span class="srl-kicker">Reference Library</span>
                        <h2 id="srl-library-title">Your visual references</h2>
                        <p>Drop or paste real spaces, materials, light, atmosphere, furniture, or details, then drag them into the board.</p>
                    </div>
                    <div class="srl-library-controls">
                        <label for="srl-category-filter">Type</label>
                        <select id="srl-category-filter" data-category-filter>
                            <option value="all">All references</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= srl_h($category) ?>"><?= srl_h($category) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="srl-counter" data-library-count><?= count($references) ?> references</span>
                        <button class="media-icon-button srl-add-reference" type="button" data-choose-reference aria-label="Choose a reference image" title="Choose image">
                            <svg class="media-action-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                        </button>
                        <input class="srl-visually-hidden" id="srl-reference-image" name="reference_image" type="file" accept="image/jpeg,image/png,image/webp" data-reference-upload-file tabindex="-1">
                    </div>
                </header>

                <div class="srl-external-drop-cue" data-external-drop-cue hidden aria-hidden="true">
                    <span data-external-drop-label>DROP IMAGE TO ADD REFERENCE</span>
                </div>

                <div class="srl-carousel-shell">
                    <button class="srl-carousel-arrow srl-carousel-arrow--previous" type="button" data-carousel-direction="-1" aria-label="Previous references">‹</button>
                    <div class="srl-library-rail" data-reference-library aria-label="Your visual reference carousel">
                        <p class="srl-library-empty" data-library-empty <?= $references ? 'hidden' : '' ?>>Upload your first real reference. These images are the material used by the Visual DNA test generator.</p>
                        <?php foreach ($references as $reference): ?>
                            <?php srl_render_reference_card($reference); ?>
                        <?php endforeach; ?>
                    </div>
                    <button class="srl-carousel-arrow srl-carousel-arrow--next" type="button" data-carousel-direction="1" aria-label="Next references">›</button>
                </div>
            </section>

            <section class="srl-decisions" aria-labelledby="srl-decisions-title">
                <header class="srl-section-heading">
                    <span class="srl-kicker">Reference Direction</span>
                    <h2 id="srl-decisions-title">Choose a working context</h2>
                </header>
                <div class="srl-decision-rail">
                    <?php foreach ($decisionCategories as $decision): ?>
                        <button class="srl-decision-block srl-tone--<?= srl_h($decision['tone']) ?>" type="button" data-category-decision="<?= srl_h($decision['value']) ?>" aria-pressed="false">
                            <span><?= srl_h($decision['label']) ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="srl-panel srl-board" aria-labelledby="srl-board-title">
                <header class="srl-panel-header">
                    <div>
                        <span class="srl-kicker">Visual Assignment</span>
                        <h2 id="srl-board-title">Current Reference Board</h2>
                        <p>Build the reference direction by placing visual material into the worktable.</p>
                    </div>
                    <span class="srl-counter" data-board-total>0 assigned</span>
                </header>

                <p class="srl-keyboard-hint" id="srl-keyboard-hint">Keyboard: select a library reference, focus a zone, then press Enter to assign it.</p>

                <div class="srl-board-zones">
                    <?php foreach ($zones as $zone): ?>
                        <section class="srl-drop-zone" tabindex="0" role="region" aria-describedby="srl-keyboard-hint" aria-labelledby="zone-<?= srl_h($zone['id']) ?>" data-drop-zone="<?= srl_h($zone['id']) ?>" data-import-category="<?= srl_h($zone['category']) ?>">
                            <header>
                                <div><h3 id="zone-<?= srl_h($zone['id']) ?>"><?= srl_h($zone['title']) ?></h3><p><?= srl_h($zone['accepts']) ?></p></div>
                                <span class="srl-counter" data-zone-count>0</span>
                            </header>
                            <div class="srl-zone-content" data-zone-content>
                                <p class="srl-empty-state" data-empty-state>Drop images or library references here.</p>
                            </div>
                        </section>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="srl-panel srl-saved" aria-labelledby="srl-saved-title">
                <header class="srl-saved-header">
                    <div>
                        <span class="srl-kicker">Organized Results</span>
                        <h2 id="srl-saved-title">Saved Reference Sets</h2>
                        <p>Reusable visual intentions for future scene, mockup and video directions.</p>
                    </div>
                    <div class="srl-set-editor">
                        <label for="srl-set-name">Name
                            <input id="srl-set-name" type="text" maxlength="160" placeholder="Mediterranean Silence" data-reference-set-name>
                        </label>
                        <label for="srl-set-description">Description
                            <textarea id="srl-set-description" rows="2" maxlength="2000" placeholder="Describe the visual intention." data-reference-set-description></textarea>
                        </label>
                        <label for="srl-set-color">Color
                            <select id="srl-set-color" data-reference-set-color>
                                <option value="rose">Dusty rose</option>
                                <option value="clay">Clay</option>
                                <option value="ochre">Ochre</option>
                                <option value="sage">Sage</option>
                                <option value="lilac">Lilac</option>
                                <option value="blue">Muted blue</option>
                            </select>
                        </label>
                    </div>
                    <button class="srl-primary-decision srl-tone--rose" type="button" data-save-reference-set>
                        <span>SAVE<br>REFERENCE SET</span>
                    </button>
                </header>

                <div class="srl-saved-rail" data-saved-sets>
                    <?php foreach ($savedSets as $set): ?>
                        <?php $realReferenceCount = count(array_filter((array)$set['items'], static fn(array $item): bool => (int)($item['reference_asset_id'] ?? 0) > 0)); ?>
                        <article class="srl-saved-set" data-saved-set data-reference-set-id="<?= (int)$set['id'] ?>" data-has-real-references="<?= $realReferenceCount > 0 ? '1' : '0' ?>">
                            <button class="srl-set-decision srl-tone--<?= srl_h($set['identifier_color']) ?>" type="button" data-open-saved-set aria-label="Open <?= srl_h($set['name']) ?> Reference Set">
                                <span><?= srl_h(strtoupper((string)$set['name'])) ?></span>
                            </button>
                            <div class="srl-set-preview" aria-hidden="true">
                                <?php foreach (array_slice((array)$set['items'], 0, 3) as $item): ?>
                                    <img src="<?= srl_h($item['thumbnail']) ?>" alt="" draggable="false">
                                <?php endforeach; ?>
                            </div>
                            <span class="srl-counter"><?= $realReferenceCount > 0 ? count((array)$set['items']) . ' references' : 'EXAMPLE ONLY' ?></span>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="srl-panel srl-generation-lab" aria-labelledby="srl-generation-title">
                <header class="srl-panel-header">
                    <div>
                        <span class="srl-kicker">Isolated Generation Connection</span>
                        <h2 id="srl-generation-title">Test Visual DNA on a mockup</h2>
                        <p>This LAB sends one official artwork and the ordered real references in a saved Visual DNA directly to its own Gemini route. It does not use or modify the normal mockup prompt flow.</p>
                    </div>
                    <span class="srl-lab-status">1 CREDIT · GEMINI LAB</span>
                </header>
                <div class="srl-generation-workspace">
                    <div class="srl-generation-fields">
                        <label for="srl-generation-artwork">Artwork
                            <select id="srl-generation-artwork" data-generation-artwork>
                                <option value="">Choose an official artwork</option>
                                <?php foreach ($labArtworks as $artwork): ?>
                                    <option value="<?= (int)$artwork['id'] ?>"><?= srl_h($artwork['title']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label for="srl-generation-set">Visual DNA
                            <select id="srl-generation-set" data-generation-set>
                                <option value="">Choose a set with real references</option>
                                <?php foreach ($generationSets as $set): ?>
                                    <option value="<?= (int)$set['id'] ?>"><?= srl_h($set['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <p class="srl-generation-help">
                            <?php if (!$references): ?>Upload real reference images and save a Visual DNA before generating.<?php elseif (!$generationSets): ?>Save a new Visual DNA using your uploaded references before generating.<?php elseif (!$labArtworks): ?>Create an artwork with an official root image before testing.<?php else: ?>The result will be stored with your mockups and marked with the selected Visual DNA.<?php endif; ?>
                        </p>
                    </div>
                    <button class="srl-primary-decision srl-generation-button srl-tone--sage" type="button" data-generate-visual-dna <?= (!$generationSets || !$labArtworks) ? 'disabled' : '' ?>>
                        <span>GENERATE<br>TEST MOCKUP</span>
                    </button>
                    <div class="srl-generation-result" data-generation-result>
                        <div class="srl-generation-placeholder" data-generation-placeholder>
                            <span>LAB RESULT</span>
                            <p>The generated mockup will appear here.</p>
                        </div>
                        <img src="" alt="Visual DNA LAB generated mockup" hidden data-generation-image>
                        <p class="srl-generation-state" data-generation-state aria-live="polite"></p>
                    </div>
                </div>
            </section>

            <div class="srl-toast" data-lab-toast role="status" aria-live="polite"></div>
        </div>
    </main>
</div>
<script src="studio_references_lab.js?v=4"></script>
</body>
</html>

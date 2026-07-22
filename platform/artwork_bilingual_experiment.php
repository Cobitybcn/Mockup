<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
$pdo = Database::connection();
$isAdmin = Auth::isAdmin($user);
$requestedId = max(0, (int)($_GET['id'] ?? 0));

header('X-Robots-Tag: noindex, nofollow', true);

function bilingual_experiment_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function bilingual_experiment_media_url(string $file): string
{
    $file = basename($file);

    return $file === '' ? '' : 'media.php?file=' . rawurlencode($file);
}

function bilingual_experiment_artwork(PDO $pdo, array $user, bool $isAdmin, int $requestedId): ?array
{
    $where = [];
    $params = [];

    if ($requestedId > 0) {
        $where[] = 'id = :id';
        $params['id'] = $requestedId;
    }
    if (!$isAdmin) {
        $where[] = 'user_id = :user_id';
        $params['user_id'] = (int)$user['id'];
    }

    $sql = 'SELECT * FROM artworks';
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY updated_at DESC, id DESC LIMIT 1';

    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    $artwork = $statement->fetch(PDO::FETCH_ASSOC);

    return is_array($artwork) ? $artwork : null;
}

function bilingual_experiment_sheet(PDO $pdo, int $artworkId, int $userId): array
{
    try {
        $statement = $pdo->prepare('
            SELECT *
            FROM artwork_sheets
            WHERE canonical_artwork_id = :artwork_id
              AND user_id = :user_id
            ORDER BY updated_at DESC, id DESC
            LIMIT 1
        ');
        $statement->execute([
            'artwork_id' => $artworkId,
            'user_id' => $userId,
        ]);
        $sheet = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($sheet) ? $sheet : [];
    } catch (Throwable) {
        return [];
    }
}

function bilingual_experiment_mockups(PDO $pdo, int $artworkId, int $userId, string $artworkFile): array
{
    $files = [];

    try {
        $statement = $pdo->prepare('
            SELECT mockup_file
            FROM mockup_sheets
            WHERE artwork_id = :artwork_id
              AND user_id = :user_id
            ORDER BY updated_at DESC, id DESC
        ');
        $statement->execute([
            'artwork_id' => $artworkId,
            'user_id' => $userId,
        ]);
        $files = array_merge($files, $statement->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable) {
        // The experiment stays usable when the optional sheet table is unavailable.
    }

    try {
        $statement = $pdo->prepare('
            SELECT mockup_file
            FROM mockups
            WHERE user_id = :user_id
              AND (source_artwork_id = :artwork_id OR artwork_file = :artwork_file)
            ORDER BY created_at DESC, id DESC
        ');
        $statement->execute([
            'user_id' => $userId,
            'artwork_id' => $artworkId,
            'artwork_file' => $artworkFile,
        ]);
        $files = array_merge($files, $statement->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable) {
        // The artwork image can still be shown without related mockups.
    }

    $files = array_map(static fn(mixed $file): string => basename((string)$file), $files);
    $files = array_filter($files, static fn(string $file): bool => $file !== '');

    return array_values(array_unique($files));
}

$artwork = bilingual_experiment_artwork($pdo, $user, $isAdmin, $requestedId);
if ($artwork === null) {
    http_response_code(404);
    exit('No artwork is available for this experiment.');
}

$artworkId = (int)$artwork['id'];
$artworkOwnerId = (int)($artwork['user_id'] ?? $user['id']);
$sheet = bilingual_experiment_sheet($pdo, $artworkId, $artworkOwnerId);
$title = trim((string)($sheet['title'] ?? '')) ?: trim((string)($artwork['final_title'] ?? '')) ?: 'Untitled';
$englishDescription = trim((string)($sheet['description'] ?? ''));
$englishShortDescription = trim((string)($sheet['short_description'] ?? ''));
$englishKeywords = trim((string)($sheet['keywords'] ?? ''));
$englishTags = trim((string)($sheet['tags'] ?? ''));
$englishAltText = trim((string)($sheet['alt_text'] ?? ''));
$englishCaption = trim((string)($sheet['caption'] ?? ''));
$imageFile = basename((string)($artwork['root_file'] ?? '')) ?: basename((string)($artwork['main_file'] ?? ''));
$mockupFiles = bilingual_experiment_mockups($pdo, $artworkId, $artworkOwnerId, $imageFile);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Edición bilingüe experimental · Artwork Mockups</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .editorial-experiment {
            --editorial-source: #c89aa1;
            --editorial-english: #9fb19a;
            display: grid;
            gap: 18px;
        }

        .editorial-experiment-topline {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            padding-bottom: 14px;
            border-bottom: 1px solid var(--line);
        }

        .editorial-experiment-state {
            margin: 0;
            color: var(--muted);
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .09em;
            text-transform: uppercase;
        }

        .editorial-back {
            color: var(--muted);
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .07em;
            text-decoration: none;
            text-transform: uppercase;
        }

        .editorial-back:hover { color: var(--accent); }

        .editorial-workspace {
            padding: 18px 20px;
            border: 1px solid var(--line);
            background: var(--surface);
        }

        .editorial-title-memo {
            margin-top: 14px;
            padding-top: 13px;
            border-top: 1px solid var(--line);
        }

        .editorial-shared-title {
            padding: 0;
        }

        .editorial-shared-title-label {
            display: block;
            color: var(--muted);
            font-size: 9px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .editorial-shared-title-text {
            margin: 8px 0 0;
            color: var(--ink);
            font: 500 clamp(36px, 4.5vw, 58px)/1.05 var(--font-serif);
            overflow-wrap: anywhere;
        }

        .editorial-shared-title-text:focus { outline: 0; }

        .editorial-language-label {
            color: var(--muted);
            font-size: 9px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .editorial-title-memo [contenteditable] {
            display: block;
            width: 100%;
            min-width: 0;
            box-sizing: border-box;
            padding: 6px 0 2px;
            color: var(--accent);
            font: italic 500 21px/1.5 var(--font-serif);
        }

        .editorial-title-memo [contenteditable]:focus { outline: 0; }

        .editorial-drawer {
            border: 1px solid var(--line);
            background: var(--surface);
        }

        .editorial-drawer > summary {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 18px 20px;
            cursor: pointer;
            list-style: none;
        }

        .editorial-drawer > summary::-webkit-details-marker { display: none; }

        .editorial-drawer-title {
            color: var(--ink);
            font: 500 22px/1.15 var(--font-serif);
        }

        .editorial-drawer-note {
            color: var(--muted);
            font-size: 9px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .editorial-drawer-note::after {
            content: '+';
            display: inline-block;
            margin-left: 16px;
            color: var(--accent);
            font: 400 18px/1 var(--font-serif);
        }

        .editorial-drawer[open] .editorial-drawer-note::after { content: '−'; }

        .editorial-spread {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
            padding: 0 14px 14px;
        }

        .editorial-page {
            display: flex;
            flex-direction: column;
            min-width: 0;
            min-height: 0;
            padding: 20px 18px;
            border: 1px solid var(--line);
            border-top-width: 4px;
            background: var(--surface-soft);
        }

        .editorial-page--source { border-top-color: var(--editorial-source); }
        .editorial-page--english { border-top-color: var(--editorial-english); }

        .editorial-language-label {
            display: block;
            margin-top: 0;
        }

        .editorial-field {
            display: block;
            width: 100%;
            box-sizing: border-box;
            padding: 0;
            color: var(--ink);
            font: 400 15px/1.75 var(--font-sans);
            white-space: pre-wrap;
        }

        .editorial-field:empty::before {
            content: attr(data-placeholder);
            color: var(--muted);
            font-style: italic;
        }

        .editorial-field:focus { outline: 0; }

        .editorial-metadata-section {
            margin-top: 20px;
            padding-top: 14px;
            border-top: 1px solid var(--line);
        }

        .editorial-metadata-section--description {
            min-height: 265px;
            margin-top: 18px;
            padding-top: 0;
            border-top: 0;
        }

        .editorial-metadata-section--description .editorial-field { min-height: 225px; }
        .editorial-metadata-section--short { min-height: 135px; }
        .editorial-metadata-section--short .editorial-field { min-height: 90px; }
        .editorial-metadata-section--compact { min-height: 128px; }
        .editorial-metadata-section--compact .editorial-field { min-height: 82px; }

        .editorial-metadata-label {
            display: block;
            color: var(--muted);
            font-size: 9px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .editorial-metadata-section .editorial-field { margin-top: 12px; }

        .editorial-private-memo {
            margin: 0 14px 14px;
            padding: 16px 6px 2px;
            border-top: 1px solid var(--line);
        }

        .editorial-private-memo summary {
            cursor: pointer;
            color: var(--muted);
            font-size: 9px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .editorial-private-memo .editorial-field {
            min-height: 90px;
            margin-top: 14px;
        }

        .editorial-visuals {
            padding: 0;
        }

        .editorial-visuals-heading {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 16px;
            padding: 0 2px 12px;
            border-bottom: 1px solid var(--line);
        }

        .editorial-visuals-title {
            margin: 0;
            color: var(--ink);
            font: 500 22px/1.2 var(--font-serif);
        }

        .editorial-visuals-count {
            color: var(--muted);
            font-size: 9px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .editorial-visual-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
            gap: 12px;
            padding-top: 14px;
        }

        .editorial-visual-card {
            position: relative;
            min-width: 0;
            margin: 0;
            background: var(--surface-soft);
            overflow: hidden;
        }

        .editorial-visual-card--root { grid-column: span 2; grid-row: span 2; }

        .editorial-visual-card img {
            display: block;
            width: 100%;
            aspect-ratio: 4 / 5;
            object-fit: cover;
        }

        .editorial-visual-card--root img {
            aspect-ratio: 4 / 3;
            object-fit: contain;
        }

        .editorial-visual-card figcaption {
            position: absolute;
            right: 8px;
            bottom: 8px;
            left: 8px;
            padding: 6px 8px;
            background: rgba(255, 255, 255, .88);
            color: var(--muted);
            font-size: 8px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        @media (max-width: 900px) {
            .editorial-spread { grid-template-columns: 1fr; }
            .editorial-page { min-height: 380px; }
        }

        @media (max-width: 680px) {
            .editorial-experiment-topline { align-items: flex-start; flex-direction: column; }
            .editorial-shared-title-text { font-size: 31px; }
            .editorial-workspace { padding: 12px; }
            .editorial-page { padding: 18px 15px; }
            .editorial-drawer > summary { align-items: flex-start; }
            .editorial-drawer-note { text-align: right; }
            .editorial-visual-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .editorial-visual-card--root { grid-column: 1 / -1; grid-row: auto; }
        }
    </style>
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="main-area">
        <header class="app-header">
            <a class="user-chip" href="account.php"><?= bilingual_experiment_h($user['email'] ?? '') ?></a>
        </header>

        <div class="workspace editorial-experiment">
            <div class="editorial-experiment-topline">
                <p class="editorial-experiment-state">Edición bilingüe · experimento privado · no guarda datos</p>
                <a class="editorial-back" href="artwork.php?id=<?= $artworkId ?>">Volver a la ficha actual</a>
            </div>

            <section class="editorial-workspace" aria-label="Título universal">
                <div class="editorial-shared-title">
                    <span class="editorial-shared-title-label">Título universal</span>
                    <h1 class="editorial-shared-title-text" contenteditable="true" role="textbox" aria-label="Título de la obra"><?= bilingual_experiment_h($title) ?></h1>
                </div>
                <div class="editorial-title-memo">
                    <span contenteditable="true" role="textbox" aria-label="Memo privado del sistema de títulos">STRATA X — LIMEN · STRATA XI — NUHRĀ (ܢܘܗܪܐ) · no traducir</span>
                </div>
            </section>

            <details class="editorial-drawer">
                <summary>
                    <span class="editorial-drawer-title">Espacio editorial</span>
                    <span class="editorial-drawer-note">Español + English</span>
                </summary>
                <div class="editorial-spread">
                    <article class="editorial-page editorial-page--source">
                        <span class="editorial-language-label">Español · fuente</span>
                        <section class="editorial-metadata-section editorial-metadata-section--description">
                            <span class="editorial-metadata-label">Descripción</span>
                            <div class="editorial-field" contenteditable="true" role="textbox" aria-multiline="true" aria-label="Descripción en español" data-placeholder="Escribí una descripción en español…"></div>
                        </section>
                        <section class="editorial-metadata-section editorial-metadata-section--short">
                            <span class="editorial-metadata-label">Resumen breve</span>
                            <div class="editorial-field" contenteditable="true" role="textbox" aria-multiline="true" aria-label="Resumen breve en español" data-placeholder="Dos o tres frases para series, tarjetas y mockups…"></div>
                        </section>
                        <section class="editorial-metadata-section editorial-metadata-section--compact">
                            <span class="editorial-metadata-label">Palabras clave</span>
                            <div class="editorial-field" contenteditable="true" role="textbox" aria-multiline="true" aria-label="Palabras clave en español" data-placeholder="Conceptos, materiales, temas…"></div>
                        </section>
                        <section class="editorial-metadata-section editorial-metadata-section--compact">
                            <span class="editorial-metadata-label">Etiquetas</span>
                            <div class="editorial-field" contenteditable="true" role="textbox" aria-multiline="true" aria-label="Etiquetas en español" data-placeholder="Etiquetas internas…"></div>
                        </section>
                        <section class="editorial-metadata-section editorial-metadata-section--compact">
                            <span class="editorial-metadata-label">Texto alternativo</span>
                            <div class="editorial-field" contenteditable="true" role="textbox" aria-multiline="true" aria-label="Texto alternativo en español" data-placeholder="Descripción visual accesible…"></div>
                        </section>
                        <section class="editorial-metadata-section editorial-metadata-section--compact">
                            <span class="editorial-metadata-label">Caption</span>
                            <div class="editorial-field" contenteditable="true" role="textbox" aria-multiline="true" aria-label="Caption en español" data-placeholder="Texto para publicación…"></div>
                        </section>
                    </article>

                    <article class="editorial-page editorial-page--english">
                        <span class="editorial-language-label">English · current version</span>
                        <section class="editorial-metadata-section editorial-metadata-section--description">
                            <span class="editorial-metadata-label">Description</span>
                            <div class="editorial-field" contenteditable="true" role="textbox" aria-multiline="true" aria-label="Description in English" data-placeholder="No English description is currently available."><?= bilingual_experiment_h($englishDescription) ?></div>
                        </section>
                        <section class="editorial-metadata-section editorial-metadata-section--short">
                            <span class="editorial-metadata-label">Short description</span>
                            <div class="editorial-field" contenteditable="true" role="textbox" aria-multiline="true" aria-label="Short description in English" data-placeholder="No English short description is currently available."><?= bilingual_experiment_h($englishShortDescription) ?></div>
                        </section>
                        <section class="editorial-metadata-section editorial-metadata-section--compact">
                            <span class="editorial-metadata-label">Keywords</span>
                            <div class="editorial-field" contenteditable="true" role="textbox" aria-multiline="true" aria-label="Keywords in English" data-placeholder="No English keywords are currently available."><?= bilingual_experiment_h($englishKeywords) ?></div>
                        </section>
                        <section class="editorial-metadata-section editorial-metadata-section--compact">
                            <span class="editorial-metadata-label">Tags</span>
                            <div class="editorial-field" contenteditable="true" role="textbox" aria-multiline="true" aria-label="Tags in English" data-placeholder="No English tags are currently available."><?= bilingual_experiment_h($englishTags) ?></div>
                        </section>
                        <section class="editorial-metadata-section editorial-metadata-section--compact">
                            <span class="editorial-metadata-label">Alt text</span>
                            <div class="editorial-field" contenteditable="true" role="textbox" aria-multiline="true" aria-label="Alt text in English" data-placeholder="No English alt text is currently available."><?= bilingual_experiment_h($englishAltText) ?></div>
                        </section>
                        <section class="editorial-metadata-section editorial-metadata-section--compact">
                            <span class="editorial-metadata-label">Caption</span>
                            <div class="editorial-field" contenteditable="true" role="textbox" aria-multiline="true" aria-label="Caption in English" data-placeholder="No English caption is currently available."><?= bilingual_experiment_h($englishCaption) ?></div>
                        </section>
                    </article>
                </div>

                <details class="editorial-private-memo">
                    <summary>Memo privado de la obra</summary>
                    <div class="editorial-field" contenteditable="true" role="textbox" aria-multiline="true" aria-label="Memo privado de la obra" data-placeholder="Ideas, decisiones y recordatorios que no se publican…"></div>
                </details>
            </details>

            <section class="editorial-visuals" aria-label="Obra y mockups">
                <div class="editorial-visuals-heading">
                    <h2 class="editorial-visuals-title">Obra y mockups</h2>
                    <span class="editorial-visuals-count"><?= count($mockupFiles) ?> mockups</span>
                </div>
                <div class="editorial-visual-grid">
                    <?php if ($imageFile !== ''): ?>
                        <figure class="editorial-visual-card editorial-visual-card--root">
                            <img src="<?= bilingual_experiment_h(bilingual_experiment_media_url($imageFile)) ?>" alt="<?= bilingual_experiment_h($title) ?>">
                            <figcaption>Obra raíz</figcaption>
                        </figure>
                    <?php endif; ?>
                    <?php foreach ($mockupFiles as $index => $mockupFile): ?>
                        <figure class="editorial-visual-card">
                            <img src="<?= bilingual_experiment_h(bilingual_experiment_media_url($mockupFile)) ?>" alt="Mockup <?= $index + 1 ?> de <?= bilingual_experiment_h($title) ?>" loading="lazy">
                            <figcaption>Mockup <?= $index + 1 ?></figcaption>
                        </figure>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
    </main>
</div>

</body>
</html>

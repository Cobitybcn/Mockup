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

$artwork = bilingual_experiment_artwork($pdo, $user, $isAdmin, $requestedId);
if ($artwork === null) {
    http_response_code(404);
    exit('No artwork is available for this experiment.');
}

$artworkId = (int)$artwork['id'];
$artworkOwnerId = (int)($artwork['user_id'] ?? $user['id']);
$sheet = bilingual_experiment_sheet($pdo, $artworkId, $artworkOwnerId);
$title = trim((string)($sheet['title'] ?? '')) ?: trim((string)($artwork['final_title'] ?? '')) ?: 'Untitled';
$subtitle = trim((string)($sheet['subtitle'] ?? '')) ?: trim((string)($artwork['subtitle'] ?? ''));
$englishDescription = trim((string)($sheet['description'] ?? ''));
$englishShortDescription = trim((string)($sheet['short_description'] ?? ''));
$imageFile = basename((string)($artwork['root_file'] ?? '')) ?: basename((string)($artwork['main_file'] ?? ''));
$series = trim((string)($artwork['series'] ?? '')) ?: 'Sin serie';
$year = trim((string)($artwork['artwork_year'] ?? ''));
$medium = trim((string)($artwork['medium'] ?? ''));
$width = trim((string)($artwork['width'] ?? ''));
$height = trim((string)($artwork['height'] ?? ''));
$unit = trim((string)($artwork['unit'] ?? '')) ?: 'cm';
$dimensions = $width !== '' && $height !== '' ? $width . ' × ' . $height . ' ' . $unit : '';
$englishHasContent = $englishDescription !== '' || $englishShortDescription !== '';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Experimento editorial bilingüe · Artwork Mockups</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .bilingual-lab {
            --lab-rose: #c99ca2;
            --lab-rose-soft: #f5e8e9;
            --lab-sage: #a8b8a2;
            --lab-sage-soft: #edf2eb;
            display: grid;
            gap: 24px;
        }

        .bilingual-lab-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 24px;
            padding-bottom: 24px;
            border-bottom: 1px solid var(--line);
        }

        .bilingual-lab-header h1 {
            margin: 0;
            font: 500 clamp(34px, 4vw, 54px)/1 var(--font-serif);
            color: var(--ink);
        }

        .bilingual-lab-header p {
            max-width: 720px;
            margin: 12px 0 0;
            color: var(--muted);
            font-size: 15px;
            line-height: 1.6;
        }

        .bilingual-lab-back {
            flex: 0 0 auto;
            color: var(--muted);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .06em;
            text-decoration: none;
            text-transform: uppercase;
        }

        .bilingual-lab-back:hover { color: var(--accent); }

        .bilingual-lab-notice {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            padding: 14px 16px;
            border: 1px solid rgba(166, 128, 74, .28);
            background: #faf4e7;
            color: var(--ink);
            font-size: 13px;
            line-height: 1.5;
        }

        .bilingual-lab-notice strong {
            font-size: 10px;
            letter-spacing: .08em;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .bilingual-artwork-context {
            display: grid;
            grid-template-columns: minmax(240px, 390px) minmax(0, 1fr);
            gap: 28px;
            align-items: stretch;
            padding: 20px;
            border: 1px solid var(--line);
            background: var(--surface);
        }

        .bilingual-artwork-image {
            display: grid;
            place-items: center;
            min-height: 360px;
            background: var(--surface-soft);
            overflow: hidden;
        }

        .bilingual-artwork-image img {
            display: block;
            width: 100%;
            height: 100%;
            max-height: 520px;
            object-fit: contain;
        }

        .bilingual-artwork-copy {
            display: flex;
            flex-direction: column;
            justify-content: center;
            min-width: 0;
            padding: 18px 22px;
        }

        .bilingual-artwork-kicker {
            margin: 0 0 12px;
            color: var(--muted);
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .1em;
            text-transform: uppercase;
        }

        .bilingual-artwork-copy h2 {
            margin: 0;
            font: 500 clamp(34px, 4vw, 52px)/1.06 var(--font-serif);
            overflow-wrap: anywhere;
        }

        .bilingual-artwork-subtitle {
            margin: 10px 0 0;
            color: var(--accent);
            font: 400 21px/1.35 var(--font-serif);
        }

        .bilingual-artwork-facts {
            display: flex;
            flex-wrap: wrap;
            gap: 8px 16px;
            margin: 28px 0 0;
            padding-top: 18px;
            border-top: 1px solid var(--line);
            color: var(--muted);
            font-size: 12px;
        }

        .bilingual-language-workspace {
            padding: 24px;
            border: 1px solid var(--line);
            background: var(--surface);
        }

        .bilingual-language-heading {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 24px;
        }

        .bilingual-language-heading h2 {
            margin: 0;
            font: 500 clamp(28px, 3vw, 40px)/1.08 var(--font-serif);
        }

        .bilingual-language-heading p {
            max-width: 620px;
            margin: 7px 0 0;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.5;
        }

        .bilingual-compare-toggle {
            width: auto;
            min-height: 42px;
            margin: 0;
            padding: 9px 14px;
            border: 1px solid var(--line);
            background: transparent;
            color: var(--ink);
            box-shadow: none;
            font-size: 11px;
            letter-spacing: .06em;
            text-transform: uppercase;
        }

        .bilingual-compare-toggle:hover,
        .bilingual-compare-toggle[aria-pressed="true"] {
            border-color: var(--accent);
            background: var(--surface-soft);
            box-shadow: none;
            color: var(--accent);
            transform: none;
        }

        .bilingual-language-rail {
            display: flex;
            gap: 14px;
            margin-top: 24px;
            overflow-x: auto;
            padding-bottom: 4px;
        }

        .bilingual-title-memo {
            display: grid;
            grid-template-columns: minmax(150px, 190px) minmax(0, 1fr);
            gap: 16px;
            align-items: center;
            margin-top: 20px;
            padding: 14px 16px;
            border: 1px solid var(--line);
            background: var(--surface-soft);
        }

        .bilingual-title-memo label {
            color: var(--muted);
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .bilingual-title-memo input {
            width: 100%;
            min-width: 0;
            box-sizing: border-box;
            padding: 12px 13px;
            border: 1px solid var(--line);
            border-radius: 2px;
            background: #fff;
            color: var(--ink);
            font: 500 15px/1.45 var(--font-serif);
        }

        .bilingual-language-block {
            flex: 0 0 156px;
            width: 156px;
            height: 156px;
            min-height: 156px;
            margin: 0;
            padding: 20px;
            border: 1px solid transparent;
            border-radius: 2px;
            box-shadow: none;
            color: #fff;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 9px;
            text-align: center;
        }

        .bilingual-language-block:hover,
        .bilingual-language-block:focus-visible {
            box-shadow: none;
            transform: translateY(-1px);
        }

        .bilingual-language-block--es { background: var(--lab-rose); }
        .bilingual-language-block--en { background: var(--lab-sage); }
        .bilingual-language-block:not(.is-active) { opacity: .64; }
        .bilingual-language-block.is-active { border-color: rgba(72, 60, 55, .5); }

        .bilingual-language-name {
            font-size: 13px;
            font-weight: 800;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .bilingual-language-state {
            font-size: 10px;
            font-weight: 600;
            line-height: 1.35;
        }

        .bilingual-editor-stage {
            margin-top: 24px;
            padding-top: 24px;
            border-top: 1px solid var(--line);
        }

        .bilingual-editor-panel[hidden] { display: none; }

        .bilingual-editor-panel-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 18px;
            margin-bottom: 20px;
        }

        .bilingual-editor-panel-head h3 {
            margin: 0;
            font: 500 28px/1.15 var(--font-serif);
        }

        .bilingual-editor-panel-head p {
            margin: 6px 0 0;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.5;
        }

        .bilingual-status {
            flex: 0 0 auto;
            padding: 7px 10px;
            border: 1px solid var(--line);
            border-radius: 999px;
            color: var(--ink);
            font-size: 9px;
            font-weight: 800;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .bilingual-status--es { background: var(--lab-rose-soft); }
        .bilingual-status--en { background: var(--lab-sage-soft); }

        .bilingual-editor-fields {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }

        .bilingual-field {
            display: grid;
            gap: 8px;
        }

        .bilingual-field--full { grid-column: 1 / -1; }

        .bilingual-field label {
            color: var(--muted);
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .bilingual-field input,
        .bilingual-field textarea {
            width: 100%;
            box-sizing: border-box;
            border: 1px solid var(--line);
            border-radius: 2px;
            background: #fff;
            color: var(--ink);
            font: 400 16px/1.65 var(--font-sans);
            padding: 14px 15px;
        }

        .bilingual-field textarea {
            min-height: 220px;
            resize: vertical;
        }

        .bilingual-field textarea.bilingual-short-copy { min-height: 112px; }

        .bilingual-field input:focus,
        .bilingual-field textarea:focus {
            outline: 2px solid rgba(183, 127, 134, .24);
            outline-offset: 1px;
            border-color: var(--accent);
        }

        .bilingual-source-note {
            margin: 0 0 18px;
            padding: 13px 14px;
            border-left: 3px solid var(--lab-sage);
            background: var(--lab-sage-soft);
            color: var(--muted);
            font-size: 13px;
            line-height: 1.55;
        }

        .bilingual-analysis-details {
            margin-top: 18px;
            border: 1px solid var(--line);
            background: var(--surface-soft);
        }

        .bilingual-analysis-details summary {
            cursor: pointer;
            padding: 14px 16px;
            color: var(--ink);
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .07em;
            text-transform: uppercase;
        }

        .bilingual-analysis-details .bilingual-field { padding: 0 16px 16px; }
        .bilingual-analysis-details textarea { min-height: 130px; }

        .bilingual-flow-action {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            margin-top: 20px;
            padding-top: 18px;
            border-top: 1px solid var(--line);
        }

        .bilingual-flow-action p {
            max-width: 650px;
            margin: 0;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.5;
        }

        .bilingual-flow-action button {
            width: auto;
            min-width: 210px;
            min-height: 50px;
            margin: 0;
            border-color: #d8b7bb;
            background: var(--lab-rose-soft);
            color: #6f4b50;
            box-shadow: none;
        }

        .bilingual-flow-action button:hover {
            border-color: var(--lab-rose);
            background: #efdadd;
            box-shadow: none;
            transform: none;
        }

        .bilingual-compare-panel[hidden] { display: none; }

        .bilingual-compare-panel {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
            margin-top: 24px;
            padding-top: 24px;
            border-top: 1px solid var(--line);
        }

        .bilingual-compare-sheet {
            min-width: 0;
            padding: 22px;
            border: 1px solid var(--line);
            background: var(--surface-soft);
        }

        .bilingual-compare-sheet--es { border-top: 5px solid var(--lab-rose); }
        .bilingual-compare-sheet--en { border-top: 5px solid var(--lab-sage); }

        .bilingual-compare-sheet h3 {
            margin: 0;
            font: 500 25px/1.15 var(--font-serif);
        }

        .bilingual-compare-sheet small {
            display: block;
            margin-top: 5px;
            color: var(--muted);
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
        }

        .bilingual-compare-copy {
            margin: 20px 0 0;
            color: var(--ink);
            font-size: 15px;
            line-height: 1.75;
            white-space: pre-wrap;
        }

        .bilingual-empty-copy { color: var(--muted); font-style: italic; }

        @media (max-width: 900px) {
            .bilingual-artwork-context { grid-template-columns: 1fr; }
            .bilingual-artwork-image { min-height: 280px; }
            .bilingual-compare-panel { grid-template-columns: 1fr; }
        }

        @media (max-width: 680px) {
            .bilingual-lab-header,
            .bilingual-language-heading,
            .bilingual-editor-panel-head,
            .bilingual-flow-action { align-items: stretch; flex-direction: column; }
            .bilingual-lab-notice { align-items: flex-start; flex-direction: column; }
            .bilingual-language-workspace,
            .bilingual-artwork-context { padding: 14px; }
            .bilingual-artwork-copy { padding: 12px 4px 4px; }
            .bilingual-editor-fields { grid-template-columns: 1fr; }
            .bilingual-field--full { grid-column: auto; }
            .bilingual-flow-action button { width: 100%; }
            .bilingual-language-block { flex-basis: 132px; width: 132px; height: 132px; min-height: 132px; }
            .bilingual-title-memo { grid-template-columns: 1fr; gap: 8px; }
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

        <div class="workspace bilingual-lab">
            <header class="bilingual-lab-header">
                <div>
                    <h1>Laboratorio editorial bilingüe</h1>
                    <p>Una prueba aislada para sentir cómo sería trabajar en español y conservar el inglés como adaptación internacional de la misma obra.</p>
                </div>
                <a class="bilingual-lab-back" href="artwork.php?id=<?= $artworkId ?>">Volver a la ficha actual</a>
            </header>

            <div class="bilingual-lab-notice" role="note">
                <span>Podés escribir y cambiar de vista para probar el flujo. Los cambios viven solamente en esta pantalla y desaparecen al recargar.</span>
                <strong>Experimento · no guarda datos</strong>
            </div>

            <section class="bilingual-artwork-context" aria-labelledby="experiment-artwork-title">
                <div class="bilingual-artwork-image">
                    <?php if ($imageFile !== ''): ?>
                        <img src="<?= bilingual_experiment_h(bilingual_experiment_media_url($imageFile)) ?>" alt="<?= bilingual_experiment_h($title) ?>">
                    <?php else: ?>
                        <span>Sin imagen disponible</span>
                    <?php endif; ?>
                </div>
                <div class="bilingual-artwork-copy">
                    <p class="bilingual-artwork-kicker">Obra raíz · datos compartidos</p>
                    <h2 id="experiment-artwork-title"><?= bilingual_experiment_h($title) ?></h2>
                    <?php if ($subtitle !== ''): ?><p class="bilingual-artwork-subtitle"><?= bilingual_experiment_h($subtitle) ?></p><?php endif; ?>
                    <div class="bilingual-artwork-facts">
                        <span><?= bilingual_experiment_h($series) ?></span>
                        <?php if ($year !== ''): ?><span><?= bilingual_experiment_h($year) ?></span><?php endif; ?>
                        <?php if ($medium !== ''): ?><span><?= bilingual_experiment_h($medium) ?></span><?php endif; ?>
                        <?php if ($dimensions !== ''): ?><span><?= bilingual_experiment_h($dimensions) ?></span><?php endif; ?>
                    </div>
                </div>
            </section>

            <section class="bilingual-language-workspace" aria-labelledby="language-workspace-title">
                <div class="bilingual-language-heading">
                    <div>
                        <h2 id="language-workspace-title">Contenido de la obra</h2>
                        <p>Elegí una mesa editorial. La obra y sus datos no cambian; solamente cambia el idioma del contenido.</p>
                    </div>
                    <button class="bilingual-compare-toggle" type="button" aria-pressed="false" data-compare-toggle>Comparar idiomas</button>
                </div>

                <div class="bilingual-language-rail" aria-label="Idioma de edición">
                    <button class="bilingual-language-block bilingual-language-block--es is-active" type="button" data-language="es" aria-pressed="true">
                        <span class="bilingual-language-name">Español</span>
                        <span class="bilingual-language-state">Fuente editorial · borrador</span>
                    </button>
                    <button class="bilingual-language-block bilingual-language-block--en" type="button" data-language="en" aria-pressed="false">
                        <span class="bilingual-language-name">English</span>
                        <span class="bilingual-language-state"><?= $englishHasContent ? 'Contenido actual · protegido' : 'Sin preparar' ?></span>
                    </button>
                </div>

                <div class="bilingual-title-memo">
                    <label for="title-system-memo">Memo privado de títulos</label>
                    <input id="title-system-memo" type="text" value="STRATA X — LIMEN · STRATA XI — NUHRĀ (ܢܘܗܪܐ) · No traducir">
                </div>

                <div class="bilingual-editor-stage">
                    <section class="bilingual-editor-panel" data-language-panel="es">
                        <div class="bilingual-editor-panel-head">
                            <div>
                                <h3>Escribí y pensá la obra en español</h3>
                                <p>Esta sería tu vista habitual. El inglés no ocupa espacio mientras trabajás.</p>
                            </div>
                            <span class="bilingual-status bilingual-status--es">Fuente principal</span>
                        </div>

                        <div class="bilingual-editor-fields">
                            <div class="bilingual-field">
                                <label for="spanish-title">Título original</label>
                                <input id="spanish-title" type="text" value="<?= bilingual_experiment_h($title) ?>" data-spanish-title>
                            </div>
                            <div class="bilingual-field">
                                <label for="spanish-subtitle">Subtítulo en español</label>
                                <input id="spanish-subtitle" type="text" placeholder="Opcional" data-spanish-subtitle>
                            </div>
                            <div class="bilingual-field bilingual-field--full">
                                <label for="spanish-description">Descripción en español</label>
                                <textarea id="spanish-description" placeholder="Escribí aquí la lectura de la obra con tus propias palabras…" data-spanish-description></textarea>
                            </div>
                            <div class="bilingual-field bilingual-field--full">
                                <label for="spanish-summary">Resumen breve</label>
                                <textarea class="bilingual-short-copy" id="spanish-summary" placeholder="Dos o tres frases para tarjetas, series y mockups…"></textarea>
                            </div>
                        </div>

                        <details class="bilingual-analysis-details">
                            <summary>Contexto privado para el análisis</summary>
                            <div class="bilingual-field">
                                <label for="spanish-context">Lo que la IA debería saber antes de analizar</label>
                                <textarea id="spanish-context" placeholder="Intención, proceso, referencias, interpretaciones que querés evitar…"></textarea>
                            </div>
                        </details>

                        <div class="bilingual-flow-action">
                            <p>En una implementación real, esta acción produciría un borrador en inglés y nunca lo publicaría automáticamente.</p>
                            <button type="button" data-preview-english>Preparar adaptación inglesa</button>
                        </div>
                    </section>

                    <section class="bilingual-editor-panel" data-language-panel="en" hidden>
                        <div class="bilingual-editor-panel-head">
                            <div>
                                <h3>Adaptación internacional</h3>
                                <p>El contenido inglés que ya existe se conserva. Esta vista permite revisarlo sin duplicar la obra.</p>
                            </div>
                            <span class="bilingual-status bilingual-status--en"><?= $englishHasContent ? 'Contenido actual' : 'Sin preparar' ?></span>
                        </div>

                        <p class="bilingual-source-note">Este prototipo no modifica el inglés existente. Cuando el español esté aprobado, aquí aparecería una propuesta de adaptación para comparar y revisar.</p>

                        <div class="bilingual-editor-fields">
                            <div class="bilingual-field">
                                <label for="english-title">Original title</label>
                                <input id="english-title" type="text" value="<?= bilingual_experiment_h($title) ?>">
                            </div>
                            <div class="bilingual-field">
                                <label for="english-subtitle">English subtitle</label>
                                <input id="english-subtitle" type="text" value="<?= bilingual_experiment_h($subtitle) ?>">
                            </div>
                            <div class="bilingual-field bilingual-field--full">
                                <label for="english-description">Current English description</label>
                                <textarea id="english-description"><?= bilingual_experiment_h($englishDescription) ?></textarea>
                            </div>
                            <div class="bilingual-field bilingual-field--full">
                                <label for="english-summary">Current short description</label>
                                <textarea class="bilingual-short-copy" id="english-summary"><?= bilingual_experiment_h($englishShortDescription) ?></textarea>
                            </div>
                        </div>
                    </section>
                </div>

                <section class="bilingual-compare-panel" data-compare-panel hidden aria-label="Comparación editorial">
                    <article class="bilingual-compare-sheet bilingual-compare-sheet--es">
                        <h3 data-compare-spanish-title><?= bilingual_experiment_h($title) ?></h3>
                        <small>Español · fuente</small>
                        <p class="bilingual-compare-copy bilingual-empty-copy" data-compare-spanish-description>Escribí una descripción en español para verla aquí.</p>
                    </article>
                    <article class="bilingual-compare-sheet bilingual-compare-sheet--en">
                        <h3><?= bilingual_experiment_h($title) ?></h3>
                        <small>English · current version</small>
                        <p class="bilingual-compare-copy <?= $englishDescription === '' ? 'bilingual-empty-copy' : '' ?>"><?= bilingual_experiment_h($englishDescription !== '' ? $englishDescription : 'No English description is currently available.') ?></p>
                    </article>
                </section>
            </section>
        </div>
    </main>
</div>

<script>
(() => {
    const languageButtons = [...document.querySelectorAll('[data-language]')];
    const languagePanels = [...document.querySelectorAll('[data-language-panel]')];
    const compareToggle = document.querySelector('[data-compare-toggle]');
    const comparePanel = document.querySelector('[data-compare-panel]');
    const spanishTitle = document.querySelector('[data-spanish-title]');
    const spanishDescription = document.querySelector('[data-spanish-description]');
    const compareSpanishTitle = document.querySelector('[data-compare-spanish-title]');
    const compareSpanishDescription = document.querySelector('[data-compare-spanish-description]');

    const selectLanguage = (language) => {
        languageButtons.forEach((button) => {
            const active = button.dataset.language === language;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        languagePanels.forEach((panel) => {
            panel.hidden = panel.dataset.languagePanel !== language;
        });
    };

    const syncSpanishPreview = () => {
        const title = spanishTitle.value.trim();
        const description = spanishDescription.value.trim();
        compareSpanishTitle.textContent = title || 'Sin título';
        compareSpanishDescription.textContent = description || 'Escribí una descripción en español para verla aquí.';
        compareSpanishDescription.classList.toggle('bilingual-empty-copy', description === '');
    };

    languageButtons.forEach((button) => {
        button.addEventListener('click', () => selectLanguage(button.dataset.language));
    });

    compareToggle.addEventListener('click', () => {
        const willOpen = comparePanel.hidden;
        comparePanel.hidden = !willOpen;
        compareToggle.setAttribute('aria-pressed', willOpen ? 'true' : 'false');
        compareToggle.textContent = willOpen ? 'Cerrar comparación' : 'Comparar idiomas';
        if (willOpen) syncSpanishPreview();
    });

    document.querySelector('[data-preview-english]').addEventListener('click', () => {
        selectLanguage('en');
        document.querySelector('[data-language-panel="en"]').scrollIntoView({ behavior: 'smooth', block: 'start' });
    });

    spanishTitle.addEventListener('input', syncSpanishPreview);
    spanishDescription.addEventListener('input', syncSpanishPreview);
})();
</script>
</body>
</html>

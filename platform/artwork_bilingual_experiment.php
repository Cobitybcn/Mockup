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
$facts = array_values(array_filter([$series, $year, $medium, $dimensions], static fn(string $value): bool => $value !== ''));
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
            gap: 22px;
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

        .editorial-identity {
            display: grid;
            grid-template-columns: 170px minmax(0, 1fr);
            gap: 26px;
            align-items: center;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--line);
        }

        .editorial-artwork-media {
            display: grid;
            place-items: center;
            height: 190px;
            background: var(--surface-soft);
            overflow: hidden;
        }

        .editorial-artwork-media img {
            display: block;
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .editorial-identity-copy { min-width: 0; }

        .editorial-artwork-facts {
            display: flex;
            flex-wrap: wrap;
            gap: 7px 15px;
            margin: 16px 0 0;
            color: var(--muted);
            font-size: 12px;
        }

        .editorial-workspace {
            padding: 20px;
            border: 1px solid var(--line);
            background: var(--surface);
        }

        .editorial-title-memo {
            margin-top: 16px;
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

        .editorial-spread {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
            padding-top: 20px;
        }

        .editorial-page {
            display: flex;
            flex-direction: column;
            min-width: 0;
            min-height: 460px;
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

        .editorial-source-copy {
            display: block;
            width: 100%;
            min-height: 300px;
            box-sizing: border-box;
            margin-top: 20px;
            padding: 0;
            color: var(--ink);
            font: 400 15px/1.75 var(--font-sans);
            white-space: pre-wrap;
        }

        .editorial-page > .editorial-source-copy,
        .editorial-page > .editorial-existing-copy {
            flex: 1 1 auto;
        }

        .editorial-source-copy:empty::before {
            content: attr(data-placeholder);
            color: var(--muted);
            font-style: italic;
        }

        .editorial-source-copy:focus { outline: 0; }

        .editorial-existing-copy {
            margin-top: 20px;
            color: var(--ink);
            font-size: 15px;
            line-height: 1.75;
            white-space: pre-line;
        }

        .editorial-existing-copy.is-empty {
            color: var(--muted);
            font-style: italic;
        }

        .editorial-short-section {
            flex: 0 0 132px;
            min-height: 132px;
            margin-top: 20px;
            padding-top: 14px;
            border-top: 1px solid var(--line);
        }

        .editorial-short-label {
            display: block;
            color: var(--muted);
            font-size: 9px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .editorial-source-copy--short {
            min-height: 92px;
            margin-top: 14px;
        }

        .editorial-short-copy {
            margin: 14px 0 0;
            color: var(--ink);
            font-size: 14px;
            line-height: 1.7;
            white-space: pre-line;
        }

        .editorial-short-copy.is-empty {
            color: var(--muted);
            font-style: italic;
        }

        .editorial-private-memo {
            margin-top: 16px;
            padding: 16px 2px 0;
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

        @media (max-width: 900px) {
            .editorial-spread { grid-template-columns: 1fr; }
            .editorial-page { min-height: 380px; }
        }

        @media (max-width: 680px) {
            .editorial-experiment-topline { align-items: flex-start; flex-direction: column; }
            .editorial-identity { grid-template-columns: 100px minmax(0, 1fr); gap: 14px; }
            .editorial-artwork-media { height: 124px; }
            .editorial-shared-title-text { font-size: 31px; }
            .editorial-artwork-facts { margin-top: 12px; font-size: 10px; }
            .editorial-workspace { padding: 12px; }
            .editorial-page { padding: 18px 15px; }
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

            <section class="editorial-workspace" aria-label="Contenido editorial bilingüe">
                <div class="editorial-identity">
                    <div class="editorial-artwork-media">
                        <?php if ($imageFile !== ''): ?>
                            <img src="<?= bilingual_experiment_h(bilingual_experiment_media_url($imageFile)) ?>" alt="<?= bilingual_experiment_h($title) ?>">
                        <?php else: ?>
                            <span>Sin imagen</span>
                        <?php endif; ?>
                    </div>
                    <div class="editorial-identity-copy">
                        <div class="editorial-shared-title">
                            <span class="editorial-shared-title-label">Título de la obra · universal</span>
                            <h1 class="editorial-shared-title-text" contenteditable="true" role="textbox" aria-label="Título de la obra"><?= bilingual_experiment_h($title) ?></h1>
                        </div>
                        <?php if ($facts !== []): ?>
                            <p class="editorial-artwork-facts">
                                <?php foreach ($facts as $fact): ?><span><?= bilingual_experiment_h($fact) ?></span><?php endforeach; ?>
                            </p>
                        <?php endif; ?>
                        <div class="editorial-title-memo">
                            <span contenteditable="true" role="textbox" aria-label="Memo privado del sistema de títulos">STRATA X — LIMEN · STRATA XI — NUHRĀ (ܢܘܗܪܐ) · no traducir</span>
                        </div>
                    </div>
                </div>

                <div class="editorial-spread">
                    <article class="editorial-page editorial-page--source">
                        <span class="editorial-language-label">Español · fuente</span>
                        <div class="editorial-source-copy" contenteditable="true" role="textbox" aria-multiline="true" aria-label="Descripción en español" data-placeholder="Escribí una descripción en español…"></div>
                        <section class="editorial-short-section" aria-labelledby="spanish-short-label">
                            <span class="editorial-short-label" id="spanish-short-label">Resumen breve · español</span>
                            <div class="editorial-source-copy editorial-source-copy--short" contenteditable="true" role="textbox" aria-multiline="true" aria-label="Resumen breve en español" data-placeholder="Dos o tres frases para series, tarjetas y mockups…"></div>
                        </section>
                    </article>

                    <article class="editorial-page editorial-page--english">
                        <span class="editorial-language-label">English · current version</span>
                        <div class="editorial-existing-copy <?= $englishDescription === '' ? 'is-empty' : '' ?>"><?= bilingual_experiment_h($englishDescription !== '' ? $englishDescription : 'No English description is currently available.') ?></div>
                        <section class="editorial-short-section" aria-labelledby="english-short-label">
                            <span class="editorial-short-label" id="english-short-label">Short description · English</span>
                            <p class="editorial-short-copy <?= $englishShortDescription === '' ? 'is-empty' : '' ?>"><?= bilingual_experiment_h($englishShortDescription !== '' ? $englishShortDescription : 'No English short description is currently available.') ?></p>
                        </section>
                    </article>
                </div>

                <details class="editorial-private-memo">
                    <summary>Memo privado de la obra</summary>
                    <div class="editorial-source-copy editorial-source-copy--short" contenteditable="true" role="textbox" aria-multiline="true" aria-label="Memo privado de la obra" data-placeholder="Ideas, decisiones y recordatorios que no se publican…"></div>
                </details>
            </section>
        </div>
    </main>
</div>

</body>
</html>

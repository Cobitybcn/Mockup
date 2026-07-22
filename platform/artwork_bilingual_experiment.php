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

        .editorial-artwork {
            display: grid;
            grid-template-columns: 170px minmax(0, 1fr);
            gap: 26px;
            align-items: center;
            padding: 18px;
            border: 1px solid var(--line);
            background: var(--surface);
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

        .editorial-artwork-copy { min-width: 0; }

        .editorial-artwork-copy h1 {
            margin: 0;
            font: 500 clamp(34px, 4vw, 54px)/1.05 var(--font-serif);
            overflow-wrap: anywhere;
        }

        .editorial-artwork-subtitle {
            margin: 8px 0 0;
            color: var(--accent);
            font: 400 20px/1.35 var(--font-serif);
        }

        .editorial-artwork-facts {
            display: flex;
            flex-wrap: wrap;
            gap: 7px 15px;
            margin: 20px 0 0;
            color: var(--muted);
            font-size: 12px;
        }

        .editorial-workspace {
            padding: 20px;
            border: 1px solid var(--line);
            background: var(--surface);
        }

        .editorial-workspace-header {
            display: flex;
            align-items: end;
            justify-content: space-between;
            gap: 24px;
            padding-bottom: 18px;
            border-bottom: 1px solid var(--line);
        }

        .editorial-workspace-header h2 {
            margin: 0;
            font: 500 clamp(28px, 3vw, 40px)/1.05 var(--font-serif);
        }

        .editorial-workspace-header p {
            max-width: 580px;
            margin: 6px 0 0;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.5;
        }

        .editorial-unsaved {
            color: var(--muted);
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .07em;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .editorial-title-memo {
            display: grid;
            grid-template-columns: 130px minmax(0, 1fr);
            gap: 14px;
            align-items: center;
            padding: 15px 0;
            border-bottom: 1px solid var(--line);
        }

        .editorial-shared-title {
            padding: 24px 0 20px;
            border-bottom: 1px solid var(--line);
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

        .editorial-title-memo label,
        .editorial-language-label {
            color: var(--muted);
            font-size: 9px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .editorial-title-memo [contenteditable] {
            width: 100%;
            min-width: 0;
            box-sizing: border-box;
            padding: 5px 0;
            color: var(--ink);
            font: 500 16px/1.45 var(--font-serif);
        }

        .editorial-title-memo [contenteditable]:focus { outline: 0; }

        .editorial-spread {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
            padding-top: 20px;
        }

        .editorial-page {
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

        .editorial-secondary {
            margin-top: 20px;
            padding-top: 14px;
            border-top: 1px solid var(--line);
        }

        .editorial-secondary summary {
            cursor: pointer;
            color: var(--muted);
            font-size: 9px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .editorial-source-copy--short {
            min-height: 100px;
            margin-top: 14px;
        }

        .editorial-secondary p {
            margin: 14px 0 0;
            color: var(--ink);
            font-size: 14px;
            line-height: 1.7;
            white-space: pre-line;
        }

        .editorial-secondary p.is-empty {
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
            .editorial-experiment-topline,
            .editorial-workspace-header { align-items: flex-start; flex-direction: column; }
            .editorial-artwork { grid-template-columns: 100px minmax(0, 1fr); gap: 14px; padding: 12px; }
            .editorial-artwork-media { height: 124px; }
            .editorial-artwork-copy h1 { font-size: 29px; }
            .editorial-artwork-subtitle { font-size: 16px; }
            .editorial-artwork-facts { margin-top: 12px; font-size: 10px; }
            .editorial-workspace { padding: 12px; }
            .editorial-title-memo { grid-template-columns: 1fr; gap: 4px; }
            .editorial-page { padding: 18px 15px; }
            .editorial-unsaved { white-space: normal; }
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
                <p class="editorial-experiment-state">Edición bilingüe · experimento privado</p>
                <a class="editorial-back" href="artwork.php?id=<?= $artworkId ?>">Volver a la ficha actual</a>
            </div>

            <section class="editorial-artwork" aria-labelledby="editorial-artwork-title">
                <div class="editorial-artwork-media">
                    <?php if ($imageFile !== ''): ?>
                        <img src="<?= bilingual_experiment_h(bilingual_experiment_media_url($imageFile)) ?>" alt="<?= bilingual_experiment_h($title) ?>">
                    <?php else: ?>
                        <span>Sin imagen</span>
                    <?php endif; ?>
                </div>
                <div class="editorial-artwork-copy">
                    <h1 id="editorial-artwork-title" data-overview-title><?= bilingual_experiment_h($title) ?></h1>
                    <?php if ($subtitle !== ''): ?><p class="editorial-artwork-subtitle"><?= bilingual_experiment_h($subtitle) ?></p><?php endif; ?>
                    <?php if ($facts !== []): ?>
                        <p class="editorial-artwork-facts">
                            <?php foreach ($facts as $fact): ?><span><?= bilingual_experiment_h($fact) ?></span><?php endforeach; ?>
                        </p>
                    <?php endif; ?>
                </div>
            </section>

            <section class="editorial-workspace" aria-labelledby="editorial-content-title">
                <header class="editorial-workspace-header">
                    <div>
                        <h2 id="editorial-content-title">Contenido editorial</h2>
                        <p>El español se construye mirando el inglés existente, sin duplicar la obra.</p>
                    </div>
                    <span class="editorial-unsaved">Prueba local · no guarda datos</span>
                </header>

                <div class="editorial-shared-title">
                    <span class="editorial-shared-title-label">Título de la obra · universal</span>
                    <h3 class="editorial-shared-title-text" contenteditable="true" role="textbox" aria-label="Título de la obra" data-shared-title><?= bilingual_experiment_h($title) ?></h3>
                </div>

                <div class="editorial-title-memo">
                    <label id="title-system-memo-label">Memo de títulos</label>
                    <span contenteditable="true" role="textbox" aria-labelledby="title-system-memo-label">STRATA X — LIMEN · STRATA XI — NUHRĀ (ܢܘܗܪܐ) · no traducir</span>
                </div>

                <div class="editorial-spread">
                    <article class="editorial-page editorial-page--source">
                        <span class="editorial-language-label">Español · fuente</span>
                        <div class="editorial-source-copy" contenteditable="true" role="textbox" aria-multiline="true" aria-label="Descripción en español" data-placeholder="Escribí una descripción en español…"></div>
                        <details class="editorial-secondary">
                            <summary>Resumen breve · español</summary>
                            <div class="editorial-source-copy editorial-source-copy--short" contenteditable="true" role="textbox" aria-multiline="true" aria-label="Resumen breve en español" data-placeholder="Dos o tres frases para series, tarjetas y mockups…"></div>
                        </details>
                    </article>

                    <article class="editorial-page editorial-page--english">
                        <span class="editorial-language-label">English · current version</span>
                        <div class="editorial-existing-copy <?= $englishDescription === '' ? 'is-empty' : '' ?>"><?= bilingual_experiment_h($englishDescription !== '' ? $englishDescription : 'No English description is currently available.') ?></div>
                        <details class="editorial-secondary">
                            <summary>Short description · English</summary>
                            <p class="<?= $englishShortDescription === '' ? 'is-empty' : '' ?>"><?= bilingual_experiment_h($englishShortDescription !== '' ? $englishShortDescription : 'No English short description is currently available.') ?></p>
                        </details>
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

<script>
(() => {
    const sharedTitle = document.querySelector('[data-shared-title]');
    const overviewTitle = document.querySelector('[data-overview-title]');

    sharedTitle.addEventListener('input', () => {
        overviewTitle.textContent = sharedTitle.textContent.trim() || 'Sin título';
    });
})();
</script>
</body>
</html>

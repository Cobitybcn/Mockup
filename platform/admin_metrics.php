<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$currentUser = Auth::requireUser();
FeatureAccess::requirePage($currentUser, FeatureAccess::WEBSITE_MANAGE, 'Site Metrics');

$pdo = Database::connection();
$ownerId = (int)$currentUser['id'];

$rangeDays = (int)($_GET['days'] ?? 30);
if (!in_array($rangeDays, [7, 30, 90, 365], true)) $rangeDays = 30;
$since = date(DATE_ATOM, time() - $rangeDays * 86400);

$totalsStmt = $pdo->prepare('SELECT event_type, COUNT(*) AS total FROM artist_site_events WHERE user_id = ? AND created_at >= ? GROUP BY event_type ORDER BY total DESC');
$totalsStmt->execute([$ownerId, $since]);
$totals = $totalsStmt->fetchAll();

$saatchiStmt = $pdo->prepare("SELECT COALESCE(NULLIF(slug,''), detail) AS label, COUNT(*) AS total
    FROM artist_site_events WHERE user_id = ? AND created_at >= ? AND event_type = 'saatchi_click'
    GROUP BY label ORDER BY total DESC LIMIT 20");
$saatchiStmt->execute([$ownerId, $since]);
$saatchiClicks = $saatchiStmt->fetchAll();

$viewsStmt = $pdo->prepare("SELECT slug, COUNT(*) AS total FROM artist_site_events
    WHERE user_id = ? AND created_at >= ? AND event_type = 'view' AND slug <> ''
    GROUP BY slug ORDER BY total DESC LIMIT 20");
$viewsStmt->execute([$ownerId, $since]);
$topViews = $viewsStmt->fetchAll();

$searchStmt = $pdo->prepare("SELECT detail, COUNT(*) AS total FROM artist_site_events
    WHERE user_id = ? AND created_at >= ? AND event_type = 'search' AND detail <> ''
    GROUP BY detail ORDER BY total DESC LIMIT 30");
$searchStmt->execute([$ownerId, $since]);
$searches = $searchStmt->fetchAll();

$localeStmt = $pdo->prepare("SELECT locale, COUNT(*) AS total FROM artist_site_events
    WHERE user_id = ? AND created_at >= ? AND event_type = 'view' AND locale <> ''
    GROUP BY locale ORDER BY total DESC");
$localeStmt->execute([$ownerId, $since]);
$locales = $localeStmt->fetchAll();

$referrerStmt = $pdo->prepare("SELECT referrer_host, COUNT(*) AS total FROM artist_site_events
    WHERE user_id = ? AND created_at >= ? AND event_type = 'view' AND referrer_host <> ''
    GROUP BY referrer_host ORDER BY total DESC LIMIT 15");
$referrerStmt->execute([$ownerId, $since]);
$referrers = $referrerStmt->fetchAll();
?>
<!doctype html>
<html lang="<?= h(Translator::locale($currentUser)) ?>">
<head>
    <meta charset="utf-8">
    <title><?= h(t('Site Metrics - Artwork Mockups', 'Métricas del sitio - Artwork Mockups')) ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .metrics-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(340px,1fr)); gap:20px; margin-top:20px; }
        .metrics-card { border:1px solid var(--line); border-radius:var(--radius); background:#fff; padding:18px 20px; }
        .metrics-card h2 { margin:0 0 4px; font:500 19px/1.25 var(--font-serif); }
        .metrics-card > p { margin:0 0 12px; color:var(--muted); font-size:12px; }
        .metrics-table { width:100%; border-collapse:collapse; font-size:13px; text-align:left; }
        .metrics-table th { padding:8px 10px; border-bottom:2px solid var(--line); color:var(--muted); font-size:10px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; }
        .metrics-table td { padding:8px 10px; border-bottom:1px solid var(--line); color:var(--ink); }
        .metrics-table td.num { text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }
        .metrics-empty { padding:14px 0 4px; color:var(--muted); font-size:13px; }
        .metrics-range { display:flex; gap:8px; flex-wrap:wrap; }
        .metrics-range a { padding:7px 13px; border:1px solid var(--line); border-radius:999px; color:var(--muted); font-size:12px; font-weight:700; text-decoration:none; }
        .metrics-range a.active { border-color:var(--accent); background:rgba(224,104,76,.09); color:var(--ink); }
    </style>
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="main-area">
        <header class="app-header">
            <a class="user-chip" href="account.php"><?= h($currentUser['email']) ?></a>
        </header>

        <div class="alert-strip">
            <?= h(t('First-party visitor metrics collected on the public artist site. No cookies, no IP addresses.', 'Métricas propias de visitantes del sitio público del artista. Sin cookies ni direcciones IP.')) ?>
        </div>

        <div class="workspace">
            <div class="workspace-header">
                <div>
                    <h1><?= h(t('Site Metrics', 'Métricas del sitio')) ?></h1>
                    <p><?= h(t('Views, Saatchi Art clicks, searches, and traffic sources from the public website.', 'Vistas, clics a Saatchi Art, búsquedas y fuentes de tráfico del sitio público.')) ?></p>
                </div>
                <div class="metrics-range">
                    <?php foreach ([7 => t('7 days', '7 días'), 30 => t('30 days', '30 días'), 90 => t('90 days', '90 días'), 365 => t('1 year', '1 año')] as $days => $label): ?>
                        <a class="<?= $rangeDays === $days ? 'active' : '' ?>" href="admin_metrics.php?days=<?= $days ?>"><?= h($label) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="metrics-grid">
                <section class="metrics-card">
                    <h2><?= h(t('Activity summary', 'Resumen de actividad')) ?></h2>
                    <p><?= h(t('Events by type in the selected range.', 'Eventos por tipo en el rango seleccionado.')) ?></p>
                    <?php if ($totals): ?>
                        <table class="metrics-table"><tr><th><?= h(t('Event', 'Evento')) ?></th><th class="num"><?= h(t('Total', 'Total')) ?></th></tr>
                        <?php foreach ($totals as $row): ?><tr><td><?= h((string)$row['event_type']) ?></td><td class="num"><?= (int)$row['total'] ?></td></tr><?php endforeach; ?>
                        </table>
                    <?php else: ?><p class="metrics-empty"><?= h(t('No events recorded yet.', 'Todavía no hay eventos registrados.')) ?></p><?php endif; ?>
                </section>

                <section class="metrics-card">
                    <h2><?= h(t('Saatchi Art clicks', 'Clics a Saatchi Art')) ?></h2>
                    <p><?= h(t('Which artworks send visitors to Saatchi.', 'Qué obras envían visitantes a Saatchi.')) ?></p>
                    <?php if ($saatchiClicks): ?>
                        <table class="metrics-table"><tr><th><?= h(t('Artwork / origin', 'Obra / origen')) ?></th><th class="num"><?= h(t('Clicks', 'Clics')) ?></th></tr>
                        <?php foreach ($saatchiClicks as $row): ?><tr><td><?= h((string)$row['label']) ?></td><td class="num"><?= (int)$row['total'] ?></td></tr><?php endforeach; ?>
                        </table>
                    <?php else: ?><p class="metrics-empty"><?= h(t('No Saatchi clicks in this range.', 'Sin clics a Saatchi en este rango.')) ?></p><?php endif; ?>
                </section>

                <section class="metrics-card">
                    <h2><?= h(t('Most viewed pages', 'Páginas más vistas')) ?></h2>
                    <p><?= h(t('Public pages by first-party view count.', 'Páginas públicas por conteo propio de vistas.')) ?></p>
                    <?php if ($topViews): ?>
                        <table class="metrics-table"><tr><th><?= h(t('Page', 'Página')) ?></th><th class="num"><?= h(t('Views', 'Vistas')) ?></th></tr>
                        <?php foreach ($topViews as $row): ?><tr><td><?= h((string)$row['slug']) ?></td><td class="num"><?= (int)$row['total'] ?></td></tr><?php endforeach; ?>
                        </table>
                    <?php else: ?><p class="metrics-empty"><?= h(t('No views recorded yet.', 'Todavía no hay vistas registradas.')) ?></p><?php endif; ?>
                </section>

                <section class="metrics-card">
                    <h2><?= h(t('Visitor searches', 'Búsquedas de visitantes')) ?></h2>
                    <p><?= h(t('What visitors type into the site search — direct vocabulary intelligence.', 'Lo que los visitantes escriben en el buscador — vocabulario real de tu público.')) ?></p>
                    <?php if ($searches): ?>
                        <table class="metrics-table"><tr><th><?= h(t('Query', 'Búsqueda')) ?></th><th class="num"><?= h(t('Times', 'Veces')) ?></th></tr>
                        <?php foreach ($searches as $row): ?><tr><td><?= h((string)$row['detail']) ?></td><td class="num"><?= (int)$row['total'] ?></td></tr><?php endforeach; ?>
                        </table>
                    <?php else: ?><p class="metrics-empty"><?= h(t('No searches recorded yet.', 'Todavía no hay búsquedas registradas.')) ?></p><?php endif; ?>
                </section>

                <section class="metrics-card">
                    <h2><?= h(t('Language demand', 'Demanda por idioma')) ?></h2>
                    <p><?= h(t('Views by interface language.', 'Vistas según idioma de navegación.')) ?></p>
                    <?php if ($locales): ?>
                        <table class="metrics-table"><tr><th><?= h(t('Language', 'Idioma')) ?></th><th class="num"><?= h(t('Views', 'Vistas')) ?></th></tr>
                        <?php foreach ($locales as $row): ?><tr><td><?= h(strtoupper((string)$row['locale'])) ?></td><td class="num"><?= (int)$row['total'] ?></td></tr><?php endforeach; ?>
                        </table>
                    <?php else: ?><p class="metrics-empty"><?= h(t('No localized views yet.', 'Todavía no hay vistas con idioma.')) ?></p><?php endif; ?>
                </section>

                <section class="metrics-card">
                    <h2><?= h(t('Traffic sources', 'Fuentes de tráfico')) ?></h2>
                    <p><?= h(t('External sites that referred visitors.', 'Sitios externos que refirieron visitantes.')) ?></p>
                    <?php if ($referrers): ?>
                        <table class="metrics-table"><tr><th><?= h(t('Source', 'Fuente')) ?></th><th class="num"><?= h(t('Views', 'Vistas')) ?></th></tr>
                        <?php foreach ($referrers as $row): ?><tr><td><?= h((string)$row['referrer_host']) ?></td><td class="num"><?= (int)$row['total'] ?></td></tr><?php endforeach; ?>
                        </table>
                    <?php else: ?><p class="metrics-empty"><?= h(t('Direct traffic only so far.', 'Por ahora solo tráfico directo.')) ?></p><?php endif; ?>
                </section>
            </div>
        </div>
    </main>
</div>
</body>
</html>

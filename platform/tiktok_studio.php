<?php
declare(strict_types=1);

require_once __DIR__ . '/app/Video/bootstrap.php';

$user = Auth::requireUser();
FeatureAccess::requirePage($user, FeatureAccess::VIDEO_MANAGE, 'Videos');
$userId = (int)$user['id'];
$pdo = Database::connection();

$repository = new VideoStudioRepository($pdo);
$finals = $repository->finalVideos($userId);
$csrf = VideoHttp::csrfToken();

$tiktokConnection = (new TikTokIntegrationService($pdo))->connection($userId, 'artist');
$tiktokConnected = ($tiktokConnection['status'] ?? '') === 'connected';
$tiktokUsername = ltrim(trim((string)($tiktokConnection['tiktok_username'] ?? '')), '@');

$publications = new VideoTikTokPublicationService($pdo);
$tiktokRows = $publications->rowsForUser($userId);

$boardService = new TikTokBoardService($pdo);
$boards = $boardService->boardsForUser($userId);
$boardIdsByVideo = $boardService->boardIdsByVideo($userId);

$jobsService = new SocialPublishJobService($pdo);
$scheduledService = new SocialScheduledPublicationService($pdo, $jobsService);
$jobsByVideo = [];
foreach ($scheduledService->recent($userId) as $job) {
    if ((string)$job['channel'] !== 'tiktok') continue;
    $videoExportId = (int)($job['video_export_id'] ?? 0);
    if ($videoExportId <= 0) continue;
    if (!isset($jobsByVideo[$videoExportId]) || (int)$job['id'] > (int)$jobsByVideo[$videoExportId]['id']) {
        $jobsByVideo[$videoExportId] = $job;
    }
}

$finalsById = [];
foreach ($finals as $final) {
    $finalsById[(int)$final['id']] = $final;
}
$unassigned = array_values(array_filter($finals, static function (array $final) use ($boardIdsByVideo, $tiktokRows): bool {
    $id = (int)$final['id'];
    if (isset($boardIdsByVideo[$id])) return false;
    $status = (string)($tiktokRows[$id]['status'] ?? '');
    return !in_array($status, ['processing', 'published'], true);
}));

function tstudio_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function tstudio_status_label(string $status): string
{
    return match ($status) {
        'scheduled' => t('SCHEDULED', 'PROGRAMADO'),
        'queued', 'processing' => t('SENDING…', 'ENVIANDO…'),
        'published' => t('PUBLISHED', 'PUBLICADO'),
        'failed' => t('FAILED', 'FALLÓ'),
        default => t('DRAFT', 'BORRADOR'),
    };
}

function tstudio_board_date_label(string $date): string
{
    $timestamp = strtotime($date);
    if (!$timestamp) return $date;
    if (Translator::locale() !== 'es') {
        return date('l, F j, Y', $timestamp);
    }
    $days = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
    $months = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    $day = $days[(int)date('w', $timestamp)];
    $month = $months[((int)date('n', $timestamp)) - 1];
    return ucfirst($day) . ' ' . date('j', $timestamp) . ' de ' . $month . ' de ' . date('Y', $timestamp);
}
?>
<!doctype html>
<html lang="<?= tstudio_h(Translator::locale($user)) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= tstudio_h(t('TikTok Studio - Artwork Mockups', 'TikTok Studio - Artwork Mockups')) ?></title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="ui-catalog.css">
    <link rel="stylesheet" href="videos.css?v=15">
    <link rel="stylesheet" href="media-controls.css?v=2">
    <link rel="stylesheet" href="tiktok_studio.css?v=3">
</head>
<body data-csrf="<?= tstudio_h($csrf) ?>">
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="main-area">
        <header class="app-header">
            <a class="user-chip" href="account.php"><?= tstudio_h($user['email']) ?></a>
        </header>

        <div class="alert-strip"><?= tstudio_h(t('Plan and schedule TikTok videos by publish date — real, automatic delivery, not just a reminder.', 'Planificá y programá videos de TikTok por fecha de publicación — entrega real y automática, no solo un recordatorio.')) ?></div>

        <div class="videos-catalog tstudio">
            <header class="catalog-heading videos-heading">
                <div>
                    <span class="videos-kicker"><?= tstudio_h(t('Videos', 'Videos')) ?></span>
                    <h1><?= tstudio_h(t('TikTok Studio', 'TikTok Studio')) ?></h1>
                    <p class="videos-page-desc">
                        <span class="videos-desc-instructions"><?= tstudio_h(t('Organize finished videos into publish-date boards, write the caption once, and let it publish itself at the scheduled time.', 'Organizá los videos terminados en boards por fecha de publicación, escribí el copy una vez, y dejá que se publique solo a la hora programada.')) ?></span>
                    </p>
                </div>
                <div class="videos-primary-actions">
                    <a class="videos-decision-block videos-decision-block--secondary" href="videos.php">← <?= tstudio_h(t('Back to Videos', 'Volver a Videos')) ?></a>
                </div>
            </header>

            <?php if (!$tiktokConnected): ?>
                <div class="tstudio-setup-note">
                    <p><?= tstudio_h(t('Connect TikTok before scheduling videos.', 'Conectá TikTok antes de programar videos.')) ?> <a href="connections.php?open=tiktok"><?= tstudio_h(t('Connect', 'Conectar')) ?></a></p>
                </div>
            <?php else: ?>
                <details class="tstudio-setup-note">
                    <summary><?= tstudio_h(t('One-time setup: connect this to your Saatchi Art / website', 'Configuración única: conectá esto con tu Saatchi Art / sitio web')) ?></summary>
                    <p><?= tstudio_h(t('TikTok does not let videos carry a clickable link — only the profile bio does, and TikTok\'s API cannot set it for you. Do this once, manually, inside the TikTok app:', 'TikTok no permite que los videos lleven un link clickeable — solo la biografía del perfil lo permite, y la API de TikTok no puede configurarlo por vos. Hacé esto una sola vez, a mano, dentro de la app de TikTok:')) ?></p>
                    <p><?= tstudio_h(t('Profile → Edit profile → Website → paste', 'Perfil → Editar perfil → Sitio web → pegá')) ?> <code>https://mauriziovalch.com</code></p>
                    <p><?= tstudio_h(t('Below, the "destination" field on each video is only for your own tracking — it is not sent to TikTok.', 'Abajo, el campo "destino" de cada video es solo para tu propio seguimiento — no se envía a TikTok.')) ?></p>
                </details>
            <?php endif; ?>

            <section class="catalog-panel videos-panel tstudio-rail-panel" aria-labelledby="tstudio-unassigned-title">
                <div class="videos-panel-heading">
                    <div>
                        <span><?= tstudio_h(t('To organize', 'Por organizar')) ?></span>
                        <div class="videos-panel-title-row">
                            <h2 id="tstudio-unassigned-title"><?= tstudio_h(t('Unassigned videos', 'Videos sin asignar')) ?></h2>
                            <span class="videos-count"><?= count($unassigned) ?></span>
                        </div>
                    </div>
                </div>
                <?php if (!$unassigned): ?>
                    <p class="tstudio-empty"><?= tstudio_h(t('Every finished video is already on a board.', 'Todos los videos terminados ya están en algún board.')) ?></p>
                <?php else: ?>
                    <div class="tstudio-rail-wrap">
                        <button class="tstudio-rail-arrow tstudio-rail-arrow--left" type="button" data-tstudio-scroll="-1" aria-label="<?= tstudio_h(t('Previous', 'Anteriores')) ?>">‹</button>
                        <div class="tstudio-rail" data-tstudio-rail>
                        <?php foreach ($unassigned as $final): ?>
                            <?php
                            $exportId = (int)$final['id'];
                            $title = trim((string)($final['displayTitle'] ?? '')) ?: t('Final video', 'Video final');
                            $row = $tiktokRows[$exportId] ?? null;
                            $draftCaption = trim((string)($row['caption'] ?? '')) ?: $title;
                            $draftTags = trim((string)($row['tags'] ?? ''));
                            ?>
                            <article class="tstudio-rail-card">
                                <div class="tstudio-rail-thumb">
                                    <?php if (!empty($final['thumbnailUrl'])): ?><img src="<?= tstudio_h((string)$final['thumbnailUrl']) ?>" alt="<?= tstudio_h($title) ?>" loading="lazy"><?php endif; ?>
                                    <span class="tstudio-play" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M8 5.5v13l11-6.5-11-6.5Z"/></svg></span>
                                </div>
                                <h3><?= tstudio_h($title) ?></h3>
                                <form class="tstudio-unassigned-form" data-tstudio-unassigned-form>
                                    <input type="hidden" name="csrf" value="<?= tstudio_h($csrf) ?>">
                                    <input type="hidden" name="exportId" value="<?= $exportId ?>">
                                    <div class="tstudio-caption-row">
                                        <textarea name="caption" maxlength="2200" rows="2" placeholder="<?= tstudio_h(t('Caption', 'Texto')) ?>" data-tstudio-caption><?= tstudio_h($draftCaption) ?></textarea>
                                        <button type="button" class="tstudio-suggest-btn" data-tstudio-suggest data-export-id="<?= $exportId ?>"><?= tstudio_h(t('Suggest caption & tags', 'Sugerir copy y tags')) ?></button>
                                    </div>
                                    <label class="tstudio-field">
                                        <span><?= tstudio_h(t('Tags', 'Tags')) ?></span>
                                        <input type="text" name="tags" placeholder="#art #tiktokart" value="<?= tstudio_h($draftTags) ?>" data-tstudio-tags>
                                    </label>
                                    <div class="tstudio-when-row">
                                        <label><span><?= tstudio_h(t('Date', 'Fecha')) ?></span><input type="date" name="date" min="<?= tstudio_h(date('Y-m-d')) ?>"></label>
                                        <label><span><?= tstudio_h(t('Time', 'Hora')) ?></span><input type="time" name="time" value="12:00"></label>
                                    </div>
                                    <div class="tstudio-unassigned-actions">
                                        <button type="submit" data-action="schedule"><?= tstudio_h(t('Schedule', 'Programar')) ?></button>
                                        <button type="submit" data-action="publish_now" class="tstudio-manage-btn"><?= tstudio_h(t('Publish now', 'Publicar ahora')) ?></button>
                                    </div>
                                    <small data-tstudio-schedule-error hidden></small>
                                </form>
                            </article>
                        <?php endforeach; ?>
                        </div>
                        <button class="tstudio-rail-arrow tstudio-rail-arrow--right" type="button" data-tstudio-scroll="1" aria-label="<?= tstudio_h(t('More', 'Más')) ?>">›</button>
                    </div>
                <?php endif; ?>
            </section>

            <div class="tstudio-add-board">
                <form data-tstudio-create-board-form>
                    <input type="hidden" name="csrf" value="<?= tstudio_h($csrf) ?>">
                    <label><span><?= tstudio_h(t('New publish date', 'Nueva fecha de publicación')) ?></span><input type="date" name="date" required min="<?= tstudio_h(date('Y-m-d')) ?>"></label>
                    <label><span><?= tstudio_h(t('Board name (optional)', 'Nombre del board (opcional)')) ?></span><input type="text" name="title" placeholder="<?= tstudio_h(t('e.g. Autumn series launch', 'ej. Lanzamiento serie otoño')) ?>"></label>
                    <button type="submit"><?= tstudio_h(t('+ Create board', '+ Crear board')) ?></button>
                </form>
            </div>

            <?php if (!$boards): ?>
                <p class="tstudio-empty tstudio-empty--boards"><?= tstudio_h(t('No date boards yet — create one above.', 'Todavía no hay boards de fecha — creá uno arriba.')) ?></p>
            <?php endif; ?>

            <?php foreach ($boards as $board): ?>
                <?php $items = array_values(array_filter(array_map(static fn (int $id) => $finalsById[$id] ?? null, $board['video_export_ids']))); ?>
                <section class="catalog-panel videos-panel tstudio-board">
                    <div class="videos-panel-heading">
                        <div>
                            <span><?= tstudio_h(t('Board', 'Board')) ?></span>
                            <div class="videos-panel-title-row">
                                <h2><?= tstudio_h(tstudio_board_date_label($board['publish_date'])) ?></h2>
                                <span class="videos-count"><?= count($items) ?></span>
                            </div>
                        </div>
                    </div>
                    <?php if (!$items): ?>
                        <p class="tstudio-empty"><?= tstudio_h(t('No videos assigned yet.', 'Todavía no hay videos asignados.')) ?></p>
                    <?php endif; ?>
                    <div class="tstudio-board-grid">
                        <?php foreach ($items as $final): ?>
                            <?php
                            $exportId = (int)$final['id'];
                            $title = trim((string)($final['displayTitle'] ?? '')) ?: t('Final video', 'Video final');
                            $row = $tiktokRows[$exportId] ?? null;
                            $job = $jobsByVideo[$exportId] ?? null;
                            $status = (string)($row['status'] ?? '');
                            $caption = trim((string)($row['caption'] ?? '')) ?: trim((string)($final['displayTitle'] ?? ''));
                            $tags = trim((string)($row['tags'] ?? ''));
                            $coverSeconds = (int)round((int)($row['cover_timestamp_ms'] ?? 0) / 1000);
                            $destinationUrl = (string)($row['destination_url'] ?? '');
                            $isLocked = in_array($status, ['queued', 'processing', 'published'], true);
                            $scheduledAt = $job ? (string)($job['scheduled_at'] ?? '') : '';
                            $scheduledDate = '';
                            $scheduledTime = '12:00';
                            if ($scheduledAt !== '') {
                                $ts = strtotime($scheduledAt);
                                if ($ts) { $scheduledDate = date('Y-m-d', $ts); $scheduledTime = date('H:i', $ts); }
                            }
                            if ($scheduledDate === '') $scheduledDate = $board['publish_date'];
                            ?>
                            <article class="tstudio-card">
                                <div class="tstudio-card-thumb">
                                    <?php if (!empty($final['thumbnailUrl'])): ?><img src="<?= tstudio_h((string)$final['thumbnailUrl']) ?>" alt="<?= tstudio_h($title) ?>" loading="lazy"><?php endif; ?>
                                    <span class="tstudio-play" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M8 5.5v13l11-6.5-11-6.5Z"/></svg></span>
                                    <span class="tstudio-status-pill tstudio-status-pill--<?= tstudio_h($status ?: 'draft') ?>"><?= tstudio_h(tstudio_status_label($status)) ?></span>
                                </div>
                                <h3><?= tstudio_h($title) ?></h3>

                                <?php if ($status === 'failed' && trim((string)($row['error'] ?? '')) !== ''): ?>
                                    <p class="tstudio-error"><?= tstudio_h((string)$row['error']) ?></p>
                                <?php endif; ?>

                                <form class="tstudio-schedule-form" data-tstudio-schedule-form>
                                    <input type="hidden" name="csrf" value="<?= tstudio_h($csrf) ?>">
                                    <input type="hidden" name="exportId" value="<?= $exportId ?>">
                                    <div class="tstudio-caption-row">
                                        <textarea name="caption" maxlength="2200" rows="3" placeholder="<?= tstudio_h(t('Caption for TikTok', 'Copy para TikTok')) ?>" data-tstudio-caption <?= $isLocked ? 'disabled' : '' ?>><?= tstudio_h($caption) ?></textarea>
                                        <button type="button" class="tstudio-suggest-btn" data-tstudio-suggest data-export-id="<?= $exportId ?>" <?= $isLocked ? 'disabled' : '' ?>><?= tstudio_h(t('Suggest caption & hashtags', 'Sugerir copy y hashtags')) ?></button>
                                    </div>
                                    <small class="tstudio-counter" data-tstudio-counter></small>
                                    <label class="tstudio-field">
                                        <span><?= tstudio_h(t('Tags', 'Tags')) ?></span>
                                        <input type="text" name="tags" placeholder="#art #tiktokart" value="<?= tstudio_h($tags) ?>" data-tstudio-tags <?= $isLocked ? 'disabled' : '' ?>>
                                    </label>
                                    <label class="tstudio-field">
                                        <span><?= tstudio_h(t('Cover frame (seconds in)', 'Portada (segundo del video)')) ?></span>
                                        <input type="number" name="coverSeconds" min="0" step="1" value="<?= $coverSeconds ?>" data-tstudio-cover <?= $isLocked ? 'disabled' : '' ?>>
                                    </label>
                                    <label class="tstudio-field">
                                        <span><?= tstudio_h(t('Destination (internal tracking only, e.g. Saatchi Art listing)', 'Destino (solo seguimiento interno, ej. listing de Saatchi Art)')) ?></span>
                                        <input type="url" name="destinationUrl" placeholder="https://www.saatchiart.com/art/..." value="<?= tstudio_h($destinationUrl) ?>" <?= $isLocked ? 'disabled' : '' ?>>
                                    </label>
                                    <div class="tstudio-when-row">
                                        <label><span><?= tstudio_h(t('Date', 'Fecha')) ?></span><input type="date" name="date" value="<?= tstudio_h($scheduledDate) ?>" <?= $isLocked ? 'disabled' : '' ?>></label>
                                        <label><span><?= tstudio_h(t('Time', 'Hora')) ?></span><input type="time" name="time" value="<?= tstudio_h($scheduledTime) ?>" <?= $isLocked ? 'disabled' : '' ?>></label>
                                    </div>
                                    <p class="tstudio-aigc-note"><?= tstudio_h(t('Labeled automatically as AI-generated content (TikTok requirement).', 'Se etiqueta automáticamente como contenido generado con IA (requisito de TikTok).')) ?></p>
                                    <?php if (!$isLocked): ?>
                                        <button type="submit"><?= $status === 'scheduled' ? tstudio_h(t('Save changes', 'Guardar cambios')) : tstudio_h(t('Schedule', 'Programar')) ?></button>
                                    <?php endif; ?>
                                    <small data-tstudio-schedule-error hidden></small>
                                </form>

                                <div class="tstudio-card-actions">
                                    <?php if ($status === 'scheduled' && $job): ?>
                                        <button type="button" class="tstudio-manage-btn" data-tstudio-manage data-job-id="<?= (int)$job['id'] ?>" data-action="cancel" data-confirmation="CANCELAR"><?= tstudio_h(t('Cancel', 'Cancelar')) ?></button>
                                        <button type="button" class="tstudio-manage-btn" data-tstudio-manage data-job-id="<?= (int)$job['id'] ?>" data-action="publish_now" data-confirmation="PUBLICAR_AHORA"><?= tstudio_h(t('Publish now', 'Publicar ahora')) ?></button>
                                    <?php elseif ($status === 'failed' && $job): ?>
                                        <button type="button" class="tstudio-manage-btn" data-tstudio-manage data-job-id="<?= (int)$job['id'] ?>" data-action="retry" data-confirmation="REINTENTAR"><?= tstudio_h(t('Retry', 'Reintentar')) ?></button>
                                    <?php elseif ($status === 'processing' || $status === 'queued'): ?>
                                        <form data-tstudio-status-form>
                                            <input type="hidden" name="csrf" value="<?= tstudio_h($csrf) ?>">
                                            <input type="hidden" name="exportId" value="<?= $exportId ?>">
                                            <button type="submit"><?= tstudio_h(t('Check status', 'Ver estado')) ?></button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if (!$isLocked): ?>
                                        <form data-tstudio-unassign-form>
                                            <input type="hidden" name="csrf" value="<?= tstudio_h($csrf) ?>">
                                            <input type="hidden" name="exportId" value="<?= $exportId ?>">
                                            <button type="submit" class="tstudio-manage-btn tstudio-manage-btn--quiet"><?= tstudio_h(t('Remove from board', 'Quitar del board')) ?></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
    </main>
</div>
<script src="tiktok_studio.js?v=3"></script>
</body>
</html>

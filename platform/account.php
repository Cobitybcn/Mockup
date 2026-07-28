<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
$isAdmin = Auth::isAdmin($user);
$planCode = FeatureAccess::planForUser($user);
$planLabel = $isAdmin ? 'Administrator' : FeatureAccess::planLabel($user);
$canUseWebsite = FeatureAccess::allows($user, FeatureAccess::WEBSITE_MANAGE);
$canUseSocial = FeatureAccess::allows($user, FeatureAccess::SOCIAL_MANAGE);
$canUseVideo = FeatureAccess::allows($user, FeatureAccess::VIDEO_MANAGE);
$upgradeRequested = isset($_GET['upgrade']) && !$isAdmin && $planCode !== FeatureAccess::PLAN_ARTIST_PRO;
$pdo = Database::connection();
$passwordSuccess = '';
$passwordError = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    $currentPassword = (string)($_POST['current_password'] ?? '');
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if ($newPassword !== $confirmPassword) {
        $passwordError = t('New passwords do not match.', 'Las contraseñas nuevas no coinciden.');
    } else {
        try {
            Auth::requireValidCsrf((string)($_POST['csrf'] ?? ''), 'account_password');
            Auth::changePassword($currentPassword, $newPassword);
            $passwordSuccess = t('Your password has been updated successfully.', 'Tu contraseña se actualizó correctamente.');
        } catch (RuntimeException $e) {
            $passwordError = $e->getMessage();
        } catch (Throwable $e) {
            $passwordError = t('We could not update your password. Please try again.', 'No pudimos actualizar tu contraseña. Volvé a intentarlo.');
        }
    }
}

$languageSuccess = '';
$languageError = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'save_language_policy') {
    try {
        Auth::requireValidCsrf((string)($_POST['csrf'] ?? ''), 'account_language');
        $submittedPublication = is_array($_POST['publication_locales'] ?? null)
            ? array_map('strval', $_POST['publication_locales'])
            : [];
        LanguagePolicy::save(
            (int)$user['id'],
            (string)($_POST['working_locale'] ?? ''),
            $submittedPublication
        );
        $languageSuccess = t('Your language settings have been saved.', 'Tu configuración de idiomas se guardó.');
    } catch (InvalidArgumentException | RuntimeException $e) {
        $languageError = $e->getMessage();
    } catch (Throwable $e) {
        $languageError = t('We could not save your language settings. Please try again.', 'No pudimos guardar tu configuración de idiomas. Volvé a intentarlo.');
    }
}

$languagePolicy = LanguagePolicy::forUser((int)$user['id']);
$languageAdaptationTargets = LanguagePolicy::adaptationTargets($languagePolicy);

// Punto #10: historial de transacciones de créditos
$txStmt = $pdo->prepare("
    SELECT amount, reason, created_at
    FROM credit_transactions
    WHERE user_id = :user_id
    ORDER BY id DESC
    LIMIT 10
");
$txStmt->execute(['user_id' => (int)$user['id']]);
$creditHistory = $txStmt->fetchAll();

$rootArtworkTotal = 0;
$mockupTotal = 0;
$variantRootTotal = 0;
$pendingArtworkTotal = 0;
try {
    $rootStmt = $pdo->prepare('SELECT COUNT(*) FROM artwork_groups WHERE status = "active" AND user_id = :user_id');
    $rootStmt->execute(['user_id' => (int)$user['id']]);
    $rootArtworkTotal = (int)$rootStmt->fetchColumn();

    $mockupStmt = $pdo->prepare('SELECT COUNT(*) FROM mockups WHERE user_id = :user_id');
    $mockupStmt->execute(['user_id' => (int)$user['id']]);
    $mockupTotal = (int)$mockupStmt->fetchColumn();

    $variantStmt = $pdo->prepare('SELECT COUNT(*) FROM artworks WHERE artwork_group_id IS NOT NULL AND root_view_status = "variant" AND user_id = :user_id');
    $variantStmt->execute(['user_id' => (int)$user['id']]);
    $variantRootTotal = (int)$variantStmt->fetchColumn();

    $pendingStmt = $pdo->prepare('SELECT COUNT(*) FROM artworks WHERE user_id = :user_id AND (status != "done" OR root_file IS NULL OR root_file = "")');
    $pendingStmt->execute(['user_id' => (int)$user['id']]);
    $pendingArtworkTotal = (int)$pendingStmt->fetchColumn();
} catch (Throwable $e) {
    // Keep account available even if a stats table is not ready in a local build.
}

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="<?= h(Translator::locale($user)) ?>">
<head>
    <meta charset="utf-8">
    <title><?= h(t('Account - Artwork Mockups', 'Cuenta - Artwork Mockups')) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <style>
        .account-page .stat-link {
            color: inherit;
            text-decoration: none;
            transition: border-color .2s ease, transform .2s ease, box-shadow .2s ease;
        }
        .account-page .stat-link:hover {
            border-color: var(--accent);
            transform: translateY(-1px);
        }
        .account-security-form {
            max-width: 760px;
        }
        .account-security-fields {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
        }
        .account-security-fields label {
            margin-top: 18px;
        }
        .account-security-form button {
            width: auto;
            min-width: 180px;
        }
        @media (max-width: 760px) {
            .account-page .main-area > .app-header,
            .account-page .main-area > .alert-strip {
                display: none;
            }
            .account-page .workspace {
                padding: 22px 14px 32px;
            }
            .account-page .workspace-header {
                align-items: flex-start;
                margin-bottom: 16px;
            }
            .account-page .workspace-header h1 {
                font-size: clamp(34px, 12vw, 48px);
                line-height: .92;
                margin-bottom: 0;
            }
            .account-page .workspace-header p {
                display: block;
                margin-top: 10px;
                color: var(--muted);
                font-size: 13px;
                line-height: 1.35;
                overflow-wrap: anywhere;
            }
            .account-page .topbar-actions {
                display: none;
            }
            .account-page .stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 10px;
                margin-bottom: 14px;
            }
            .account-page .stat-card {
                min-height: 92px;
                padding: 14px 12px;
                border-radius: 8px;
                background: #fffaf7;
            }
            .account-page .stat-card span {
                font-size: 10px;
                letter-spacing: .08em;
                line-height: 1.2;
            }
            .account-page .stat-card strong {
                font-size: 24px;
                line-height: 1;
            }
            .account-page .panel {
                padding: 16px;
                border-radius: 8px;
                margin-top: 12px;
            }
            .account-page .panel h2 {
                font-size: 13px;
                letter-spacing: .12em;
                text-transform: uppercase;
                margin-bottom: 8px;
            }
            .account-page .panel p {
                font-size: 13px;
                line-height: 1.45;
            }
            .account-page .panel:last-child {
                display: none;
            }
            .account-page table {
                display: block;
                width: 100%;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                white-space: nowrap;
            }
            .account-security-fields {
                grid-template-columns: 1fr;
                gap: 0;
            }
            .account-security-form button {
                width: 100%;
            }
        }

        .plan-heading {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 18px;
        }

        .plan-badge {
            display: inline-flex;
            padding: 7px 11px;
            border: 1px solid var(--line-dark);
            border-radius: 999px;
            background: var(--surface-soft);
            color: var(--accent);
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .08em;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .plan-feature-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            margin-top: 18px;
        }

        .plan-feature {
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 16px;
            background: var(--surface-soft);
        }

        .plan-feature.is-enabled {
            border-color: rgba(106, 139, 101, .34);
            background: rgba(228, 238, 225, .55);
        }

        .plan-feature span {
            display: block;
            margin-bottom: 6px;
            color: var(--muted);
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .07em;
            text-transform: uppercase;
        }

        .plan-feature strong {
            font-family: var(--font-serif);
            font-size: 20px;
            font-weight: 500;
        }

        .plan-details {
            margin-top: 16px;
            padding-top: 14px;
            border-top: 1px solid var(--line);
        }

        .plan-details summary {
            cursor: pointer;
            font-weight: 600;
        }

        @media (max-width: 760px) {
            .plan-heading { display: block; }
            .plan-badge { margin-top: 10px; }
            .plan-feature-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body class="account-page">
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="main-area">
        <header class="app-header">
            <a class="user-chip" href="account.php"><?= h($user['email']) ?></a>
        </header>

        <div class="alert-strip">
            <?= h(t('Real payments are currently disabled. The beta uses internal credits to prepare the commercial architecture.', 'Los pagos reales están desactivados por ahora. La beta usa créditos internos para preparar la arquitectura comercial.')) ?>
        </div>

        <div class="workspace">
            <div class="workspace-header">
                <div>
                    <h1><?= h(t('Account', 'Cuenta')) ?></h1>
                    <p><?= h($user['email']) ?></p>
                </div>
                <div class="topbar-actions">
                    <a class="button-link secondary" href="<?= h($canUseSocial ? 'connections.php' : 'account.php?upgrade=artist_pro&feature=social#plan') ?>"><?= h($canUseSocial ? t('Connections', 'Conexiones') : t('Artist Pro', 'Artist Pro')) ?></a>
                    <a class="button-link secondary" href="root_album.php"><?= h(t('ArtWorks', 'Obras')) ?></a>
                </div>
            </div>

            <section class="stats">
                <a class="stat-card stat-link" href="account.php#credits">
                    <span><?= h(t('Available Credits', 'Créditos disponibles')) ?></span>
                    <strong><?= h($user['credits']) ?></strong>
                </a>
                <a class="stat-card stat-link" href="root_album.php">
                    <span><?= h(t('ArtWorks', 'Obras')) ?></span>
                    <strong><?= h($rootArtworkTotal) ?></strong>
                </a>
                <a class="stat-card stat-link" href="mockups.php">
                    <span><?= h(t('Scene Mockups', 'Mockups de escena')) ?></span>
                    <strong><?= h($mockupTotal) ?></strong>
                </a>
                <a class="stat-card stat-link" href="root_album.php">
                    <span><?= h(t('Root Variants', 'Variantes de raíz')) ?></span>
                    <strong><?= h($variantRootTotal) ?></strong>
                </a>
                <?php if ($pendingArtworkTotal > 0): ?>
                    <a class="stat-card stat-link" href="root_album.php#pendientes">
                        <span><?= h(t('Pending', 'Pendientes')) ?></span>
                        <strong><?= h($pendingArtworkTotal) ?></strong>
                    </a>
                <?php endif; ?>
            </section>

            <section class="panel" id="plan">
                <div class="plan-heading">
                    <div>
                        <h2><?= h(t('Your plan', 'Tu plan')) ?></h2>
                        <p><?= h(t('Plan access and generation credits are managed separately.', 'El acceso del plan y los créditos de generación se gestionan por separado.')) ?></p>
                    </div>
                    <span class="plan-badge"><?= h($planLabel) ?></span>
                </div>

                <?php if ($upgradeRequested): ?>
                    <div class="notice" role="status"><?= h(t('Studio Notes, Store, Social Media Board and Video Lab are available with Artist Pro. Your existing artworks and mockups remain unchanged.', 'Studio Notes, Tienda, Social Media Board y Video Lab están disponibles con Artist Pro. Tus obras y mockups actuales no cambian.')) ?></div>
                <?php endif; ?>

                <div class="plan-feature-grid">
                    <div class="plan-feature <?= $canUseWebsite ? 'is-enabled' : '' ?>">
                        <span><?= h(t('Studio Notes & Store', 'Studio Notes y Tienda')) ?></span>
                        <strong><?= h($canUseWebsite ? t('Available', 'Disponible') : t('Artist Pro', 'Artist Pro')) ?></strong>
                    </div>
                    <div class="plan-feature <?= $canUseSocial ? 'is-enabled' : '' ?>">
                        <span><?= h(t('Social Media Board', 'Social Media Board')) ?></span>
                        <strong><?= h($canUseSocial ? t('Available', 'Disponible') : t('Artist Pro', 'Artist Pro')) ?></strong>
                    </div>
                    <div class="plan-feature <?= $canUseVideo ? 'is-enabled' : '' ?>">
                        <span><?= h(t('Video Lab', 'Video Lab')) ?></span>
                        <strong><?= h($canUseVideo ? t('Available', 'Disponible') : t('Artist Pro', 'Artist Pro')) ?></strong>
                    </div>
                </div>

                <?php if (!$isAdmin && $planCode === FeatureAccess::PLAN_ARTIST_STUDIO): ?>
                    <p style="margin-top:16px;"><a class="button-link" href="contact/"><?= h(t('Request Artist Pro access', 'Solicitar acceso a Artist Pro')) ?></a></p>
                <?php endif; ?>

                <details class="plan-details">
                    <summary><?= h(t('Plan details', 'Detalles del plan')) ?></summary>
                    <p><strong><?= h(t('Artist Studio:', 'Artist Studio:')) ?></strong> <?= h(t('artworks, series, mockup generation, Mockup Lab, private albums and downloads.', 'obras, series, generación de mockups, Mockup Lab, álbumes privados y descargas.')) ?></p>
                    <p><strong><?= h(t('Artist Pro:', 'Artist Pro:')) ?></strong> <?= h(t('everything in Artist Studio, plus Studio Notes, Store, Social Media Board and Video Lab.', 'todo lo de Artist Studio, más Studio Notes, Tienda, Social Media Board y Video Lab.')) ?></p>
                    <p><?= h(t('Every Mockup Lab generation or AI variation consumes credits. Browsing or downloading existing files does not.', 'Cada generación de Mockup Lab o variación con IA consume créditos. Ver o descargar archivos existentes no.')) ?></p>
                </details>
            </section>

            <section class="panel" id="languages">
                <h2><?= h(t('Languages', 'Idiomas')) ?></h2>
                <p><?= h(t('Choose the language you work in and the languages you want to publish in. English is optional: content is only adapted when you publish in a language other than your working one.', 'Elegí el idioma en el que trabajás y los idiomas en los que querés publicar. El inglés es opcional: el contenido solo se adapta cuando publicás en un idioma distinto del tuyo.')) ?></p>

                <?php if ($languageSuccess !== ''): ?>
                    <div class="notice" role="status"><?= h($languageSuccess) ?></div>
                <?php endif; ?>

                <?php if ($languageError !== ''): ?>
                    <div class="notice error" role="alert"><?= h($languageError) ?></div>
                <?php endif; ?>

                <form method="post">
                    <input type="hidden" name="action" value="save_language_policy">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars(Auth::csrfToken('account_language'), ENT_QUOTES, 'UTF-8') ?>">

                    <div style="margin-top:16px;">
                        <label for="working_locale"><?= h(t('Working language', 'Idioma de trabajo')) ?></label>
                        <p style="margin:4px 0 8px; font-size:13px; opacity:.75;"><?= h(t('The language you analyse, generate and review in. Your master content is written in it.', 'El idioma en el que analizás, generás y revisás. Tu contenido maestro se escribe en él.')) ?></p>
                        <select id="working_locale" name="working_locale">
                            <?php foreach (LanguagePolicy::supportedLocales() as $localeCode => $localeLabel): ?>
                                <option value="<?= h($localeCode) ?>" <?= $languagePolicy['working_locale'] === $localeCode ? 'selected' : '' ?>><?= h($localeLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <fieldset style="margin-top:20px; border:0; padding:0;">
                        <legend style="padding:0;"><?= h(t('Publication languages', 'Idiomas de publicación')) ?></legend>
                        <p style="margin:4px 0 8px; font-size:13px; opacity:.75;"><?= h(t('The languages your public site and channels are published in. Choose at least one.', 'Los idiomas en los que se publican tu sitio público y tus canales. Elegí al menos uno.')) ?></p>
                        <?php foreach (LanguagePolicy::supportedLocales() as $localeCode => $localeLabel): ?>
                            <label style="display:block; margin-bottom:6px; font-weight:400;">
                                <input
                                    type="checkbox"
                                    name="publication_locales[]"
                                    value="<?= h($localeCode) ?>"
                                    <?= in_array($localeCode, $languagePolicy['publication_locales'], true) ? 'checked' : '' ?>
                                >
                                <?= h($localeLabel) ?>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>

                    <p style="margin-top:16px; font-size:13px;">
                        <?php if ($languageAdaptationTargets === []): ?>
                            <?= h(t('Current setting: you publish only in your working language, so no adaptation is created.', 'Configuración actual: publicás solo en tu idioma de trabajo, así que no se crea ninguna adaptación.')) ?>
                        <?php else: ?>
                            <?php printf(
                                t(
                                    'Current setting: your master content is written in <strong>%s</strong> and adapted into <strong>%s</strong>.',
                                    'Configuración actual: tu contenido maestro está escrito en <strong>%s</strong> y adaptado a <strong>%s</strong>.'
                                ),
                                h(LanguagePolicy::localeLabel($languagePolicy['working_locale'])),
                                h(implode(', ', array_map([LanguagePolicy::class, 'localeLabel'], $languageAdaptationTargets)))
                            ); ?>
                        <?php endif; ?>
                    </p>

                    <?php if (!$languagePolicy['is_explicit']): ?>
                        <div class="notice" role="status"><?= h(t('These are the settings the studio has used so far. Save them to make your choice explicit.', 'Esta es la configuración que el estudio usó hasta ahora. Guardala para hacer tu elección explícita.')) ?></div>
                    <?php endif; ?>

                    <button type="submit"><?= h(t('Save languages', 'Guardar idiomas')) ?></button>
                </form>

                <details class="plan-details" style="margin-top:16px;">
                    <summary><?= h(t('When does this take effect?', '¿Cuándo tiene efecto esto?')) ?></summary>
                    <p><?= h(t('The editorial engine already follows this setting for new content. Existing content you already created is not reinterpreted or changed.', 'El motor editorial ya sigue este ajuste para el contenido nuevo. El contenido que ya creaste no se reinterpreta ni cambia.')) ?></p>
                </details>
            </section>

            <section class="panel" id="security">
                <h2><?= h(t('Change password', 'Cambiar contraseña')) ?></h2>
                <p><?= h(t('Enter your current password, then choose a new password with at least 8 characters.', 'Ingresá tu contraseña actual y elegí una nueva de al menos 8 caracteres.')) ?></p>

                <?php if ($passwordSuccess !== ''): ?>
                    <div class="notice" role="status"><?= h($passwordSuccess) ?></div>
                <?php endif; ?>

                <?php if ($passwordError !== ''): ?>
                    <div class="notice error" role="alert"><?= h($passwordError) ?></div>
                <?php endif; ?>

                <form method="post" class="account-security-form">
                    <input type="hidden" name="action" value="change_password">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars(Auth::csrfToken('account_password'), ENT_QUOTES, 'UTF-8') ?>">
                    <div class="account-security-fields">
                        <div>
                            <label for="current_password"><?= h(t('Current password', 'Contraseña actual')) ?></label>
                            <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
                        </div>
                        <div>
                            <label for="new_password"><?= h(t('New password', 'Nueva contraseña')) ?></label>
                            <input type="password" id="new_password" name="new_password" required minlength="8" autocomplete="new-password">
                        </div>
                        <div>
                            <label for="confirm_password"><?= h(t('Confirm new password', 'Confirmar nueva contraseña')) ?></label>
                            <input type="password" id="confirm_password" name="confirm_password" required minlength="8" autocomplete="new-password">
                        </div>
                    </div>
                    <button type="submit"><?= h(t('Change password', 'Cambiar contraseña')) ?></button>
                </form>
            </section>

            <section class="panel" id="credits">
                <h2><?= h(t('Beta Credits', 'Créditos de la beta')) ?></h2>
                <p><?= h(t('Each mockup generation and every Mockup Lab AI variation uses credits. Root image creation (Step 1) is free in Beta. Credits are refunded automatically if a generation fails.', 'Cada generación de mockup y cada variación con IA de Mockup Lab usa créditos. Crear la imagen raíz (Paso 1) es gratis durante la beta. Los créditos se reintegran automáticamente si una generación falla.')) ?></p>

                <?php if ($creditHistory): ?>
                    <table style="width: 100%; border-collapse: collapse; margin-top: 16px; font-size: 13px;">
                        <thead>
                            <tr style="border-bottom: 1px solid var(--line); text-align: left;">
                                <th style="padding: 8px 12px; color: var(--muted); font-weight: 600;"><?= h(t('Date', 'Fecha')) ?></th>
                                <th style="padding: 8px 12px; color: var(--muted); font-weight: 600;"><?= h(t('Amount', 'Monto')) ?></th>
                                <th style="padding: 8px 12px; color: var(--muted); font-weight: 600;"><?= h(t('Reason', 'Motivo')) ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($creditHistory as $tx): ?>
                                <tr style="border-bottom: 1px solid var(--line);">
                                    <td style="padding: 8px 12px; color: var(--muted);"><?= h(substr((string)$tx['created_at'], 0, 16)) ?></td>
                                    <td style="padding: 8px 12px; font-weight: 600; color: <?= (int)$tx['amount'] > 0 ? 'var(--accent)' : 'var(--danger, #c0392b)' ?>;">
                                        <?= (int)$tx['amount'] > 0 ? '+' . h($tx['amount']) : h($tx['amount']) ?>
                                    </td>
                                    <td style="padding: 8px 12px; color: var(--ink);"><?= h($tx['reason']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p style="color: var(--muted); margin-top: 12px;"><?= h(t('No credit transactions yet.', 'Todavía no hay movimientos de créditos.')) ?></p>
                <?php endif; ?>
            </section>

            <section class="panel">
                <h2><?= h(t('Payments', 'Pagos')) ?></h2>
                <p><?= h(t('Payment integration is pending. The beta operates with internal credits prior to connecting live transactions.', 'La integración de pagos está pendiente. La beta funciona con créditos internos hasta conectar transacciones reales.')) ?></p>
            </section>
        </div>
    </main>
</div>
</body>
</html>

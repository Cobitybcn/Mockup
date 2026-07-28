<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
$saved = false;
$domainSaved = false;
$domainVerified = false;
$error = '';
$pdo = Database::connection();
$domainService = new ArtistDomainService($pdo);
$_SESSION['artist_profile_csrf'] ??= bin2hex(random_bytes(32));
$csrf = (string)$_SESSION['artist_profile_csrf'];

function artist_profile_photo_dir(): string
{
    return __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'artist_profiles';
}

function artist_profile_photo_url(string $file): string
{
    $file = basename($file);
    return $file !== '' ? 'profile_media.php?file=' . rawurlencode($file) : '';
}

function handle_artist_photo_upload(int $userId, string $existingFile): string
{
    if (!isset($_FILES['artist_photo']) || !is_array($_FILES['artist_photo'])) {
        return $existingFile;
    }

    $file = $_FILES['artist_photo'];
    $errorCode = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($errorCode === UPLOAD_ERR_NO_FILE) {
        return $existingFile;
    }
    if ($errorCode !== UPLOAD_ERR_OK) {
        throw new RuntimeException(t('Artist photo upload failed.', 'Falló la subida de la foto del artista.'));
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException(t('Artist photo upload is invalid.', 'La subida de la foto del artista no es válida.'));
    }

    $info = @getimagesize($tmp);
    if (!is_array($info) || empty($info['mime'])) {
        throw new RuntimeException(t('Artist photo must be a valid image.', 'La foto del artista debe ser una imagen válida.'));
    }

    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    $mime = (string)$info['mime'];
    if (!isset($extensions[$mime])) {
        throw new RuntimeException(t('Artist photo must be JPG, PNG, or WEBP.', 'La foto del artista debe ser JPG, PNG o WEBP.'));
    }

    $dir = artist_profile_photo_dir();
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        throw new RuntimeException(t('Could not create artist photo directory.', 'No se pudo crear el directorio de fotos del artista.'));
    }

    $name = 'artist-' . $userId . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $extensions[$mime];
    $target = $dir . DIRECTORY_SEPARATOR . $name;

    if (!move_uploaded_file($tmp, $target)) {
        throw new RuntimeException(t('Could not save artist photo.', 'No se pudo guardar la foto del artista.'));
    }
    if (StorageService::isGcsActive()
        && !StorageService::uploadFile('uploads/artist_profiles/' . $name, $target)) {
        @unlink($target);
        throw new RuntimeException(t('Could not persist artist photo.', 'No se pudo persistir la foto del artista.'));
    }

    return $name;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $postedToken = (string)($_POST['csrf'] ?? '');
        if ($postedToken === '' || !hash_equals($csrf, $postedToken)) {
            throw new RuntimeException(t('The form expired. Reload the page and try again.', 'El formulario expiró. Recargá la página e intentá de nuevo.'));
        }
        $action = (string)($_POST['action'] ?? 'save_profile');
        $currentProfile = ArtistProfile::findForUser((int)$user['id']);
        if ($action === 'save_domain') {
            $domainService->saveConfiguration(
                (int)$user['id'],
                (string)($_POST['subdomain'] ?? ''),
                (string)($_POST['custom_domain'] ?? '')
            );
            $domainSaved = true;
        } elseif ($action === 'verify_domain') {
            $result = $domainService->verifyOwnership((int)$user['id']);
            $domainVerified = !empty($result['verified_now']);
            if (!$domainVerified) $error = (string)$result['last_error'];
        } elseif ($action === 'save_profile') {
            $input = $_POST;
            $input['subdomain'] = (string)($currentProfile['subdomain'] ?? '');
            $input['custom_domain'] = (string)($currentProfile['custom_domain'] ?? '');
            $input['photo_file'] = handle_artist_photo_upload((int)$user['id'], basename((string)($currentProfile['photo_file'] ?? '')));
            ArtistProfile::saveForUser((int)$user['id'], $input);
            $saved = true;
        } else {
            throw new RuntimeException(t('Unknown Artist Profile action.', 'Acción de Perfil de Artista desconocida.'));
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$profile = ArtistProfile::findForUser((int)$user['id']);
$domain = $domainService->configuration((int)$user['id']);
$isAdmin = Auth::isAdmin($user);
$canUseSocial = FeatureAccess::allows($user, FeatureAccess::SOCIAL_MANAGE);

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function field_value(array $profile, string $field): string
{
    return h($profile[$field] ?? '');
}

function artist_profile_admin_vars(string $field): array
{
    $directPlaceholders = [
        'statement' => '{artist_statement}',
        'visual_language' => '{visual_language}',
        'recurring_themes' => '{recurring_symbols}',
        'palette_notes' => '{preferred_atmospheres}',
    ];

    return [
        'prompt_variable' => $directPlaceholders[$field] ?? '',
        'included_in' => '{artist_profile_prompt}',
    ];
}

function admin_vars_hint(bool $isAdmin, string $field): void
{
    if (!$isAdmin) {
        return;
    }

    $vars = artist_profile_admin_vars($field);

    echo '<small class="admin-vars">';
    if ($vars['prompt_variable'] !== '') {
        echo h(t('Prompt variable:', 'Variable de prompt:')) . ' ' . h($vars['prompt_variable']) . '<br>';
    }
    echo h(t('Included in:', 'Incluido en:')) . ' ' . h($vars['included_in']);
    echo '</small>';
}
?>
<!doctype html>
<html lang="<?= h(Translator::locale($user)) ?>">
<head>
    <meta charset="utf-8">
    <title><?= h(t('Artist Profile - Artwork Mockups', 'Perfil del Artista - Artwork Mockups')) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;0,700;1,400;1,500&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <style>
        .profile-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 30px;
            align-items: start;
        }
        .profile-card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 30px;
            display: flex;
            flex-direction: column;
            gap: 20px;
            transition: all 0.3s ease;
        }
        .profile-card:hover {
            box-shadow: var(--shadow-hover);
            border-color: var(--accent);
        }
        .profile-card h3 {
            font-family: var(--font-serif);
            font-size: 22px;
            color: var(--accent);
            border-bottom: 1px solid var(--line);
            padding-bottom: 12px;
            margin: 0;
        }
        .artist-profile-header {
            background: rgba(183, 127, 134, 0.16);
            border: 1px solid rgba(183, 127, 134, 0.28);
            border-radius: var(--radius);
            padding: 24px 26px;
        }
        .artist-profile-header .topbar-actions { align-items:flex-start; }
        .profile-connections-decision-block {
            display:inline-flex;
            flex:0 0 140px;
            width:140px;
            min-width:140px;
            height:140px;
            min-height:140px;
            align-items:center;
            justify-content:center;
            box-sizing:border-box;
            margin:0;
            padding:18px;
            border:1px solid #94a88f;
            border-radius:4px;
            background:#9fb198;
            color:#fffdf8;
            box-shadow:0 8px 18px rgba(68,83,63,.12);
            font-size:11px;
            font-weight:800;
            letter-spacing:.09em;
            line-height:1.35;
            text-align:center;
            text-decoration:none;
            text-transform:uppercase;
        }
        .profile-connections-decision-block:hover,
        .profile-connections-decision-block:focus-visible { border-color:#81987b; background:#8fa487; color:#fff; transform:translateY(-1px); }
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .form-group label {
            margin: 0;
            font-size: 11px;
            text-transform: uppercase;
            font-weight: 600;
            color: var(--ink);
            letter-spacing: 0.05em;
        }
        .form-group textarea,
        .form-group input {
            font-size: 13px;
            line-height: 1.4;
            padding: 10px 12px;
            border-radius: 4px;
        }
        .form-group textarea {
            resize: vertical;
        }
        .form-group small {
            margin: 2px 0 0 0;
            font-size: 11px;
            color: var(--muted);
            line-height: 1.3;
        }
        .form-group small.admin-vars {
            color: var(--accent);
            font-family: ui-monospace, SFMono-Regular, Consolas, "Liberation Mono", monospace;
            font-size: 10px;
            line-height: 1.45;
            word-break: break-word;
        }
        .submit-container {
            margin-top: 30px;
            display: flex;
            justify-content: flex-end;
        }
        .submit-container button {
            width: auto;
            min-width: 200px;
            margin-top: 0;
            font-size: 12px;
            padding: 14px 28px;
        }
        .artist-photo-box {
            display: grid;
            grid-template-columns: 86px minmax(0, 1fr);
            gap: 14px;
            align-items: center;
            padding: 12px;
            border: 1px solid var(--line);
            background: var(--surface-soft);
            border-radius: var(--radius);
        }
        .artist-photo-preview {
            width: 86px;
            height: 86px;
            border-radius: 999px;
            overflow: hidden;
            border: 1px solid var(--line);
            background: var(--surface);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--muted);
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: .05em;
            text-align: center;
        }
        .artist-photo-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .domain-workspace {
            margin: 0 0 24px;
            border: 1px solid var(--line);
            background: var(--surface);
        }
        .domain-workspace > summary {
            min-height: 76px;
            padding: 17px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            cursor: pointer;
            list-style: none;
        }
        .domain-workspace > summary::-webkit-details-marker { display: none; }
        .domain-summary-copy {
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .domain-summary-copy span {
            color: var(--muted);
            font-size: 11px;
            font-weight: 600;
            letter-spacing: .08em;
            text-transform: uppercase;
        }
        .domain-summary-copy strong {
            overflow: hidden;
            color: var(--ink);
            font: 500 21px/1.15 var(--font-serif);
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .domain-status {
            flex: 0 0 auto;
            border: 1px solid var(--line-dark);
            padding: 6px 10px;
            color: var(--muted);
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
        }
        .domain-status--verified {
            border-color: rgba(93, 122, 86, .32);
            background: rgba(188, 207, 181, .32);
            color: #42533f;
        }
        .domain-workspace-body {
            border-top: 1px solid var(--line);
            padding: 22px 20px 24px;
        }
        .domain-address-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) auto;
            gap: 16px;
            align-items: end;
        }
        .domain-address-grid .form-group { min-width: 0; }
        .domain-address-grid button,
        .domain-verification button {
            width: auto;
            min-height: 44px;
            margin: 0;
            border-color: #aebca8;
            background: #b8c6b2;
            color: #253023;
            box-shadow: none;
        }
        .domain-address-grid button:hover,
        .domain-verification button:hover {
            border-color: #98aa91;
            background: #a9bba3;
            box-shadow: none;
        }
        .domain-verification {
            margin-top: 22px;
            padding-top: 20px;
            border-top: 1px solid var(--line);
        }
        .domain-verification h3 {
            margin: 0 0 4px;
            font: 500 24px/1.1 var(--font-serif);
        }
        .domain-verification > p {
            max-width: 760px;
            margin: 0 0 16px;
            color: var(--muted);
            font-size: 13px;
        }
        .dns-records {
            display: grid;
            grid-template-columns: 90px minmax(220px, .8fr) minmax(320px, 1.4fr);
            border: 1px solid var(--line);
            background: var(--surface-soft);
        }
        .dns-records > div {
            min-width: 0;
            padding: 12px 14px;
            border-right: 1px solid var(--line);
        }
        .dns-records > div:last-child { border-right: 0; }
        .dns-records span {
            display: block;
            margin-bottom: 4px;
            color: var(--muted);
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
        }
        .dns-records code {
            display: block;
            overflow-wrap: anywhere;
            color: var(--ink);
            font: 12px/1.5 ui-monospace, SFMono-Regular, Consolas, monospace;
        }
        .domain-verification form {
            margin-top: 14px;
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .domain-routing-note {
            margin-top: 14px !important;
            margin-bottom: 0 !important;
        }
        @media (max-width: 760px) {
            .app-header,
            .alert-strip {
                display: none;
            }
            .workspace {
                padding-left: 10px;
                padding-right: 10px;
            }
            .artist-profile-header {
                display: block;
                margin-bottom: 14px;
                padding: 0 0 14px;
                border: 0;
                border-bottom: 1px solid var(--line);
                border-radius: 0;
                background: transparent;
            }
            .artist-profile-header h1 {
                margin-bottom: 8px;
                font-size: 31px;
                line-height: 1.04;
            }
            .artist-profile-header p {
                margin: 0;
                font-size: 12px;
                line-height: 1.45;
            }
            .artist-profile-header .topbar-actions {
                display: flex;
                justify-content: flex-end;
                margin-top: 16px;
            }
            .profile-connections-decision-block { flex-basis:112px; width:112px; min-width:112px; height:112px; min-height:112px; padding:14px; }
            .profile-grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }
            .profile-card {
                padding: 14px 10px;
                border-left: 0;
                border-right: 0;
                border-radius: 0;
                box-shadow: none;
                gap: 14px;
            }
            .profile-card:hover {
                box-shadow: none;
                border-color: var(--line);
            }
            .profile-card h3 {
                padding-bottom: 8px;
                font-size: 19px;
            }
            .domain-workspace { margin-bottom: 14px; }
            .domain-workspace > summary { min-height: 66px; padding: 13px 10px; }
            .domain-summary-copy strong { font-size: 19px; }
            .domain-workspace-body { padding: 16px 10px 18px; }
            .domain-address-grid { grid-template-columns: 1fr; gap: 12px; }
            .domain-address-grid button,
            .domain-verification button { width: 100%; min-height: 48px; }
            .dns-records { grid-template-columns: 1fr; }
            .dns-records > div { border-right: 0; border-bottom: 1px solid var(--line); }
            .dns-records > div:last-child { border-bottom: 0; }
            .domain-verification form { display: block; }
            .artist-photo-box {
                grid-template-columns: 70px minmax(0, 1fr);
                gap: 10px;
                padding: 10px;
                border-radius: 6px;
            }
            .artist-photo-preview {
                width: 70px;
                height: 70px;
            }
            .form-group {
                gap: 5px;
            }
            .form-group label {
                font-size: 10px;
            }
            .form-group input,
            .form-group textarea {
                width: 100%;
                min-height: 46px;
                box-sizing: border-box;
                font-size: 14px;
            }
            .form-group textarea {
                min-height: 104px;
            }
            .form-group small {
                display: none;
            }
            .form-group small.admin-vars {
                display: block;
            }
            .submit-container {
                margin-top: 14px;
                position: sticky;
                bottom: 0;
                z-index: 30;
                padding: 10px 0 2px;
                background: linear-gradient(180deg, rgba(250, 249, 246, 0), var(--bg) 28%);
            }
            .submit-container button {
                width: 100%;
                min-width: 0;
                min-height: 52px;
                background: #b77f86;
                border-color: #b77f86;
            }
        }
    </style>
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="main-area">
        <header class="app-header">
            <a class="user-chip" href="account.php"><?= h($user['email']) ?></a>
        </header>

        <div class="alert-strip">
            <?= h(t('Artist Context & AI Guidance: These details act as semantic context for the AI, refining visual recommendations and descriptions.', 'Contexto del Artista y Guía de IA: estos detalles actúan como contexto semántico para la IA, refinando recomendaciones visuales y descripciones.')) ?>
        </div>

        <div class="workspace">
            <div class="workspace-header artist-profile-header">
                <div>
                    <h1><?= h(t('Artist Profile', 'Perfil del Artista')) ?></h1>
                    <p><?= h(t('Configure the artist context that shapes analysis, descriptions, and mockup guidance.', 'Configurá el contexto del artista que da forma al análisis, las descripciones y la guía de mockups.')) ?></p>
                </div>
                <div class="topbar-actions">
                    <?php if ($canUseSocial): ?>
                        <a class="profile-connections-decision-block" href="connections.php"><?= h(t('Connections', 'Conexiones')) ?></a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($saved): ?>
                <div class="notice"><?= h(t('Profile saved successfully.', 'Perfil guardado correctamente.')) ?></div>
            <?php endif; ?>

            <?php if ($domainSaved): ?>
                <div class="notice"><?= h(t('Website address saved. Verify the custom domain after adding its DNS record.', 'Dirección del sitio web guardada. Verificá el dominio personalizado después de agregar su registro DNS.')) ?></div>
            <?php endif; ?>

            <?php if ($domainVerified): ?>
                <div class="notice"><?= h(t('Domain ownership verified. The artist website can now recognize this host.', 'Propiedad del dominio verificada. El sitio web del artista ahora puede reconocer este host.')) ?></div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="notice error"><?= h($error) ?></div>
            <?php endif; ?>

            <?php
            $domainStatus = (string)$domain['status'];
            $domainSummary = (string)$domain['public_host'];
            if ($domainSummary === '') $domainSummary = t('Choose your website address', 'Elegí la dirección de tu sitio web');
            ?>
            <details class="domain-workspace" <?= (string)$domain['custom_domain'] !== '' && $domainStatus !== 'verified' ? 'open' : '' ?>>
                <summary>
                    <span class="domain-summary-copy"><span><?= h(t('Website address', 'Dirección del sitio web')) ?></span><strong><?= h($domainSummary) ?></strong></span>
                    <span class="domain-status <?= $domainStatus === 'verified' ? 'domain-status--verified' : '' ?>"><?= h($domainStatus === 'verified' ? t('Verified', 'Verificado') : ($domainStatus === 'pending' ? t('DNS pending', 'DNS pendiente') : t('Optional', 'Opcional'))) ?></span>
                </summary>
                <div class="domain-workspace-body">
                    <form method="post" class="domain-address-grid">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="save_domain">
                        <div class="form-group">
                            <label><?= h(t('Artwork Mockups subdomain', 'Subdominio de Artwork Mockups')) ?></label>
                            <input type="text" name="subdomain" value="<?= h((string)$domain['subdomain']) ?>" placeholder="artist-name" pattern="^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$" title="<?= h(t('Lowercase letters, numbers, and internal hyphens.', 'Minúsculas, números y guiones internos.')) ?>">
                            <small><?= h((string)$domain['subdomain']) !== '' ? h((string)$domain['subdomain']) . '.artworkmockups.com' : h(t('Included with every artist website.', 'Incluido con cada sitio web de artista.')) ?></small>
                        </div>
                        <div class="form-group">
                            <label><?= h(t('Own domain', 'Dominio propio')) ?></label>
                            <input type="text" name="custom_domain" value="<?= h((string)$domain['custom_domain']) ?>" placeholder="artist.com" inputmode="url">
                            <small><?= h(t('Use a domain you own. Do not include a page path.', 'Usá un dominio que te pertenezca. No incluyas una ruta de página.')) ?></small>
                        </div>
                        <button type="submit"><?= h(t('Save address', 'Guardar dirección')) ?></button>
                    </form>

                    <?php if ((string)$domain['custom_domain'] !== ''): ?>
                        <div class="domain-verification">
                            <h3><?= $domainStatus === 'verified' ? h(t('Ownership verified', 'Propiedad verificada')) : h(t('Verify ownership', 'Verificar propiedad')) ?></h3>
                            <p><?= $domainStatus === 'verified' ? h(t('Artwork Mockups will accept this verified host for your public artist website.', 'Artwork Mockups aceptará este host verificado para tu sitio web público de artista.')) : h(t('Add this TXT record where you manage the domain, then return here and verify it.', 'Agregá este registro TXT donde administrás el dominio y después volvé acá para verificarlo.')) ?></p>
                            <div class="dns-records">
                                <div><span><?= h(t('Type', 'Tipo')) ?></span><code>TXT</code></div>
                                <div><span><?= h(t('Name', 'Nombre')) ?></span><code><?= h((string)$domain['verification_record']) ?></code></div>
                                <div><span><?= h(t('Value', 'Valor')) ?></span><code><?= h((string)$domain['verification_value']) ?></code></div>
                            </div>
                            <?php if ($domainStatus !== 'verified'): ?>
                                <form method="post">
                                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                    <input type="hidden" name="action" value="verify_domain">
                                    <button type="submit"><?= h(t('Verify DNS', 'Verificar DNS')) ?></button>
                                </form>
                            <?php endif; ?>
                            <p class="domain-routing-note"><?= h(t('Website traffic target:', 'Destino del tráfico del sitio web:')) ?> <strong><?= h((string)$domain['routing_target']) ?></strong>. <?= h(t('Ownership verification does not alter your existing DNS automatically.', 'La verificación de propiedad no altera automáticamente tu DNS existente.')) ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </details>

            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="save_profile">
                <div class="profile-grid">
                    <!-- Column 1: Artistic Identity -->
                    <div class="profile-card">
                        <h3><?= h(t('Artistic Identity', 'Identidad Artística')) ?></h3>

                        <div class="artist-photo-box">
                            <div class="artist-photo-preview">
                                <?php $artistPhotoUrl = artist_profile_photo_url((string)($profile['photo_file'] ?? '')); ?>
                                <?php if ($artistPhotoUrl !== ''): ?>
                                    <img src="<?= h($artistPhotoUrl) ?>" alt="<?= h(t('Artist photo', 'Foto del artista')) ?>">
                                <?php else: ?>
                                    <?= h(t('No photo', 'Sin foto')) ?>
                                <?php endif; ?>
                            </div>
                            <div class="form-group">
                                <label><?= h(t('Artist Photo', 'Foto del Artista')) ?></label>
                                <input type="file" name="artist_photo" accept="image/jpeg,image/png,image/webp">
                                <input type="hidden" name="photo_file" value="<?= field_value($profile, 'photo_file') ?>">
                                <small><?= h(t('JPG, PNG, or WEBP portrait image.', 'Imagen retrato JPG, PNG o WEBP.')) ?></small>
                            </div>
                        </div>

                        <div class="form-group">
                            <label><?= h(t('Artistic Name', 'Nombre Artístico')) ?></label>
                            <input type="text" name="artist_name" value="<?= field_value($profile, 'artist_name') ?>" placeholder="<?= h(t('e.g. Elena Rostova', 'ej. Elena Rostova')) ?>">
                            <?php admin_vars_hint($isAdmin, 'artist_name'); ?>
                        </div>

                        <div class="form-group">
                            <label><?= h(t('Short Artist Bio', 'Biografía Breve del Artista')) ?></label>
                            <textarea name="short_bio" rows="3" placeholder="<?= h(t('Brief biography focusing on career, studies and background...', 'Biografía breve centrada en carrera, estudios y trayectoria...')) ?>"><?= field_value($profile, 'short_bio') ?></textarea>
                            <?php admin_vars_hint($isAdmin, 'short_bio'); ?>
                        </div>

                        <div class="form-group">
                            <label><?= h(t('Artistic Statement', 'Declaración Artística')) ?></label>
                            <textarea name="statement" rows="4" placeholder="<?= h(t('Conceptual statement, intention, search or constant themes in your work...', 'Declaración conceptual, intención, búsqueda o temas constantes en tu obra...')) ?>"><?= field_value($profile, 'statement') ?></textarea>
                            <?php admin_vars_hint($isAdmin, 'statement'); ?>
                        </div>

                        <div class="form-group">
                            <label><?= h(t('Visual Language', 'Lenguaje Visual')) ?></label>
                            <textarea name="visual_language" rows="3" placeholder="<?= h(t('e.g. Abstract expressionism, geometric structure, textures, organic lines...', 'ej. Expresionismo abstracto, estructura geométrica, texturas, líneas orgánicas...')) ?>"><?= field_value($profile, 'visual_language') ?></textarea>
                            <?php admin_vars_hint($isAdmin, 'visual_language'); ?>
                        </div>

                        <div class="form-group">
                            <label><?= h(t('Recurring Symbols / Motifs', 'Símbolos / Motivos Recurrentes')) ?></label>
                            <textarea name="recurring_themes" rows="3" placeholder="<?= h(t('e.g. Grids, thresholds, minerals, shadow play, anatomical forms...', 'ej. Grillas, umbrales, minerales, juego de sombras, formas anatómicas...')) ?>"><?= field_value($profile, 'recurring_themes') ?></textarea>
                            <?php admin_vars_hint($isAdmin, 'recurring_themes'); ?>
                        </div>

                        <div class="form-group">
                            <label><?= h(t('Materials & Process', 'Materiales y Proceso')) ?></label>
                            <textarea name="materials" rows="3" placeholder="<?= h(t('e.g. Layered acrylic, spatula incisions, wood panels, pigments...', 'ej. Acrílico en capas, incisiones de espátula, paneles de madera, pigmentos...')) ?>"><?= field_value($profile, 'materials') ?></textarea>
                            <?php admin_vars_hint($isAdmin, 'materials'); ?>
                        </div>
                    </div>

                    <!-- Column 2: Mockups & Atmospheres -->
                    <div class="profile-card">
                        <h3><?= h(t('Atmospheres & Curation', 'Atmósferas y Curaduría')) ?></h3>

                        <div class="form-group">
                            <label><?= h(t('Preferred Atmospheres', 'Atmósferas Preferidas')) ?></label>
                            <textarea name="palette_notes" rows="4" placeholder="<?= h(t('e.g. Nocturnal, mineral, warm, tense, serene, intimate, luminous, restrained...', 'ej. Nocturna, mineral, cálida, tensa, serena, íntima, luminosa, contenida...')) ?>"><?= field_value($profile, 'palette_notes') ?></textarea>
                            <small><?= h(t('Shorthand description of lighting and color temp preferences.', 'Descripción breve de preferencias de iluminación y temperatura de color.')) ?></small>
                            <?php admin_vars_hint($isAdmin, 'palette_notes'); ?>
                        </div>

                        <div class="form-group">
                            <label><?= h(t('Preferred Mockup Styles', 'Estilos de Mockup Preferidos')) ?></label>
                            <textarea name="preferred_contexts" rows="4" placeholder="<?= h(t('e.g. Modernist galleries, architectural concrete rooms, townhouses, clean brick walls...', 'ej. Galerías modernistas, salas de concreto arquitectónico, casas urbanas, paredes de ladrillo limpias...')) ?>"><?= field_value($profile, 'preferred_contexts') ?></textarea>
                            <small><?= h(t('Styles or spaces that best showcase your style.', 'Estilos o espacios que mejor muestran tu estilo.')) ?></small>
                            <?php admin_vars_hint($isAdmin, 'preferred_contexts'); ?>
                        </div>

                        <div class="form-group">
                            <label><?= h(t('Excluded Mockup Contexts', 'Contextos de Mockup Excluidos')) ?></label>
                            <textarea name="forbidden_contexts" rows="4" placeholder="<?= h(t('e.g. Commercial kitchens, kids bedrooms, generic office spaces...', 'ej. Cocinas comerciales, dormitorios infantiles, oficinas genéricas...')) ?>"><?= field_value($profile, 'forbidden_contexts') ?></textarea>
                            <small><?= h(t('Environments the AI must avoid when creating mockups.', 'Ambientes que la IA debe evitar al crear mockups.')) ?></small>
                            <?php admin_vars_hint($isAdmin, 'forbidden_contexts'); ?>
                        </div>

                        <div class="form-group">
                            <label><?= h(t('Forbidden Language / Exclusions', 'Lenguaje Prohibido / Exclusiones')) ?></label>
                            <textarea name="commercial_positioning" rows="5" placeholder="<?= h(t('List words or tones to avoid in curatorial texts (e.g. do not use marketing jargon, avoid academic over-complexity)...', 'Enumerá palabras o tonos a evitar en textos curatoriales (ej. no usar jerga de marketing, evitar la sobrecomplejidad académica)...')) ?>"><?= field_value($profile, 'commercial_positioning') ?></textarea>
                            <small><?= h(t('Words or phrases to exclude from AI copy generation.', 'Palabras o frases a excluir de la generación de textos por IA.')) ?></small>
                            <?php admin_vars_hint($isAdmin, 'commercial_positioning'); ?>
                        </div>
                    </div>

                    <!-- Column 3: Audience & Voice -->
                    <div class="profile-card">
                        <h3><?= h(t('Audience & Voice', 'Audiencia y Voz')) ?></h3>

                        <div class="form-group">
                            <label><?= h(t('Tone of Voice', 'Tono de Voz')) ?></label>
                            <textarea name="tone_of_voice" rows="3" placeholder="<?= h(t('e.g. Poetic, minimalist, elegant, conversational, technical, collectors-focused...', 'ej. Poético, minimalista, elegante, conversacional, técnico, orientado a coleccionistas...')) ?>"><?= field_value($profile, 'tone_of_voice') ?></textarea>
                            <small><?= h(t('Guides the writing style of artwork descriptions.', 'Guía el estilo de escritura de las descripciones de las obras.')) ?></small>
                            <?php admin_vars_hint($isAdmin, 'tone_of_voice'); ?>
                        </div>

                        <div class="form-group">
                            <label><?= h(t('Target Audience / Presentation Context', 'Audiencia Objetivo / Contexto de Presentación')) ?></label>
                            <textarea name="target_audience" rows="3" placeholder="<?= h(t('e.g. Collectors, curators, architects, quiet interiors, institutional spaces...', 'ej. Coleccionistas, curadores, arquitectos, interiores silenciosos, espacios institucionales...')) ?>"><?= field_value($profile, 'target_audience') ?></textarea>
                            <small><?= h(t('Defines who the work should speak to and where it should feel at home.', 'Define a quién debe hablarle la obra y dónde debería sentirse como en casa.')) ?></small>
                            <?php admin_vars_hint($isAdmin, 'target_audience'); ?>
                        </div>

                        <div class="form-group">
                            <label><?= h(t('Conceptual Keywords', 'Palabras Clave Conceptuales')) ?></label>
                            <textarea name="conceptual_keywords" rows="3" placeholder="<?= h(t('e.g. Silence, entropy, limit, construction, gravity...', 'ej. Silencio, entropía, límite, construcción, gravedad...')) ?>"><?= field_value($profile, 'conceptual_keywords') ?></textarea>
                            <small><?= h(t('Core philosophical terms guiding the metadata.', 'Términos filosóficos centrales que guían los metadatos.')) ?></small>
                            <?php admin_vars_hint($isAdmin, 'conceptual_keywords'); ?>
                        </div>
                    </div>
                </div>

                <div class="submit-container">
                    <button type="submit" class="button"><?= h(t('Save Profile Context', 'Guardar Contexto del Perfil')) ?></button>
                </div>
            </form>
        </div>
    </main>
</div>
</body>
</html>

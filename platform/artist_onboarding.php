<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
$pdo = Database::connection();

if (!ArtistOnboarding::isRequired($user) || ArtistOnboarding::isComplete($user, $pdo)) {
    header('Location: create_scenes.php');
    exit;
}

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$currentProfile = ArtistProfile::findForUser((int)$user['id']);
$languagePolicy = LanguagePolicy::forUser((int)$user['id']);
$domainService = new ArtistDomainService($pdo);
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        Auth::requireValidCsrf((string)($_POST['csrf'] ?? ''), 'artist_onboarding');

        $artistName = trim((string)($_POST['artist_name'] ?? ''));
        $workingLocale = (string)($_POST['working_locale'] ?? '');
        $submittedPublication = is_array($_POST['publication_locales'] ?? null)
            ? array_map('strval', $_POST['publication_locales'])
            : [];
        $subdomain = (string)($_POST['subdomain'] ?? '');

        if ($artistName === '') {
            throw new InvalidArgumentException(t('Enter your artist name.', 'Ingresá tu nombre de artista.'));
        }

        LanguagePolicy::save((int)$user['id'], $workingLocale, $submittedPublication);
        $domainService->saveConfiguration((int)$user['id'], $subdomain, (string)($currentProfile['custom_domain'] ?? ''));

        $profileInput = $currentProfile;
        $profileInput['artist_name'] = $artistName;
        ArtistProfile::saveForUser((int)$user['id'], $profileInput);

        header('Location: create_scenes.php');
        exit;
    } catch (InvalidArgumentException | RuntimeException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        $error = t('We could not save your setup. Please try again.', 'No pudimos guardar tu configuración. Volvé a intentarlo.');
    }
}

$formArtistName = (string)($_POST['artist_name'] ?? $currentProfile['artist_name'] ?? '');
$formWorkingLocale = (string)($_POST['working_locale'] ?? $languagePolicy['working_locale']);
$formPublicationLocales = is_array($_POST['publication_locales'] ?? null)
    ? array_map('strval', $_POST['publication_locales'])
    : $languagePolicy['publication_locales'];
$formSubdomain = (string)($_POST['subdomain'] ?? $currentProfile['subdomain'] ?? '');
?>
<!doctype html>
<html lang="<?= h(Translator::locale($user)) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h(t('Set up your studio - Artwork Mockups', 'Configurá tu estudio - Artwork Mockups')) ?></title>
    <link rel="icon" type="image/svg+xml" href="favicon.svg?v=1">
    <link rel="stylesheet" href="style.css?v=auth-gallery-6">
</head>
<body class="auth-page">

<main class="auth-layout-v2">
    <div class="auth-card-floating">
        <span class="brand-v2">
            <span class="star-mark">✦</span> Artwork Mockups
        </span>

        <h1><?= h(t('Set up your studio', 'Configurá tu estudio')) ?></h1>
        <p class="page-kicker-v2"><?= h(t(
            'A few essentials before you start: your name, the language you work in, and your website address. You can refine everything else from your profile later.',
            'Unos pocos datos esenciales antes de empezar: tu nombre, el idioma en el que trabajás y la dirección de tu sitio web. El resto lo podés afinar después desde tu perfil.'
        )) ?></p>

        <div class="auth-divider">
            <span class="divider-dot">✦</span>
        </div>

        <?php if ($error !== ''): ?>
            <p class="notice error" style="margin-bottom: 20px;"><?= h($error) ?></p>
        <?php endif; ?>

        <form method="post" novalidate>
            <input type="hidden" name="csrf" value="<?= h(Auth::csrfToken('artist_onboarding')) ?>">

            <div class="form-group-v2">
                <label for="artist_name"><?= h(t('Artist name', 'Nombre de artista')) ?></label>
                <div class="input-wrapper-v2">
                    <input type="text" id="artist_name" name="artist_name" required value="<?= h($formArtistName) ?>" placeholder="<?= h(t('Your name as it appears publicly', 'Tu nombre tal como aparece públicamente')) ?>">
                </div>
            </div>

            <div class="form-group-v2">
                <label for="working_locale"><?= h(t('Working language', 'Idioma de trabajo')) ?></label>
                <p style="margin:4px 0 8px; font-size:13px; opacity:.75;"><?= h(t('The language you analyse, generate and review in.', 'El idioma en el que analizás, generás y revisás.')) ?></p>
                <div class="input-wrapper-v2">
                    <select id="working_locale" name="working_locale">
                        <?php foreach (LanguagePolicy::supportedLocales() as $localeCode => $localeLabel): ?>
                            <option value="<?= h($localeCode) ?>" <?= $formWorkingLocale === $localeCode ? 'selected' : '' ?>><?= h($localeLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <fieldset style="margin-top:20px; border:0; padding:0;">
                <legend style="padding:0;"><?= h(t('Publication languages', 'Idiomas de publicación')) ?></legend>
                <p style="margin:4px 0 8px; font-size:13px; opacity:.75;"><?= h(t('The languages your public site is published in. Choose at least one.', 'Los idiomas en los que se publica tu sitio público. Elegí al menos uno.')) ?></p>
                <?php foreach (LanguagePolicy::supportedLocales() as $localeCode => $localeLabel): ?>
                    <label style="display:block; margin-bottom:6px; font-weight:400;">
                        <input
                            type="checkbox"
                            name="publication_locales[]"
                            value="<?= h($localeCode) ?>"
                            <?= in_array($localeCode, $formPublicationLocales, true) ? 'checked' : '' ?>
                        >
                        <?= h($localeLabel) ?>
                    </label>
                <?php endforeach; ?>
            </fieldset>

            <div class="form-group-v2" style="margin-top:20px;">
                <label for="subdomain"><?= h(t('Your website address', 'La dirección de tu sitio web')) ?></label>
                <div class="input-wrapper-v2">
                    <input type="text" id="subdomain" name="subdomain" required value="<?= h($formSubdomain) ?>" placeholder="<?= h(t('your-name', 'tu-nombre')) ?>" pattern="^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$" title="<?= h(t('Lowercase letters, numbers, and internal hyphens.', 'Minúsculas, números y guiones internos.')) ?>">
                </div>
                <small><?= h(t('Included with every artist website: your-name.artworkmockups.com. You can add your own domain later from your profile.', 'Incluido con cada sitio de artista: tu-nombre.artworkmockups.com. Podés agregar tu propio dominio después desde tu perfil.')) ?></small>
            </div>

            <button type="submit" class="btn-submit-v2"><?= h(t('Start', 'Empezar')) ?></button>
        </form>
    </div>
</main>

</body>
</html>

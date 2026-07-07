<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
$saved = false;
$error = '';

function artist_profile_photo_dir(): string
{
    return __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'artist_profiles';
}

function artist_profile_photo_url(string $file): string
{
    $file = basename($file);
    return $file !== '' ? 'uploads/artist_profiles/' . rawurlencode($file) : '';
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
        throw new RuntimeException('Artist photo upload failed.');
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Artist photo upload is invalid.');
    }

    $info = @getimagesize($tmp);
    if (!is_array($info) || empty($info['mime'])) {
        throw new RuntimeException('Artist photo must be a valid image.');
    }

    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    $mime = (string)$info['mime'];
    if (!isset($extensions[$mime])) {
        throw new RuntimeException('Artist photo must be JPG, PNG, or WEBP.');
    }

    $dir = artist_profile_photo_dir();
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        throw new RuntimeException('Could not create artist photo directory.');
    }

    $name = 'artist-' . $userId . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $extensions[$mime];
    $target = $dir . DIRECTORY_SEPARATOR . $name;

    if (!move_uploaded_file($tmp, $target)) {
        throw new RuntimeException('Could not save artist photo.');
    }

    return $name;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $currentProfile = ArtistProfile::findForUser((int)$user['id']);
        $input = $_POST;
        $input['photo_file'] = handle_artist_photo_upload((int)$user['id'], basename((string)($currentProfile['photo_file'] ?? '')));
        ArtistProfile::saveForUser((int)$user['id'], $input);
        $saved = true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$profile = ArtistProfile::findForUser((int)$user['id']);
$isAdmin = Auth::isAdmin($user);

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
        echo 'Prompt variable: ' . h($vars['prompt_variable']) . '<br>';
    }
    echo 'Included in: ' . h($vars['included_in']);
    echo '</small>';
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Artist Profile - Artwork Mockups</title>
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
            Artist Context & AI Guidance: These details act as semantic context for the AI, refining visual recommendations and descriptions.
        </div>

        <div class="workspace">
            <div class="workspace-header artist-profile-header">
                <div>
                    <h1>Artist Profile</h1>
                    <p>Configure the artist context that shapes analysis, descriptions, and mockup guidance.</p>
                </div>
                <div class="topbar-actions">
                    <a class="button-link secondary" href="root_album.php">Root Artworks</a>
                </div>
            </div>

            <?php if ($saved): ?>
                <div class="notice">Profile saved successfully.</div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="notice error"><?= h($error) ?></div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data">
                <div class="profile-grid">
                    <!-- Column 1: Artistic Identity -->
                    <div class="profile-card">
                        <h3>Artistic Identity</h3>

                        <div class="artist-photo-box">
                            <div class="artist-photo-preview">
                                <?php $artistPhotoUrl = artist_profile_photo_url((string)($profile['photo_file'] ?? '')); ?>
                                <?php if ($artistPhotoUrl !== ''): ?>
                                    <img src="<?= h($artistPhotoUrl) ?>" alt="Artist photo">
                                <?php else: ?>
                                    No photo
                                <?php endif; ?>
                            </div>
                            <div class="form-group">
                                <label>Artist Photo</label>
                                <input type="file" name="artist_photo" accept="image/jpeg,image/png,image/webp">
                                <input type="hidden" name="photo_file" value="<?= field_value($profile, 'photo_file') ?>">
                                <small>JPG, PNG, or WEBP portrait image.</small>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Artistic Name</label>
                            <input type="text" name="artist_name" value="<?= field_value($profile, 'artist_name') ?>" placeholder="e.g. Elena Rostova">
                            <?php admin_vars_hint($isAdmin, 'artist_name'); ?>
                        </div>

                        <div class="form-group">
                            <label>Short Artist Bio</label>
                            <textarea name="short_bio" rows="3" placeholder="Brief biography focusing on career, studies and background..."><?= field_value($profile, 'short_bio') ?></textarea>
                            <?php admin_vars_hint($isAdmin, 'short_bio'); ?>
                        </div>

                        <div class="form-group">
                            <label>Artistic Statement</label>
                            <textarea name="statement" rows="4" placeholder="Conceptual statement, intention, search or constant themes in your work..."><?= field_value($profile, 'statement') ?></textarea>
                            <?php admin_vars_hint($isAdmin, 'statement'); ?>
                        </div>

                        <div class="form-group">
                            <label>Visual Language</label>
                            <textarea name="visual_language" rows="3" placeholder="e.g. Abstract expressionism, geometric structure, textures, organic lines..."><?= field_value($profile, 'visual_language') ?></textarea>
                            <?php admin_vars_hint($isAdmin, 'visual_language'); ?>
                        </div>

                        <div class="form-group">
                            <label>Recurring Symbols / Motifs</label>
                            <textarea name="recurring_themes" rows="3" placeholder="e.g. Grids, thresholds, minerals, shadow play, anatomical forms..."><?= field_value($profile, 'recurring_themes') ?></textarea>
                            <?php admin_vars_hint($isAdmin, 'recurring_themes'); ?>
                        </div>

                        <div class="form-group">
                            <label>Materials & Process</label>
                            <textarea name="materials" rows="3" placeholder="e.g. Layered acrylic, spatula incisions, wood panels, pigments..."><?= field_value($profile, 'materials') ?></textarea>
                            <?php admin_vars_hint($isAdmin, 'materials'); ?>
                        </div>
                    </div>

                    <!-- Column 2: Mockups & Atmospheres -->
                    <div class="profile-card">
                        <h3>Atmospheres & Curation</h3>

                        <div class="form-group">
                            <label>Preferred Atmospheres</label>
                            <textarea name="palette_notes" rows="4" placeholder="e.g. Nocturnal, mineral, warm, tense, serene, intimate, luminous, restrained..."><?= field_value($profile, 'palette_notes') ?></textarea>
                            <small>Shorthand description of lighting and color temp preferences.</small>
                            <?php admin_vars_hint($isAdmin, 'palette_notes'); ?>
                        </div>

                        <div class="form-group">
                            <label>Preferred Mockup Styles</label>
                            <textarea name="preferred_contexts" rows="4" placeholder="e.g. Modernist galleries, architectural concrete rooms, townhouses, clean brick walls..."><?= field_value($profile, 'preferred_contexts') ?></textarea>
                            <small>Styles or spaces that best showcase your style.</small>
                            <?php admin_vars_hint($isAdmin, 'preferred_contexts'); ?>
                        </div>

                        <div class="form-group">
                            <label>Excluded Mockup Contexts</label>
                            <textarea name="forbidden_contexts" rows="4" placeholder="e.g. Commercial kitchens, kids bedrooms, generic office spaces..."><?= field_value($profile, 'forbidden_contexts') ?></textarea>
                            <small>Environments the AI must avoid when creating mockups.</small>
                            <?php admin_vars_hint($isAdmin, 'forbidden_contexts'); ?>
                        </div>

                        <div class="form-group">
                            <label>Forbidden Language / Exclusions</label>
                            <textarea name="commercial_positioning" rows="5" placeholder="List words or tones to avoid in curatorial texts (e.g. do not use marketing jargon, avoid academic over-complexity)..."><?= field_value($profile, 'commercial_positioning') ?></textarea>
                            <small>Words or phrases to exclude from AI copy generation.</small>
                            <?php admin_vars_hint($isAdmin, 'commercial_positioning'); ?>
                        </div>
                    </div>

                    <!-- Column 3: Audience & Voice -->
                    <div class="profile-card">
                        <h3>Audience & Voice</h3>

                        <div class="form-group">
                            <label>Tone of Voice</label>
                            <textarea name="tone_of_voice" rows="3" placeholder="e.g. Poetic, minimalist, elegant, conversational, technical, collectors-focused..."><?= field_value($profile, 'tone_of_voice') ?></textarea>
                            <small>Guides the writing style of artwork descriptions.</small>
                            <?php admin_vars_hint($isAdmin, 'tone_of_voice'); ?>
                        </div>

                        <div class="form-group">
                            <label>Target Audience / Presentation Context</label>
                            <textarea name="target_audience" rows="3" placeholder="e.g. Collectors, curators, architects, quiet interiors, institutional spaces..."><?= field_value($profile, 'target_audience') ?></textarea>
                            <small>Defines who the work should speak to and where it should feel at home.</small>
                            <?php admin_vars_hint($isAdmin, 'target_audience'); ?>
                        </div>

                        <div class="form-group">
                            <label>Conceptual Keywords</label>
                            <textarea name="conceptual_keywords" rows="3" placeholder="e.g. Silence, entropy, limit, construction, gravity..."><?= field_value($profile, 'conceptual_keywords') ?></textarea>
                            <small>Core philosophical terms guiding the metadata.</small>
                            <?php admin_vars_hint($isAdmin, 'conceptual_keywords'); ?>
                        </div>
                    </div>
                </div>

                <div class="submit-container">
                    <button type="submit" class="button">Save Profile Context</button>
                </div>
            </form>
        </div>
    </main>
</div>
</body>
</html>

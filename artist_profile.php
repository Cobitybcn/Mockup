<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
$saved = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        ArtistProfile::saveForUser((int)$user['id'], $_POST);
        $saved = true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$profile = ArtistProfile::findForUser((int)$user['id']);

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function field_value(array $profile, string $field): string
{
    return h($profile[$field] ?? '');
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Artist Profile - The Artwork Curator</title>
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
            <div class="workspace-header">
                <div>
                    <h1>Artist Profile</h1>
                    <p>Configure the creative and commercial identity that shapes your catalog's metadata.</p>
                </div>
                <div class="topbar-actions">
                    <a class="button-link secondary" href="dashboard.php">Dashboard</a>
                </div>
            </div>

            <?php if ($saved): ?>
                <div class="notice">Profile saved successfully.</div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="notice error"><?= h($error) ?></div>
            <?php endif; ?>

            <form method="post">
                <div class="profile-grid">
                    <!-- Column 1: Artistic Identity -->
                    <div class="profile-card">
                        <h3>Artistic Identity</h3>

                        <div class="form-group">
                            <label>Artistic Name</label>
                            <input type="text" name="artist_name" value="<?= field_value($profile, 'artist_name') ?>" placeholder="e.g. Elena Rostova">
                        </div>

                        <div class="form-group">
                            <label>Short Artist Bio</label>
                            <textarea name="short_bio" rows="3" placeholder="Brief biography focusing on career, studies and background..."><?= field_value($profile, 'short_bio') ?></textarea>
                        </div>

                        <div class="form-group">
                            <label>Artistic Statement</label>
                            <textarea name="statement" rows="4" placeholder="Conceptual statement, intention, search or constant themes in your work..."><?= field_value($profile, 'statement') ?></textarea>
                        </div>

                        <div class="form-group">
                            <label>Visual Language</label>
                            <textarea name="visual_language" rows="3" placeholder="e.g. Abstract expressionism, geometric structure, textures, organic lines..."><?= field_value($profile, 'visual_language') ?></textarea>
                        </div>

                        <div class="form-group">
                            <label>Recurring Symbols / Motifs</label>
                            <textarea name="recurring_themes" rows="3" placeholder="e.g. Grids, thresholds, minerals, shadow play, anatomical forms..."><?= field_value($profile, 'recurring_themes') ?></textarea>
                        </div>

                        <div class="form-group">
                            <label>Materials & Process</label>
                            <textarea name="materials" rows="3" placeholder="e.g. Layered acrylic, spatula incisions, wood panels, pigments..."><?= field_value($profile, 'materials') ?></textarea>
                        </div>
                    </div>

                    <!-- Column 2: Mockups & Atmospheres -->
                    <div class="profile-card">
                        <h3>Atmospheres & Curation</h3>

                        <div class="form-group">
                            <label>Preferred Atmospheres</label>
                            <textarea name="palette_notes" rows="4" placeholder="e.g. Nocturnal, mineral, warm, tense, serene, intimate, luminous, restrained..."><?= field_value($profile, 'palette_notes') ?></textarea>
                            <small>Shorthand description of lighting and color temp preferences.</small>
                        </div>

                        <div class="form-group">
                            <label>Preferred Mockup Styles</label>
                            <textarea name="preferred_contexts" rows="4" placeholder="e.g. Modernist galleries, architectural concrete rooms, townhouses, clean brick walls..."><?= field_value($profile, 'preferred_contexts') ?></textarea>
                            <small>Styles or spaces that best showcase your style.</small>
                        </div>

                        <div class="form-group">
                            <label>Excluded Mockup Contexts</label>
                            <textarea name="forbidden_contexts" rows="4" placeholder="e.g. Commercial kitchens, kids bedrooms, generic office spaces..."><?= field_value($profile, 'forbidden_contexts') ?></textarea>
                            <small>Environments the AI must avoid when creating mockups.</small>
                        </div>

                        <div class="form-group">
                            <label>Forbidden Language / Exclusions</label>
                            <textarea name="commercial_positioning" rows="5" placeholder="List words or tones to avoid in curatorial texts (e.g. do not use marketing jargon, avoid academic over-complexity)..."><?= field_value($profile, 'commercial_positioning') ?></textarea>
                            <small>Words or phrases to exclude from AI copy generation.</small>
                        </div>
                    </div>

                    <!-- Column 3: Commercial & Distribution -->
                    <div class="profile-card">
                        <h3>Distribution & Marketing</h3>

                        <div class="form-group">
                            <label>Tone of Voice</label>
                            <textarea name="tone_of_voice" rows="3" placeholder="e.g. Poetic, minimalist, elegant, conversational, technical, collectors-focused..."><?= field_value($profile, 'tone_of_voice') ?></textarea>
                            <small>Guides the writing style of descriptions and captions.</small>
                        </div>

                        <div class="form-group">
                            <label>Target Market</label>
                            <textarea name="target_audience" rows="3" placeholder="e.g. Boutique hotels, corporate collectors, high-net-worth individuals, design agencies..."><?= field_value($profile, 'target_audience') ?></textarea>
                            <small>Defines commercial copy hooks.</small>
                        </div>

                        <div class="form-group">
                            <label>Conceptual Keywords</label>
                            <textarea name="conceptual_keywords" rows="3" placeholder="e.g. Silence, entropy, limit, construction, gravity..."><?= field_value($profile, 'conceptual_keywords') ?></textarea>
                            <small>Core philosophical terms guiding the metadata.</small>
                        </div>

                        <div class="form-group">
                            <label>Marketplace Strategy</label>
                            <textarea name="marketplace_strategy" rows="4" placeholder="e.g. Emphasize physical detail and authenticity for Saatchi listings; suggest framing options and premium materials..."><?= field_value($profile, 'marketplace_strategy') ?></textarea>
                            <small>Specific instructions for marketplaces copy.</small>
                        </div>

                        <div class="form-group">
                            <label>Social Media Strategy</label>
                            <textarea name="social_strategy" rows="3" placeholder="e.g. Short, hook-oriented captions with storytelling elements..."><?= field_value($profile, 'social_strategy') ?></textarea>
                        </div>

                        <div class="form-group">
                            <label>Pinterest Strategy</label>
                            <textarea name="pinterest_strategy" rows="3" placeholder="e.g. Board categories like 'Interior Design Inspiration', 'Modern Abstract Art'..."><?= field_value($profile, 'pinterest_strategy') ?></textarea>
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

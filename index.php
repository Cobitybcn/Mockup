<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

// Redirect if already logged in
if (Auth::user()) {
    header('Location: root_album.php');
    exit;
}

$error = '';

// Handle Login POST request directly on landing page
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if (Auth::login($email, $password)) {
        header('Location: root_album.php');
        exit;
    }

    $error = 'Incorrect email or password.';
}

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Artwork Mockups — AI Art Mockup Generator for Artists & Galleries</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;0,700;1,400;1,500&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css?v=landing-2">
    <style>
        /* Landing Page Specific Scoped Styling */
        html {
            scroll-behavior: smooth;
        }
        body.landing-theme {
            background: var(--bg);
            color: var(--ink);
            font-family: var(--font-sans);
            overflow-x: hidden;
            margin: 0;
            padding: 0;
        }
        body.landing-theme h1,
        body.landing-theme h2,
        body.landing-theme h3,
        body.landing-theme h4,
        body.landing-theme h5,
        body.landing-theme h6,
        body.landing-theme .section-title,
        body.landing-theme .brand-title {
            color: var(--ink) !important;
        }
        body.landing-theme p {
            color: var(--muted) !important;
        }
        body.landing-theme a {
            color: var(--muted);
            transition: color 0.2s ease;
        }
        body.landing-theme a:hover {
            color: var(--accent);
        }
        body.landing-theme .section-kicker,
        body.landing-theme .hero-kicker,
        body.landing-theme .showcase-tag {
            color: var(--accent) !important;
            font-weight: 600;
        }
        
        /* Header */
        .landing-header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 80px;
            background: rgba(250, 249, 246, 0.9);
            backdrop-filter: blur(16px);
            border-bottom: 1px solid var(--line);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 4%;
            z-index: 100;
        }
        .landing-header .brand-title {
            color: var(--ink) !important;
        }
        .landing-nav {
            display: flex;
            align-items: center;
            gap: 32px;
        }
        .landing-nav a {
            color: var(--ink) !important;
            text-decoration: none;
            font-weight: 600;
            font-size: 11px;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }
        .landing-nav a:hover {
            color: var(--accent) !important;
        }
        .btn-cta {
            background: var(--accent) !important;
            color: #FFFFFF !important;
            padding: 10px 20px;
            border-radius: 4px;
            font-weight: 600 !important;
            text-transform: uppercase;
            font-size: 11px;
            letter-spacing: 0.08em;
            transition: all 0.2s ease;
            border: 1px solid var(--accent) !important;
            text-decoration: none;
            display: inline-block;
        }
        .btn-cta:hover {
            background: transparent !important;
            color: var(--accent) !important;
            box-shadow: 0 4px 16px rgba(154, 123, 86, 0.15) !important;
        }

        /* Hero Section */
        .hero-section {
            min-height: 90vh;
            min-height: 90dvh;
            padding: 160px 4% 100px;
            display: flex;
            align-items: center;
            position: relative;
            background: var(--bg);
            overflow: hidden;
        }
        .hero-content {
            position: relative;
            z-index: 5;
            max-width: 550px;
        }
        .hero-content h1 {
            font-family: var(--font-serif);
            font-weight: 400;
            font-size: clamp(48px, 5.8vw, 72px);
            line-height: 1.08;
            margin: 0 0 24px 0;
            color: var(--ink) !important;
            letter-spacing: -0.02em;
        }
        .hero-content h1 em {
            font-style: italic;
            font-family: var(--font-serif);
            font-weight: 400;
        }
        .hero-content p {
            font-size: 15px;
            line-height: 1.7;
            color: var(--muted) !important;
            margin-bottom: 36px;
            max-width: 580px;
        }
        .hero-actions {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
        }
        .btn-secondary {
            background: transparent !important;
            color: var(--ink) !important;
            padding: 12px 28px;
            border-radius: 4px;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 11px;
            letter-spacing: 0.08em;
            border: 1px solid var(--line) !important;
            text-decoration: none;
            transition: all 0.2s ease;
            display: inline-block;
        }
        .btn-secondary:hover {
            border-color: var(--ink) !important;
            background: var(--surface-soft) !important;
        }

        /* Hero Visual Frame */
        .hero-visual {
            position: relative;
            z-index: 2;
            display: flex;
            justify-content: center;
        }
        .frame-container {
            background: var(--surface);
            border: 1px solid var(--line);
            padding: 16px;
            border-radius: 12px;
            box-shadow: var(--shadow-hover);
            max-width: 100%;
        }
        .frame-image {
            width: 100%;
            height: auto;
            max-height: 480px;
            object-fit: cover;
            border-radius: 6px;
            border: 1px solid var(--line);
            display: block;
        }
        .frame-caption {
            font-size: 11px;
            color: var(--muted);
            margin-top: 12px;
            text-align: center;
            letter-spacing: 0.05em;
        }

        /* Features Section */
        .section-padding {
            padding: 100px 4%;
            border-top: 1px solid var(--line) !important;
            position: relative;
            background: var(--bg) !important;
        }
        .section-title-wrapper {
            max-width: 700px;
            margin-bottom: 60px;
        }
        .section-title {
            font-family: var(--font-serif);
            font-size: 40px;
            font-weight: 500;
            margin: 0;
            line-height: 1.2;
            color: var(--ink) !important;
        }
        
        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 24px;
        }
        .feature-card {
            position: relative;
            background: var(--surface) !important;
            border: 1px solid var(--line) !important;
            padding: 40px 24px 32px 24px;
            border-radius: 16px;
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            box-shadow: 0 4px 20px rgba(20, 20, 18, 0.02);
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
        }
        .feature-card::before {
            content: "";
            position: absolute;
            top: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 48px;
            height: 3px;
            background: var(--accent);
            border-radius: 0 0 3px 3px;
            opacity: 0.8;
        }
        .feature-card:hover {
            border-color: var(--accent) !important;
            transform: translateY(-6px);
            box-shadow: 0 20px 40px rgba(20, 20, 18, 0.06);
        }
        .feature-icon {
            width: 76px;
            height: 76px;
            border: 1px solid var(--line);
            background: radial-gradient(circle, #FFFFFF 0%, #FAF9F6 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--accent);
            margin-bottom: 8px;
            box-shadow: 0 4px 10px rgba(20, 20, 18, 0.02), inset 0 2px 4px rgba(255, 255, 255, 0.9);
            transition: all 0.3s ease;
        }
        .feature-icon svg {
            color: var(--accent);
        }
        .feature-card:hover .feature-icon {
            border-color: var(--accent);
            transform: scale(1.05);
            box-shadow: 0 6px 16px rgba(154, 123, 86, 0.12), inset 0 1px 0 #FFFFFF;
        }
        .feature-card-divider {
            width: 24px;
            height: 1px;
            background: var(--accent);
            margin: 16px auto;
            opacity: 0.5;
        }
        .section-divider {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-top: 20px;
            max-width: 320px;
        }
        .divider-line {
            flex: 1;
            height: 1px;
            background: var(--line-dark, #C5C3BD);
            opacity: 0.5;
        }
        .divider-diamond {
            color: var(--accent);
            font-size: 8px;
            line-height: 1;
        }
        
        /* High-Impact Hero Showcase Visuals: Immersive Backdrop Slider */
        .hero-bg-slider {
            position: absolute;
            top: 0;
            bottom: 0;
            right: 0;
            left: 36%;
            z-index: 1;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: flex-end;
        }
        .hero-slide {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            z-index: 1;
            transition: opacity 1.2s ease-in-out;
            pointer-events: none;
        }
        .hero-slide.active {
            opacity: 1;
            z-index: 2;
        }
        .hero-slide img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center center;
            display: block;
        }
        .hero-bg-overlay {
            position: absolute;
            inset: 0;
            background: linear-gradient(90deg, 
                rgba(250, 249, 246, 1) 0%, 
                rgba(250, 249, 246, 0.8) 18%, 
                rgba(250, 249, 246, 0.2) 65%, 
                rgba(250, 249, 246, 0) 100%
            );
            z-index: 3;
            pointer-events: none;
        }
        .hero-section::before {
            content: "";
            position: absolute;
            top: 0;
            left: 4%;
            right: 4%;
            height: 1px;
            background: var(--line);
            z-index: 4;
        }
        .hero-section::after {
            content: "";
            position: absolute;
            bottom: 0;
            left: 4%;
            right: 4%;
            height: 1px;
            background: var(--line);
            z-index: 4;
        }
        .metadata-badge-grid {
            display: flex;
            flex-direction: column;
            gap: 6px;
            font-size: 11px;
            color: var(--ink);
        }
        .metadata-badge-grid span {
            color: var(--muted);
            font-weight: 500;
        }
        .feature-card h3 {
            font-family: var(--font-serif);
            font-size: 20px;
            font-weight: 500;
            margin: 0 0 16px 0;
            color: var(--ink) !important;
            line-height: 1.3;
        }
        .feature-card p {
            font-size: 13px;
            line-height: 1.6;
            color: var(--muted) !important;
            margin: 0;
        }

        /* Showcase Section */
        .showcase-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 24px;
        }
        .showcase-item {
            position: relative;
            border-radius: 8px;
            overflow: hidden;
            border: 1px solid var(--line);
            aspect-ratio: 4/3;
        }
        .showcase-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.6s cubic-bezier(0.25, 1, 0.5, 1);
        }
        .showcase-item:hover img {
            transform: scale(1.05);
        }
        .showcase-overlay {
            position: absolute;
            inset: 0;
            background: linear-gradient(to top, rgba(20,20,18,0.95) 0%, rgba(20,20,18,0.1) 60%, transparent 100%);
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            padding: 24px;
        }
        .showcase-title {
            font-family: var(--font-serif);
            font-size: 18px;
            color: #FAF9F6 !important;
            margin: 0 0 4px 0;
        }
        .showcase-desc {
            font-size: 11px;
            color: rgba(250, 249, 246, 0.75) !important;
            margin: 0;
        }

        /* Login Container Section */
        .login-section {
            background: var(--surface-soft) !important;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
        }
        .login-section .auth-card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 32px;
            width: min(100%, 420px);
            box-shadow: var(--shadow-hover);
            text-align: left;
            margin-top: 24px;
        }
        .login-section label {
            display: block;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            margin-bottom: 6px;
            color: var(--muted);
            font-weight: 600;
        }
        .login-section input {
            width: 100%;
            height: 44px;
            border-radius: 4px;
            padding: 10px;
            border: 1px solid var(--line);
            background: var(--bg);
            color: var(--ink);
            font-family: var(--font-sans);
            margin-bottom: 16px;
            transition: all 0.2s ease;
        }
        .login-section input:focus {
            border-color: var(--accent);
            background: var(--surface);
            outline: 2px solid rgba(154, 123, 86, 0.15);
        }

        /* Responsive */
        @media (max-width: 960px) {
            .hero-section {
                grid-template-columns: 1fr;
                text-align: center;
                padding-top: 120px;
                gap: 40px;
            }
            .hero-content h1 {
                font-size: 42px;
            }
            .hero-actions {
                justify-content: center;
            }
            .showcase-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body class="landing-theme">

    <!-- Header -->
    <header class="landing-header">
        <div class="brand">
            <span class="brand-kicker">ArtworkMockups.com</span>
            <div class="brand-title">
                <span class="brand-mark"></span>
                Artwork Mockups
            </div>
        </div>
        <nav class="landing-nav">
            <a href="#features">Technology</a>
            <a href="#showcase">Showcase</a>
            <a href="#login" class="btn-cta">Login</a>
        </nav>
    </header>

    <!-- Hero Section -->
    <section class="hero-section">
        <div class="hero-content">
            <span class="hero-kicker">AI Art Mockup Generator for Artists & Galleries</span>
            <h1>Create Professional<br><em>Art Mockups</em><br>From One Artwork Photo</h1>
            <p>Upload a photo of your painting, drawing or original artwork and generate realistic gallery mockups, interior mockups and collector-space presentations with AI-assisted photographic direction — preserving the original colors, texture, scale and proportions.</p>
            <div class="hero-actions">
                <a href="#login" class="btn-cta" style="padding: 14px 32px; font-size: 13px;">CREATE YOUR FIRST MOCKUP</a>
                <a href="#showcase" class="btn-secondary" style="padding: 14px 32px; font-size: 13px;">VIEW ART MOCKUP EXAMPLES</a>
            </div>
        </div>
        <div class="hero-bg-slider">
            <?php
            $showcaseDir = __DIR__ . '/assets/showcase';
            $slides = [];
            if (is_dir($showcaseDir)) {
                $allFiles = glob($showcaseDir . '/*.{jpg,jpeg,png}', GLOB_BRACE) ?: [];
                foreach ($allFiles as $file) {
                    $name = basename($file);
                    // Filter out original artworks or templates
                    if (
                        !str_contains(strtolower($name), 'artwork') 
                        && !str_contains(strtolower($name), 'original')
                        && is_file($file)
                    ) {
                        $slides[] = 'assets/showcase/' . $name;
                    }
                }
            }
            if (empty($slides)) {
                $slides = [
                    'assets/showcase/latest_mockup_1.jpg',
                    'assets/showcase/latest_mockup_2.jpg',
                    'assets/showcase/latest_mockup_3.jpg',
                ];
            }
            
            // Limit to 10 showcase images
            $slides = array_slice($slides, 0, 10);
            $totalSlides = count($slides);
            ?>
            <?php foreach ($slides as $index => $slidePath): ?>
                <div class="hero-slide<?= $index === 0 ? ' active' : '' ?>">
                    <img src="<?= htmlspecialchars($slidePath) ?>" alt="Showcase Space <?= $index + 1 ?>">
                </div>
            <?php endforeach; ?>
            <div class="hero-bg-overlay"></div>
        </div>
    </section>

    <!-- Features Section -->
    <section id="features" class="section-padding">
        <div class="section-title-wrapper">
            <span class="section-kicker">THE TECHNICAL ADVANTAGE</span>
            <h2 class="section-title">Calibrated Specifically<br>for Fine Art Curation</h2>
            <div class="section-divider">
                <span class="divider-line"></span>
                <span class="divider-diamond">♦</span>
                <span class="divider-line"></span>
            </div>
        </div>
        <div class="features-grid">
            <div class="feature-card">
                <div class="feature-icon">
                    <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
                        <path d="M9 11l2 2 4-4" />
                    </svg>
                </div>
                <div class="feature-card-divider"></div>
                <h3>Faithful Artwork Preservation</h3>
                <p>Your painting, drawing or original artwork remains untouched. The system preserves composition, colors, texture, proportions and visual identity while changing only the presentation context.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">
                    <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="6" y="6" width="12" height="12" />
                        <path d="M6 3v6M3 6h6" />
                        <path d="M18 3v6M15 6h6" />
                        <path d="M6 15v6M3 18h6" />
                        <path d="M18 15v6M15 18h6" />
                    </svg>
                </div>
                <div class="feature-card-divider"></div>
                <h3>True-to-Scale Artwork Placement</h3>
                <p>Artwork dimensions are used to place each piece realistically within galleries, interiors and collector spaces — avoiding oversized walls, tiny canvases or unnatural proportions.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">
                    <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M10 6l4 2-2 4-4-2z" />
                        <path d="M12.5 5.5l1.5-1.5M7.5 9.5l-1.5 1.5" />
                        <path d="M12 10l-4 11M12 10l4 11M12 10v4" />
                        <path d="M18 6l1 1-1 1-1-1zm-12 10l1 1-1 1-1-1z" />
                    </svg>
                </div>
                <div class="feature-card-divider"></div>
                <h3>AI-Assisted Photographic Lighting</h3>
                <p>Generate mockups with natural daylight, gallery spotlights, soft shadows and realistic surface response, including canvas texture, paint relief and varnish reflections.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">
                    <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z" />
                        <circle cx="12" cy="13" r="4" />
                    </svg>
                </div>
                <div class="feature-card-divider"></div>
                <h3>Curated Camera Perspectives</h3>
                <p>Choose from frontal views, three-quarter angles, aerial compositions, low-angle perspectives and editorial close-ups designed for professional artwork presentation.</p>
            </div>
        </div>
    </section>

    <!-- Showcase Section -->
    <section id="showcase" class="section-padding" style="background: #0D0D0A;">
        <div class="section-title-wrapper">
            <span class="section-kicker">Showcase Gallery</span>
            <h2 class="section-title">Explore Artwork Mockups in Curated Rooms</h2>
            <p style="font-size: 14px; margin-top: 10px; max-width: 600px;">Gallery walls, collector interiors, floor displays and editorial camera angles designed to present paintings, drawings and original artworks professionally.</p>
        </div>
        <div class="showcase-grid">
            <div class="showcase-item">
                <img src="assets/showcase/34-right-view-attic-studio-3-4-right-view-medium-test-piece-mockup-02.jpg" alt="Attic Studio Gallery">
                <div class="showcase-overlay">
                    <span class="showcase-tag">Attic Space</span>
                    <h3 class="showcase-title">Attic Studio Gallery</h3>
                    <p class="showcase-desc">3/4 oblique perspective showing canvas texture under large skylights.</p>
                </div>
            </div>
            <div class="showcase-item">
                <img src="assets/showcase/floor-leaning.jpg" alt="Blue Hour Studio Loft">
                <div class="showcase-overlay">
                    <span class="showcase-tag">Floor Leaning Display</span>
                    <h3 class="showcase-title">Blue Hour Studio Loft</h3>
                    <p class="showcase-desc">Oblique side view showcasing canvas texture in soft ambient light.</p>
                </div>
            </div>
            <div class="showcase-item">
                <img src="assets/showcase/nadir.jpg" alt="Industrial Nadir Lounge">
                <div class="showcase-overlay">
                    <span class="showcase-tag">Low-Angle Vertical View</span>
                    <h3 class="showcase-title">Industrial Nadir Lounge</h3>
                    <p class="showcase-desc">High-ceiling dramatic perspective highlighting architectural scale.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Login Area -->
    <section id="login" class="section-padding login-section">
        <div class="section-title-wrapper" style="text-align: center; margin-bottom: 24px;">
            <span class="section-kicker">Artist Workspace</span>
            <h2 class="section-title">Enter Artwork Mockups</h2>
            <p style="color: var(--muted); font-size: 14px; margin-top: 10px;">Manage your artwork catalog and generate coordinated mockup sets.</p>
        </div>

        <div class="auth-panel" style="background: transparent; min-height: auto; padding: 0; border: none;">
            <?php if ($error): ?>
                <p class="notice error" style="margin-bottom: 16px; width: min(100%, 380px);"><?= h($error) ?></p>
            <?php endif; ?>

            <form class="auth-card" method="post" action="#login">
                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 11px; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 6px;">Email</label>
                    <input type="email" name="email" required autocomplete="email" style="width: 100%; border-radius: 4px; padding: 10px; font-family: var(--font-sans);">
                </div>
                <div style="margin-bottom: 24px;">
                    <label style="display: block; font-size: 11px; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 6px;">Password</label>
                    <input type="password" name="password" required style="width: 100%; border-radius: 4px; padding: 10px; font-family: var(--font-sans);">
                </div>
                <button type="submit" class="btn-cta" style="width: 100%; padding: 12px; cursor: pointer; border-radius: 4px; font-size: 13px;">Sign In</button>
            </form>

            <p style="margin-top: 20px; font-size: 13px; color: var(--muted);">
                Don't have an account? <a href="register.php" style="color: var(--accent); text-decoration: underline;">Register here</a>
            </p>
        </div>
    </section>

    <!-- Footer -->
    <footer style="padding: 40px 4%; border-top: 1px solid var(--line); text-align: center; font-size: 12px; color: var(--muted);">
        <p>&copy; 2026 ArtworkMockups.com. All rights reserved. Powered by Vertex AI & Gemini.</p>
    </footer>

    <script>
    document.addEventListener("DOMContentLoaded", function() {
        const slides = document.querySelectorAll('.hero-slide');
        if (slides.length <= 1) return;
        let currentSlide = 0;
        setInterval(() => {
            slides[currentSlide].classList.remove('active');
            currentSlide = (currentSlide + 1) % slides.length;
            slides[currentSlide].classList.add('active');
        }, 5000);
    });
    </script>
</body>
</html>

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
    <title>The Artwork Curator — AI-Curated Fine Art Mockups</title>
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
            color: var(--muted) !important;
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
            color: var(--bg) !important;
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
            min-height: 100vh;
            padding: 140px 4% 80px;
            display: grid;
            grid-template-columns: 1.15fr 0.85fr;
            align-items: center;
            gap: 60px;
            position: relative;
            background: linear-gradient(180deg, var(--surface) 0%, var(--bg) 100%);
        }
        .hero-content {
            position: relative;
            z-index: 2;
        }
        .hero-content h1 {
            font-family: var(--font-serif);
            font-size: clamp(42px, 4.8vw, 64px);
            line-height: 1.15;
            font-weight: 500;
            margin: 0 0 24px 0;
            color: var(--ink) !important;
            letter-spacing: -0.01em;
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
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 32px;
        }
        .feature-card {
            background: var(--surface) !important;
            border: 1px solid var(--line) !important;
            padding: 32px;
            border-radius: 8px;
            transition: all 0.3s ease;
            box-shadow: var(--shadow);
        }
        .feature-card:hover {
            border-color: var(--accent) !important;
            transform: translateY(-4px);
            box-shadow: var(--shadow-hover);
        }
        .feature-icon {
            width: 48px;
            height: 48px;
            border: 1px solid rgba(154, 123, 86, 0.25);
            background: rgba(154, 123, 86, 0.04);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--accent);
            margin-bottom: 24px;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.6), 0 2px 4px rgba(20,20,18,0.02);
        }
        .feature-icon svg {
            color: var(--accent);
        }
        
        /* High-Impact Hero Showcase Visuals */
        .showcase-visual-wrapper {
            position: relative;
            max-width: 100%;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .hero-main-image {
            width: 100%;
            height: auto;
            max-height: 520px;
            object-fit: cover;
            border-radius: 20px;
            border: 1px solid var(--line);
            box-shadow: 0 40px 100px rgba(20,20,18,0.16), 0 16px 40px rgba(20,20,18,0.08);
            display: block;
            transition: transform 0.4s cubic-bezier(0.25, 1, 0.5, 1);
        }
        .hero-main-image:hover {
            transform: translateY(-5px) scale(1.01);
        }
        .floating-metadata-card {
            position: absolute;
            bottom: -24px;
            left: -24px;
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(12px);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 18px 24px;
            box-shadow: 0 20px 40px rgba(20,20,18,0.08);
            z-index: 10;
            width: 240px;
            text-align: left;
        }
        .metadata-badge-kicker {
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: var(--accent);
            margin-bottom: 4px;
        }
        .metadata-badge-title {
            font-family: var(--font-serif);
            font-size: 18px;
            font-weight: 500;
            color: var(--ink);
            margin-bottom: 12px;
            border-bottom: 1px solid var(--line);
            padding-bottom: 8px;
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
            font-size: 22px;
            font-weight: 500;
            margin: 0 0 12px 0;
            color: var(--ink) !important;
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
            <span class="brand-kicker">Fine Art Mockups</span>
            <div class="brand-title">
                <span class="brand-mark"></span>
                The Artwork Curator
            </div>
        </div>
        <nav class="landing-nav">
            <a href="#features">Tecnología</a>
            <a href="#showcase">Galería</a>
            <a href="#login" class="btn-cta">Acceder</a>
        </nav>
    </header>

    <!-- Hero Section -->
    <section class="hero-section">
        <div class="hero-content">
            <span class="hero-kicker">Inteligencia Artificial para Artistas</span>
            <h1>Crea Montajes de Arte Fotorrealistas en Segundos</h1>
            <p>Transforma una foto plana de tu pintura o dibujo en maquetas de exhibición perfectamente calibradas. Ubica tus obras en galerías de alta gama, residencias contemporáneas y lofts con luz natural e integración de escala real.</p>
            <div class="hero-actions">
                <a href="#login" class="btn-cta" style="padding: 14px 32px; font-size: 13px;">Comenzar Ahora</a>
                <a href="#features" class="btn-secondary" style="padding: 14px 32px; font-size: 13px;">Ver Características</a>
            </div>
        </div>
        <div class="hero-visual">
            <div class="showcase-visual-wrapper">
                <img src="assets/showcase/brutalism.jpg" alt="Loft Brutalista" class="hero-main-image">
                <div class="floating-metadata-card">
                    <div class="metadata-badge-kicker">Mockup Generado</div>
                    <div class="metadata-badge-title">Loft Brutalista</div>
                    <div class="metadata-badge-grid">
                        <div><span>Espacio:</span> Loft de Hormigón</div>
                        <div><span>Perspectiva:</span> Oblicua 3/4</div>
                        <div><span>Luz:</span> Hora Azul</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section id="features" class="section-padding">
        <div class="section-title-wrapper">
            <span class="section-kicker">La Diferencia Técnica</span>
            <h2 class="section-title">Diseñado con Precisión para Pintores e Interiores de Arte</h2>
        </div>
        <div class="features-grid">
            <div class="feature-card">
                <div class="feature-icon">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="6" y="6" width="12" height="12" rx="1" />
                        <line x1="6" y1="3" x2="18" y2="3" />
                        <path d="M9 1l-3 2 3 2M15 1l3 2-3 2" />
                        <line x1="3" y1="6" x2="3" y2="18" />
                        <path d="M1 9l2-3 2 3M1 15l2 3 2-3" />
                    </svg>
                </div>
                <h3>Invarianza de Escala Real</h3>
                <p>El motor calcula el tamaño físico real de tu lienzo (ej. 120 x 90 cm) y lo escala proporcionalmente respecto a techos, sillones y ventanas. Olvídate de obras que se ven gigantes o diminutas.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M8 2h8M12 2v3" />
                        <path d="M9 8c0-2 6-2 6 0H9z" />
                        <path d="M7 12h10L15 8H9z" />
                        <line x1="9" y1="12" x2="4" y2="22" stroke-opacity="0.4" />
                        <line x1="15" y1="12" x2="20" y2="22" stroke-opacity="0.4" />
                        <line x1="12" y1="13" x2="12" y2="17" />
                        <line x1="10" y1="14" x2="9" y2="18" />
                        <line x1="14" y1="14" x2="15" y2="18" />
                    </svg>
                </div>
                <h3>Luz de Galería Natural</h3>
                <p>Nuestra IA proyecta sombras de caída suave y focos de riel sobre el lienzo, simulando de manera precisa la textura física, brillos y empastes de la pintura original sobre la pared.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z" />
                        <circle cx="12" cy="13" r="4" />
                    </svg>
                </div>
                <h3>Cámaras Editoriales</h3>
                <p>Elige entre planos directos para previsualización corporativa, perspectivas oblicuas a 3/4 que dan profundidad o espectaculares vistas desde ángulo Nadir (suelo-techo).</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="7" y="2" width="10" height="20" rx="2" />
                        <polygon points="10,9 15,12 10,15" fill="currentColor" opacity="0.15" />
                        <polygon points="10,9 15,12 10,15" />
                        <line x1="11" y1="4" x2="13" y2="4" />
                        <circle cx="12" cy="20" r="0.5" fill="currentColor" />
                    </svg>
                </div>
                <h3>Video Social Integrado</h3>
                <p>Exporta compilaciones en formato vertical 9:16 con transiciones y paneos suaves estilo Ken Burns, listos para publicar como Reels o TikToks sin necesidad de edición externa.</p>
            </div>
        </div>
    </section>

    <!-- Showcase Section -->
    <section id="showcase" class="section-padding" style="background: #0D0D0A;">
        <div class="section-title-wrapper">
            <span class="section-kicker">Showcase de Formatos</span>
            <h2 class="section-title">Explora los Tipos de Vistas Disponibles</h2>
        </div>
        <div class="showcase-grid">
            <div class="showcase-item">
                <img src="assets/showcase/ibiza.jpg" alt="Salón en Ibiza">
                <div class="showcase-overlay">
                    <span class="showcase-tag">Sala Contemporánea</span>
                    <h3 class="showcase-title">Salón Mediterráneo en Ibiza</h3>
                    <p class="showcase-desc">Iluminación natural cálida y proporciones de escala real.</p>
                </div>
            </div>
            <div class="showcase-item">
                <img src="assets/showcase/floor-leaning.jpg" alt="Estudio Hora Azul">
                <div class="showcase-overlay">
                    <span class="showcase-tag">Obra Apoyada</span>
                    <h3 class="showcase-title">Estudio en Hora Azul</h3>
                    <p class="showcase-desc">Perspectiva lateral oblicua con luz natural tenue.</p>
                </div>
            </div>
            <div class="showcase-item">
                <img src="assets/showcase/nadir.jpg" alt="Perspectiva Nadir">
                <div class="showcase-overlay">
                    <span class="showcase-tag">Contrapicado Suelo-Techo</span>
                    <h3 class="showcase-title">Perspectiva Nadir Industrial</h3>
                    <p class="showcase-desc">Plano dramático desde el suelo ideal para techos altos.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Login Area -->
    <section id="login" class="section-padding login-section">
        <div class="section-title-wrapper" style="text-align: center; margin-bottom: 24px;">
            <span class="section-kicker">Tu Archivo Privado</span>
            <h2 class="section-title">Acceder al Curador</h2>
            <p style="color: rgba(247, 242, 234, 0.5); font-size: 14px; margin-top: 10px;">Gestiona tus obras y genera conjuntos completos de maquetas.</p>
        </div>

        <div class="auth-panel" style="background: transparent; min-height: auto; padding: 0;">
            <?php if ($error): ?>
                <p class="notice error" style="margin-bottom: 16px; width: min(100%, 380px);"><?= h($error) ?></p>
            <?php endif; ?>

            <form class="auth-card" method="post" action="#login">
                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 11px; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 6px;">Email</label>
                    <input type="email" name="email" required autocomplete="email" style="width: 100%; border-radius: 4px; padding: 10px; font-family: var(--font-sans);">
                </div>
                <div style="margin-bottom: 24px;">
                    <label style="display: block; font-size: 11px; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 6px;">Contraseña</label>
                    <input type="password" name="password" required style="width: 100%; border-radius: 4px; padding: 10px; font-family: var(--font-sans);">
                </div>
                <button type="submit" class="btn-cta" style="width: 100%; padding: 12px; cursor: pointer; border-radius: 4px; font-size: 13px;">Iniciar Sesión</button>
            </form>

            <p style="margin-top: 20px; font-size: 13px; color: var(--muted);">
                ¿No tienes una cuenta? <a href="register.php" style="color: var(--accent); text-decoration: underline;">Regístrate aquí</a>
            </p>
        </div>
    </section>

    <!-- Footer -->
    <footer style="padding: 40px 4%; border-top: 1px solid var(--line); text-align: center; font-size: 12px; color: var(--muted);">
        <p>&copy; 2026 The Artwork Curator. Todos los derechos reservados. Tecnología impulsada por Vertex AI & Gemini.</p>
    </footer>

</body>
</html>

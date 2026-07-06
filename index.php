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
            background: #090907;
            color: #F7F2EA;
            font-family: var(--font-sans);
            overflow-x: hidden;
            margin: 0;
            padding: 0;
        }
        
        /* Glassmorphism Header */
        .landing-header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 80px;
            background: rgba(9, 9, 7, 0.85);
            backdrop-filter: blur(16px);
            border-bottom: 1px solid rgba(247, 242, 234, 0.06);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 4%;
            z-index: 100;
        }
        .landing-header .brand-title {
            color: #F7F2EA;
        }
        .landing-nav {
            display: flex;
            align-items: center;
            gap: 32px;
        }
        .landing-nav a {
            color: rgba(247, 242, 234, 0.72);
            text-decoration: none;
            font-weight: 500;
            font-size: 13px;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            transition: color 0.2s ease;
        }
        .landing-nav a:hover {
            color: var(--accent);
        }
        .btn-cta {
            background: var(--accent);
            color: #090907 !important;
            padding: 10px 20px;
            border-radius: 4px;
            font-weight: 600 !important;
            text-transform: uppercase;
            font-size: 12px;
            letter-spacing: 0.08em;
            transition: all 0.2s ease;
            border: 1px solid var(--accent);
        }
        .btn-cta:hover {
            background: transparent;
            color: var(--accent) !important;
            box-shadow: 0 0 16px rgba(154, 123, 86, 0.3);
        }

        /* Hero Section */
        .hero-section {
            min-height: 100vh;
            padding: 140px 4% 80px;
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            align-items: center;
            gap: 60px;
            position: relative;
        }
        .hero-section::before {
            content: '';
            position: absolute;
            top: 20%;
            left: 50%;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(154, 123, 86, 0.08) 0%, transparent 70%);
            z-index: 0;
            pointer-events: none;
        }
        .hero-content {
            position: relative;
            z-index: 2;
        }
        .hero-kicker {
            font-family: var(--font-sans);
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.2em;
            color: var(--accent);
            margin-bottom: 16px;
        }
        .hero-content h1 {
            font-family: var(--font-serif);
            font-size: clamp(42px, 5vw, 68px);
            line-height: 1.1;
            font-weight: 400;
            margin: 0 0 24px 0;
            color: #F7F2EA;
            letter-spacing: -0.01em;
        }
        .hero-content p {
            font-size: 16px;
            line-height: 1.7;
            color: rgba(247, 242, 234, 0.68);
            margin-bottom: 36px;
            max-width: 580px;
        }
        .hero-actions {
            display: flex;
            gap: 16px;
        }
        .btn-secondary {
            background: transparent;
            color: #F7F2EA;
            padding: 12px 28px;
            border-radius: 4px;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 12px;
            letter-spacing: 0.08em;
            border: 1px solid rgba(247, 242, 234, 0.2);
            text-decoration: none;
            transition: all 0.2s ease;
        }
        .btn-secondary:hover {
            border-color: #F7F2EA;
            background: rgba(247, 242, 234, 0.05);
        }

        /* Hero Image/Frame */
        .hero-visual {
            position: relative;
            z-index: 2;
            display: flex;
            justify-content: center;
        }
        .frame-container {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid rgba(247, 242, 234, 0.08);
            padding: 16px;
            border-radius: 12px;
            box-shadow: 0 40px 100px rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(8px);
            max-width: 100%;
        }
        .frame-image {
            width: 100%;
            height: auto;
            max-height: 480px;
            object-fit: cover;
            border-radius: 6px;
            border: 1px solid rgba(0, 0, 0, 0.4);
            display: block;
        }
        .frame-caption {
            font-size: 11px;
            color: rgba(247, 242, 234, 0.4);
            margin-top: 12px;
            text-align: center;
            letter-spacing: 0.05em;
        }

        /* Features Section */
        .section-padding {
            padding: 100px 4%;
            border-top: 1px solid rgba(247, 242, 234, 0.06);
            position: relative;
        }
        .section-title-wrapper {
            max-width: 700px;
            margin-bottom: 60px;
        }
        .section-kicker {
            color: var(--accent);
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            margin-bottom: 12px;
            display: block;
        }
        .section-title {
            font-family: var(--font-serif);
            font-size: 40px;
            font-weight: 400;
            margin: 0;
            line-height: 1.2;
        }
        
        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 32px;
        }
        .feature-card {
            background: rgba(247, 242, 234, 0.02);
            border: 1px solid rgba(247, 242, 234, 0.06);
            padding: 32px;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        .feature-card:hover {
            border-color: rgba(154, 123, 86, 0.4);
            background: rgba(247, 242, 234, 0.04);
            transform: translateY(-4px);
        }
        .feature-icon {
            width: 40px;
            height: 40px;
            border: 1px solid var(--accent);
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--accent);
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 24px;
        }
        .feature-card h3 {
            font-family: var(--font-serif);
            font-size: 22px;
            font-weight: 400;
            margin: 0 0 12px 0;
            color: #F7F2EA;
        }
        .feature-card p {
            font-size: 13px;
            line-height: 1.6;
            color: rgba(247, 242, 234, 0.6);
            margin: 0;
        }

        /* Showcase / Slider Section */
        .showcase-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 24px;
        }
        .showcase-item {
            position: relative;
            border-radius: 8px;
            overflow: hidden;
            border: 1px solid rgba(247, 242, 234, 0.06);
            aspect-ratio: 4/3;
            group: hover;
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
            background: linear-gradient(to top, rgba(9,9,7,0.9) 0%, rgba(9,9,7,0.2) 60%, transparent 100%);
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            padding: 24px;
            opacity: 0.9;
            transition: opacity 0.3s ease;
        }
        .showcase-tag {
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            color: var(--accent);
            font-weight: 600;
            margin-bottom: 6px;
        }
        .showcase-title {
            font-family: var(--font-serif);
            font-size: 18px;
            color: #F7F2EA;
            margin: 0 0 4px 0;
        }
        .showcase-desc {
            font-size: 11px;
            color: rgba(247, 242, 234, 0.5);
            margin: 0;
        }

        /* Login Container Section */
        .login-section {
            background: radial-gradient(circle at center, #141310 0%, #090907 100%);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
        }
        .login-section .auth-card {
            text-align: left;
            margin-top: 24px;
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
            <div class="frame-container">
                <img src="assets/auth/gallery-main.jpg" alt="Exhibition Room Mockup" class="frame-image">
                <div class="frame-caption">Sala de Exhibición Coleccionista — Generada con Cámara Angular 3/4</div>
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
                <div class="feature-icon">📐</div>
                <h3>Invarianza de Escala Real</h3>
                <p>El motor calcula el tamaño físico real de tu lienzo (ej. 120 x 90 cm) y lo escala proporcionalmente respecto a techos, sillones y ventanas. Olvídate de obras que se ven gigantes o diminutas.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">🕯️</div>
                <h3>Luz de Galería Natural</h3>
                <p>Nuestra IA proyecta sombras de caída suave y focos de riel sobre el lienzo, simulando de manera precisa la textura física, brillos y empastes de la pintura original sobre la pared.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">📸</div>
                <h3>Cámaras Editoriales</h3>
                <p>Elige entre planos directos para previsualización corporativa, perspectivas oblicuas a 3/4 que dan profundidad o espectaculares vistas desde ángulo Nadir (suelo-techo).</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">🎞️</div>
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
                <img src="assets/auth/gallery-main.jpg" alt="Main Room Display">
                <div class="showcase-overlay">
                    <span class="showcase-tag">Plano Principal (Frontal)</span>
                    <h3 class="showcase-title">Salón de Coleccionista</h3>
                    <p class="showcase-desc">Perfecto para mostrar equilibrio de color y proporciones amplias.</p>
                </div>
            </div>
            <div class="showcase-item">
                <img src="assets/auth/gallery-side.jpg" alt="Oblique Loft View">
                <div class="showcase-overlay">
                    <span class="showcase-tag">Plano Oblicuo (3/4 Izquierda)</span>
                    <h3 class="showcase-title">Estudio de Techo Alto</h3>
                    <p class="showcase-desc">Muestra la obra integrada con la profundidad del espacio habitable.</p>
                </div>
            </div>
            <div class="showcase-item">
                <img src="assets/auth/gallery-detail.jpg" alt="Close-up Texture Detail">
                <div class="showcase-overlay">
                    <span class="showcase-tag">Plano de Detalle (Macro)</span>
                    <h3 class="showcase-title">Grano y Textura</h3>
                    <p class="showcase-desc">Resalta el relieve físico y la fidelidad del bastidor pictórico.</p>
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

            <p style="margin-top: 20px; font-size: 13px; color: rgba(247, 242, 234, 0.5);">
                ¿No tienes una cuenta? <a href="register.php" style="color: var(--accent); text-decoration: underline;">Regístrate aquí</a>
            </p>
        </div>
    </section>

    <!-- Footer -->
    <footer style="padding: 40px 4%; border-top: 1px solid rgba(247, 242, 234, 0.06); text-align: center; font-size: 12px; color: rgba(247, 242, 234, 0.3);">
        <p>&copy; 2026 The Artwork Curator. Todos los derechos reservados. Tecnología impulsada por Vertex AI & Gemini.</p>
    </footer>

</body>
</html>

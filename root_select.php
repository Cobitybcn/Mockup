<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$currentUser = Auth::requireUser();
$isAdmin = Auth::isAdmin($currentUser);

$job = basename((string)($_GET['job'] ?? ''));

if ($job === '') {
    // Smart redirect: find the latest pending job for this user
    $db = Database::connection();
    $stmt = $db->prepare("SELECT job_id FROM artworks WHERE user_id = :user_id AND status = 'awaiting_selection' ORDER BY created_at DESC LIMIT 1");
    $stmt->execute(['user_id' => $currentUser['id']]);
    $latestPending = $stmt->fetch();
    if ($latestPending && !empty($latestPending['job_id'])) {
        header('Location: root_select.php?job=' . urlencode((string)$latestPending['job_id']));
        exit;
    } else {
        // No pending selection, redirect to root_album.php's pending section
        header('Location: root_album.php#pendientes');
        exit;
    }
}

$jobDir = __DIR__ . '/jobs/' . $job;
$statusFile = $jobDir . '/status.json';

if (!is_file($statusFile)) {
    die('Job not found.');
}

$status = json_decode((string)file_get_contents($statusFile), true);

if (!is_array($status)) {
    die('Could not load job state.');
}

if ((int)($status['user_id'] ?? 0) !== (int)$currentUser['id']) {
    http_response_code(403);
    die('You do not have access to this job.');
}

if (($status['status'] ?? '') !== 'done') {
    header('Location: waiting.php?job=' . urlencode($job));
    exit;
}

$candidates = $status['candidates'] ?? [];
$candidateCount = count((array)$candidates);
$originalFile = $status['main_file'] ?? '';
$originalUrl = 'job_media.php?job=' . rawurlencode($job) . '&file=' . rawurlencode((string)$originalFile);

$albumSlides = [];
try {
    $pdo = Database::connection();
    $randomOrder = Database::randomOrderSql();
    $stmt = $pdo->prepare("
        SELECT root_file
        FROM artworks
        WHERE user_id = :user_id
        AND root_file IS NOT NULL
        AND root_file != ''
        ORDER BY {$randomOrder}
        LIMIT 12
    ");
    $stmt->execute(['user_id' => (int)$currentUser['id']]);

    foreach ($stmt->fetchAll() as $row) {
        $file = basename((string)($row['root_file'] ?? ''));
        if ($file !== '' && is_file(RESULTS_DIR . DIRECTORY_SEPARATOR . $file)) {
            $albumSlides[$file] = 'media.php?file=' . rawurlencode($file) . '&thumb=1&w=640';
        }
    }

    if (count($albumSlides) < 8) {
        $fallbackFiles = [];
        foreach (['*mockup*.jpg', '*mockup*.jpeg', '*mockup*.png', 'base_artwork*.png', 'base_artwork*.jpg'] as $pattern) {
            $fallbackFiles = array_merge($fallbackFiles, glob(RESULTS_DIR . DIRECTORY_SEPARATOR . $pattern) ?: []);
        }
        shuffle($fallbackFiles);
        foreach ($fallbackFiles as $path) {
            $file = basename($path);
            if (!isset($albumSlides[$file])) {
                $albumSlides[$file] = 'media.php?file=' . rawurlencode($file) . '&thumb=1&w=640';
            }
            if (count($albumSlides) >= 12) {
                break;
            }
        }
    }
} catch (Throwable $e) {
    $albumSlides = [];
}

$albumSlides = array_values($albumSlides);
shuffle($albumSlides);

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Select Root Image - Artwork Mockups</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;0,700;1,400;1,500&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <style>
        :root {
            --font-serif: 'Cormorant Garamond', Georgia, serif;
            --font-sans: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            --gal-bg: #FAF9F6;
            --gal-surface: #FFFFFF;
            --gal-surface-soft: #F4F3EE;
            --gal-border: #E5E3DD;
            --gal-ink: #141412;
            --gal-muted: #7A7872;
            --gal-accent: #9A7B56;
            --gal-accent-light: #F6F3EE;
            --gal-accent-hover: #7E6342;
            --gal-shadow: 0 4px 30px rgba(20, 20, 18, 0.02);
            --gal-shadow-hover: 0 20px 48px rgba(20, 20, 18, 0.05);
            --gal-radius: 4px;
        }

        body {
            margin: 0;
            padding: 0;
            font-family: var(--font-sans);
            background: var(--gal-bg);
            color: var(--gal-ink);
            line-height: 1.6;
        }

        .workspace {
            padding: 30px 40px 54px;
            max-width: 1440px;
            margin: 0 auto;
        }

        h1 {
            font-family: var(--font-serif);
            font-size: 38px;
            font-weight: 500;
            margin: 0 0 8px;
            letter-spacing: -0.01em;
        }

        .page-kicker {
            font-size: 15px;
            color: var(--gal-muted);
            margin: 0 0 32px;
            max-width: 800px;
            font-weight: 300;
        }

        /* Layout Grid */
        .selection-layout {
            display: grid;
            grid-template-columns: 320px 1fr;
            gap: 48px;
            align-items: start;
        }

        /* Original Reference */
        .reference-panel {
            background: var(--gal-surface);
            border: 1px solid var(--gal-border);
            padding: 20px;
            border-radius: var(--gal-radius);
            box-shadow: var(--gal-shadow);
        }

        .reference-panel h3 {
            font-family: var(--font-serif);
            font-size: 20px;
            margin: 0 0 16px;
            font-weight: 500;
            border-bottom: 1px solid var(--gal-border);
            padding-bottom: 10px;
        }

        .reference-img {
            width: 100%;
            height: auto;
            border-radius: 2px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.03);
            display: block;
        }

        /* Candidates Grid */
        .candidates-area h2 {
            font-family: var(--font-serif);
            font-size: 24px;
            font-weight: 500;
            margin: 0 0 20px;
            border-bottom: 1px solid var(--gal-border);
            padding-bottom: 12px;
        }

        .candidates-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 24px;
        }

        .candidate-card {
            background: var(--gal-surface);
            border: 1px solid var(--gal-border);
            border-radius: var(--gal-radius);
            padding: 18px;
            display: flex;
            flex-direction: column;
            box-shadow: var(--gal-shadow);
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            cursor: pointer;
        }

        .candidate-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--gal-shadow-hover);
            border-color: var(--gal-accent);
        }

        .candidate-card.selected-active {
            border-color: var(--gal-accent);
            background: var(--gal-accent-light);
        }

        .candidate-num {
            font-size: 9px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            color: var(--gal-accent);
            margin-bottom: 8px;
        }

        .candidate-frame {
            width: 100%;
            aspect-ratio: 4 / 5;
            overflow: hidden;
            background: var(--gal-surface-soft);
            border: 1px solid var(--gal-border);
            border-radius: 2px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .candidate-frame img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            display: block;
            transition: transform 0.3s ease;
        }

        .candidate-card:hover .candidate-frame img {
            transform: scale(1.02);
        }

        .select-btn {
            width: 100%;
            border: 1px solid var(--gal-accent);
            background: var(--gal-surface);
            color: var(--gal-accent);
            padding: 10px 16px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            cursor: pointer;
            border-radius: var(--gal-radius);
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            margin-top: auto;
        }

        .candidate-card:hover .select-btn {
            background: var(--gal-accent);
            color: var(--gal-surface);
        }

        /* Overlay loader during analysis */
        .global-loader {
            position: fixed;
            inset: 0;
            box-sizing: border-box;
            background: #080807;
            display: none;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            color: #f7f2ea;
            overflow: hidden;
            zoom: 1;
            padding: 18px 24px;
        }

        .global-loader::before {
            content: "";
            position: absolute;
            inset: 0;
            background:
                radial-gradient(circle at 18% 22%, rgba(25, 82, 91, 0.45), transparent 32%),
                radial-gradient(circle at 78% 18%, rgba(121, 42, 48, 0.26), transparent 31%),
                radial-gradient(circle at 58% 76%, rgba(154, 123, 86, 0.30), transparent 34%),
                linear-gradient(126deg, rgba(4, 7, 8, 0.96), rgba(8, 8, 7, 0.92) 38%, rgba(19, 31, 31, 0.82) 100%),
                linear-gradient(118deg, transparent 0 18%, rgba(255, 255, 255, 0.045) 18% 18.2%, transparent 18.2% 46%, rgba(214, 178, 122, 0.055) 46% 46.25%, transparent 46.25%),
                linear-gradient(90deg, rgba(255, 255, 255, 0.028) 0 1px, transparent 1px 100%),
                linear-gradient(0deg, rgba(255, 255, 255, 0.018) 0 1px, transparent 1px 100%);
            background-size: 100% 100%, 112px 112px, 112px 112px;
            opacity: 0.72;
            animation: loaderGradientDrift 18s ease-in-out infinite alternate;
            pointer-events: none;
        }

        .global-loader.active {
            display: flex;
        }

        .loader-album-stage {
            position: relative;
            z-index: 2;
            flex: 1 1 auto;
            width: 100%;
            min-height: 0;
            overflow: hidden;
            display: flex;
            align-items: center;
            padding: clamp(8px, 2.4vw, 24px) 0 clamp(10px, 2vw, 18px);
            box-sizing: border-box;
        }

        .loader-album-stage::before {
            content: "";
            position: absolute;
            pointer-events: none;
            width: min(72vw, 980px);
            height: min(42vh, 420px);
            border: 1px solid rgba(255, 255, 255, 0.10);
            background: rgba(255, 255, 255, 0.018);
            transform: translateX(-8vw) rotate(-5deg);
            mix-blend-mode: screen;
        }

        .loader-album-stage::after {
            content: "";
            position: fixed;
            inset: 0;
            background:
                radial-gradient(circle at center, rgba(0, 0, 0, 0.02), rgba(0, 0, 0, 0.58)),
                linear-gradient(180deg, rgba(0, 0, 0, 0.08), rgba(0, 0, 0, 0.70));
            pointer-events: none;
        }

        .loader-album-track {
            display: flex;
            align-items: center;
            gap: clamp(22px, 3vw, 44px);
            width: max-content;
            height: 100%;
            animation: loaderAlbumScrollLeft 58s linear infinite;
            position: relative;
            z-index: 1;
        }

        .loader-album-slide {
            flex: 0 0 auto;
            max-width: min(34vw, 540px);
            max-height: 86%;
            width: auto;
            height: auto;
            object-fit: contain;
            filter: drop-shadow(0 24px 72px rgba(0, 0, 0, 0.62));
            opacity: 0.94;
        }

        @keyframes loaderAlbumScrollLeft {
            from { transform: translateX(0); }
            to { transform: translateX(-50%); }
        }

        .loader-album-empty {
            width: min(76vw, 980px);
            max-height: calc(100% - 24px);
            aspect-ratio: 16 / 10;
            border: 1px solid rgba(255, 255, 255, 0.16);
            background: linear-gradient(135deg, #151311, #28231d);
            box-shadow: 0 30px 90px rgba(0, 0, 0, 0.55);
        }

        .loader-status-panel {
            width: min(560px, calc(100vw - 48px));
            background: linear-gradient(135deg, rgba(18, 16, 14, 0.82), rgba(10, 9, 8, 0.68));
            border: 1px solid rgba(214, 178, 122, 0.20);
            border-radius: 8px;
            padding: 14px 16px 13px;
            text-align: center;
            box-shadow: 0 24px 70px rgba(0, 0, 0, 0.42), inset 0 1px 0 rgba(255, 255, 255, 0.06);
            backdrop-filter: blur(14px);
            position: relative;
            z-index: 3;
            margin: 0;
        }

        .spinner {
            width: 40px;
            height: 40px;
            border: 3px solid rgba(255, 255, 255, 0.22);
            border-top-color: #d6b27a;
            border-radius: 50%;
            animation: spin 0.85s linear infinite;
            margin: 0 auto 8px;
        }

        .loader-text {
            font-family: var(--font-serif);
            font-size: clamp(18px, 1.8vw, 23px);
            color: #f7f2ea;
            text-align: center;
        }

        .loader-sub {
            font-size: 14px;
            color: rgba(247, 242, 234, 0.74);
            margin-top: 6px;
        }

        .loader-tip {
            min-height: 38px;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid rgba(247, 242, 234, 0.13);
            color: rgba(247, 242, 234, 0.72);
            font-size: 12px;
            line-height: 1.45;
        }

        .loader-tip strong {
            color: #e2c183;
            font-weight: 600;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        @keyframes loaderGradientDrift {
            from { filter: saturate(0.95) brightness(0.88); transform: scale(1); }
            to { filter: saturate(1.16) brightness(1.02); transform: scale(1.035); }
        }

        @media (max-width: 900px) {
            .selection-layout {
                grid-template-columns: 1fr;
                gap: 32px;
            }

            .loader-album-slide {
                max-width: 86vw;
            }
        }

        /* Lightbox styling */
        .zoom-trigger-btn:hover {
            background: var(--gal-accent) !important;
            border-color: var(--gal-accent) !important;
            transform: scale(1.1);
        }
        .zoom-trigger-btn:hover svg {
            stroke: var(--gal-surface) !important;
        }
        .lightbox-modal {
            position: fixed;
            inset: 0;
            background: rgba(20, 20, 18, 0.95);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            backdrop-filter: blur(8px);
            opacity: 0;
            transition: opacity 0.3s ease;
            zoom: 1;
        }
        .lightbox-modal.active {
            display: flex;
            opacity: 1;
        }
        .lightbox-content {
            position: relative;
            width: 96vw;
            height: 94vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        .lightbox-content img {
            max-width: 100%;
            max-height: 90vh;
            object-fit: contain;
            border: 2px solid var(--gal-surface);
            box-shadow: 0 10px 50px rgba(0,0,0,0.5);
            border-radius: 2px;
        }
        .lightbox-close {
            position: absolute;
            top: 16px;
            right: 18px;
            background: transparent;
            border: none;
            color: var(--gal-surface);
            font-size: 36px;
            cursor: pointer;
            transition: color 0.2s;
            line-height: 1;
        }
        .lightbox-close:hover {
            color: var(--gal-accent);
        }
        .lightbox-caption {
            color: var(--gal-surface);
            font-family: var(--font-serif);
            font-size: 18px;
            margin-top: 12px;
            letter-spacing: 0.05em;
        }

        .global-loader {
            background: #020202;
        }

        .global-loader::before {
            background:
                radial-gradient(ellipse at 24% 22%, rgba(161, 124, 73, 0.22), transparent 38%),
                radial-gradient(ellipse at 76% 28%, rgba(74, 48, 33, 0.24), transparent 42%),
                radial-gradient(ellipse at 48% 78%, rgba(187, 159, 108, 0.13), transparent 46%),
                linear-gradient(120deg, #010101 0%, #090704 42%, #030303 100%);
            background-size: 150% 150%;
            opacity: 1;
            animation: loaderSepiaBreath 28s ease-in-out infinite alternate;
        }

        .loader-album-stage {
            position: absolute;
            inset: 0;
            z-index: 1;
            display: block;
            width: auto;
            padding: 0;
            overflow: hidden;
        }

        .loader-album-stage::before,
        .loader-album-stage::after {
            border: 0;
            background:
                radial-gradient(ellipse at center, rgba(222, 193, 138, 0.13), transparent 58%);
            filter: blur(24px);
            opacity: 0.36;
        }

        .loader-album-stage::before {
            width: 70vw;
            height: 60vh;
            left: -12vw;
            top: 2vh;
            transform: rotate(-18deg);
        }

        .loader-album-stage::after {
            width: 58vw;
            height: 54vh;
            right: -16vw;
            bottom: -8vh;
            transform: rotate(14deg);
        }

        .loader-album-track {
            position: absolute;
            display: flex;
            align-items: center;
            gap: clamp(70px, 12vw, 180px);
            width: max-content;
            height: auto;
            animation: none;
            will-change: transform;
        }

        .loader-album-track.dream-a {
            top: 12vh;
            left: -34vw;
            animation: loaderDreamDriftA 112s ease-in-out infinite alternate;
        }

        .loader-album-track.dream-b {
            top: 43vh;
            right: -42vw;
            animation: loaderDreamDriftB 146s ease-in-out infinite alternate;
        }

        .loader-album-track.dream-c {
            bottom: 6vh;
            left: -18vw;
            animation: loaderDreamDriftC 128s ease-in-out infinite alternate;
        }

        .loader-album-track.dream-d {
            top: -24vh;
            left: 10vw;
            flex-direction: column;
            animation: loaderDreamColumnDown 168s ease-in-out infinite alternate;
        }

        .loader-album-track.dream-e {
            bottom: -34vh;
            right: 14vw;
            flex-direction: column;
            animation: loaderDreamColumnUp 188s ease-in-out infinite alternate;
        }

        .loader-album-track.dream-f {
            top: 4vh;
            left: -86vw;
            transform-origin: center;
            animation: loaderDreamWideRiver 206s ease-in-out infinite alternate;
        }

        .loader-album-track.dream-g {
            top: 64vh;
            right: -62vw;
            animation: loaderDreamSmallCounter 156s ease-in-out infinite alternate;
        }

        .loader-album-track.dream-h {
            top: 28vh;
            left: -124vw;
            animation: loaderDreamMonumentalMist 236s ease-in-out infinite alternate;
        }

        .loader-album-slide {
            max-width: min(28vw, 430px);
            max-height: 46vh;
            filter: sepia(0.92) saturate(0.55) contrast(0.9) brightness(0.78) blur(0.15px) drop-shadow(0 30px 72px rgba(0, 0, 0, 0.72));
            opacity: 0.34;
            mix-blend-mode: screen;
            border-radius: 1px;
        }

        .loader-album-track.dream-b .loader-album-slide {
            max-width: min(24vw, 360px);
            opacity: 0.24;
            filter: sepia(1) saturate(0.45) contrast(0.86) brightness(0.72) blur(0.45px) drop-shadow(0 30px 70px rgba(0, 0, 0, 0.78));
        }

        .loader-album-track.dream-c .loader-album-slide {
            max-width: min(20vw, 320px);
            opacity: 0.18;
            filter: sepia(0.98) saturate(0.4) contrast(0.82) brightness(0.68) blur(0.7px) drop-shadow(0 24px 64px rgba(0, 0, 0, 0.78));
        }

        .loader-album-track.dream-d .loader-album-slide {
            max-width: min(18vw, 280px);
            max-height: 36vh;
            opacity: 0.20;
            filter: sepia(1) saturate(0.35) contrast(0.8) brightness(0.66) blur(0.8px);
        }

        .loader-album-track.dream-e .loader-album-slide {
            max-width: min(16vw, 240px);
            max-height: 34vh;
            opacity: 0.16;
            filter: invert(1) sepia(0.8) saturate(0.28) contrast(0.88) brightness(0.58) blur(0.6px);
            mix-blend-mode: lighten;
        }

        .loader-album-track.dream-f .loader-album-slide {
            max-width: min(56vw, 860px);
            max-height: 58vh;
            opacity: 0.11;
            filter: sepia(0.9) saturate(0.3) contrast(0.78) brightness(0.62) blur(1px);
        }

        .loader-album-track.dream-g .loader-album-slide {
            max-width: min(14vw, 210px);
            max-height: 28vh;
            opacity: 0.28;
            filter: sepia(1) saturate(0.5) contrast(0.9) brightness(0.76) blur(0.25px);
        }

        .loader-album-track.dream-h .loader-album-slide {
            max-width: min(82vw, 1240px);
            max-height: 76vh;
            opacity: 0.075;
            filter: invert(1) sepia(0.65) saturate(0.2) contrast(0.74) brightness(0.52) blur(1.4px);
            mix-blend-mode: screen;
        }

        .loader-album-slide:nth-child(3n + 1) {
            transform: rotate(-5deg) scale(0.86);
        }

        .loader-album-slide:nth-child(3n + 2) {
            transform: rotate(4deg) scale(1.06);
        }

        .loader-album-slide:nth-child(3n) {
            transform: rotate(-1deg) scale(0.96);
        }

        .loader-status-panel {
            position: absolute;
            right: 22px;
            bottom: 18px;
            width: min(330px, calc(100vw - 44px));
            padding: 8px 10px;
            opacity: 0.24;
            transition: opacity 0.35s ease;
            background: rgba(8, 6, 4, 0.22);
            border-color: rgba(226, 193, 131, 0.13);
            box-shadow: none;
        }

        .loader-status-panel:hover,
        .loader-status-panel:focus-within {
            opacity: 0.88;
        }

        .spinner {
            width: 18px;
            height: 18px;
            border-width: 2px;
            margin-bottom: 4px;
        }

        .loader-text {
            font-size: 14px;
            margin: 0;
        }

        .loader-sub,
        .loader-tip {
            display: none;
        }

        @keyframes loaderDreamDriftA {
            0% { transform: translate3d(-4vw, 0, 0); }
            38% { transform: translate3d(24vw, 7vh, 0); }
            72% { transform: translate3d(52vw, -3vh, 0); }
            100% { transform: translate3d(78vw, 5vh, 0); }
        }

        @keyframes loaderDreamDriftB {
            0% { transform: translate3d(18vw, 0, 0); }
            45% { transform: translate3d(-18vw, -8vh, 0); }
            100% { transform: translate3d(-64vw, 4vh, 0); }
        }

        @keyframes loaderDreamDriftC {
            0% { transform: translate3d(0, 5vh, 0); }
            35% { transform: translate3d(32vw, -6vh, 0); }
            68% { transform: translate3d(58vw, 2vh, 0); }
            100% { transform: translate3d(84vw, -9vh, 0); }
        }

        @keyframes loaderDreamColumnDown {
            0% { transform: translate3d(0, -42vh, 0) rotate(-3deg); }
            40% { transform: translate3d(6vw, 8vh, 0) rotate(2deg); }
            100% { transform: translate3d(-4vw, 78vh, 0) rotate(-1deg); }
        }

        @keyframes loaderDreamColumnUp {
            0% { transform: translate3d(0, 46vh, 0) rotate(4deg); }
            52% { transform: translate3d(-7vw, -10vh, 0) rotate(-2deg); }
            100% { transform: translate3d(5vw, -82vh, 0) rotate(1deg); }
        }

        @keyframes loaderDreamWideRiver {
            0% { transform: translate3d(-18vw, -4vh, 0) scale(2); }
            48% { transform: translate3d(28vw, 9vh, 0) scale(2); }
            100% { transform: translate3d(72vw, -2vh, 0) scale(2); }
        }

        @keyframes loaderDreamSmallCounter {
            0% { transform: translate3d(34vw, 2vh, 0) scale(0.5); }
            45% { transform: translate3d(-4vw, -7vh, 0) scale(0.5); }
            100% { transform: translate3d(-52vw, 5vh, 0) scale(0.5); }
        }

        @keyframes loaderDreamMonumentalMist {
            0% { transform: translate3d(-26vw, -12vh, 0) scale(3); }
            54% { transform: translate3d(18vw, 4vh, 0) scale(3); }
            100% { transform: translate3d(62vw, -6vh, 0) scale(3); }
        }

        @keyframes loaderSepiaBreath {
            from { background-position: 0% 50%; filter: brightness(0.78) saturate(0.82); }
            to { background-position: 100% 48%; filter: brightness(0.98) saturate(1.08); }
        }

        @media (max-width: 640px) {
            .loader-album-slide,
            .loader-album-track.dream-b .loader-album-slide,
            .loader-album-track.dream-c .loader-album-slide {
                max-width: 68vw;
            }

            .loader-status-panel {
                right: 12px;
                bottom: 12px;
            }
        }

        .global-loader {
            background: var(--gal-bg);
        }

        .global-loader::before,
        .loader-album-stage {
            display: none;
        }

        .loader-status-panel {
            position: relative;
            inset: auto;
            width: min(360px, calc(100vw - 48px));
            padding: 26px 24px;
            opacity: 1;
            text-align: center;
            background: transparent;
            border: 0;
            box-shadow: none;
        }

        .spinner {
            width: 34px;
            height: 34px;
            margin: 0 auto 18px;
            border-width: 2px;
        }

        .loader-text {
            margin: 0 0 8px;
            color: var(--gal-ink);
            font-size: 22px;
        }

        .loader-sub {
            display: block;
            margin: 0;
            color: var(--gal-muted);
            font-size: 13px;
        }

        .loader-tip {
            display: none;
        }

        .admin-overlay-prompts {
            position: fixed;
            top: 24px;
            right: 24px;
            bottom: 24px;
            z-index: 10001;
            width: min(560px, calc(100vw - 48px));
            overflow: auto;
            padding: 16px;
            background: rgba(255, 255, 255, 0.96);
            border: 1px solid var(--gal-border);
            border-radius: 8px;
            box-shadow: 0 20px 70px rgba(20, 20, 18, 0.16);
            text-align: left;
            display: none;
        }

        .global-loader.active .admin-overlay-prompts {
            display: block;
        }

        .admin-overlay-prompts h3 {
            margin: 0 0 4px;
            font-family: var(--font-serif);
            font-size: 22px;
            font-weight: 500;
            color: var(--gal-ink);
        }

        .admin-overlay-prompts > p,
        .admin-overlay-empty {
            margin: 0 0 14px;
            color: var(--gal-muted);
            font-size: 12px;
            line-height: 1.45;
        }

        [data-typewriter].is-typing::after {
            content: "";
            display: inline-block;
            width: 1px;
            height: 1em;
            margin-left: 2px;
            background: currentColor;
            vertical-align: -0.12em;
            animation: typewriterCaret 0.75s steps(1) infinite;
        }

        @keyframes typewriterCaret {
            0%, 48% { opacity: 1; }
            49%, 100% { opacity: 0; }
        }

        @media (prefers-reduced-motion: reduce) {
            [data-typewriter].is-typing::after {
                display: none;
            }
        }

        .admin-overlay-prompt {
            margin-top: 10px;
            border: 1px solid var(--gal-border);
            border-radius: 6px;
            background: var(--gal-bg);
            overflow: hidden;
        }

        .admin-overlay-prompt summary {
            margin: 0;
            padding: 10px 12px;
            color: var(--gal-ink);
            font-size: 12px;
            font-weight: 600;
            list-style-position: inside;
        }

        .admin-overlay-meta {
            display: grid;
            gap: 3px;
            padding: 0 12px 10px;
            color: var(--gal-muted);
            font-size: 11px;
            line-height: 1.35;
        }

        .admin-overlay-actions {
            display: flex;
            justify-content: flex-end;
            padding: 0 12px 10px;
        }

        .admin-overlay-actions button {
            width: auto;
            margin: 0;
            padding: 7px 10px;
            font-size: 11px;
        }

        .admin-overlay-prompt textarea {
            display: block;
            width: calc(100% - 24px);
            min-height: 260px;
            margin: 0 12px 12px;
            padding: 10px;
            resize: vertical;
            border: 1px solid var(--gal-border);
            border-radius: 4px;
            background: #fbfaf7;
            color: var(--gal-ink);
            font-family: Consolas, Monaco, monospace;
            font-size: 11px;
            line-height: 1.45;
            box-sizing: border-box;
        }
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
            Candidate Selection: Review the generated versions and choose the most frontal, clean and cropped image to act as the official root.
        </div>

        <div class="workspace">
            <div class="workspace-header" style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 24px; border-bottom: 1px solid var(--gal-border); padding-bottom: 16px;">
                <div>
                    <h1 style="margin: 0;">Select Root Image Version</h1>
                    <p style="margin: 6px 0 0 0; font-size: 14px; color: var(--gal-muted);">We generated <?= h($candidateCount) ?> candidates of your root image to prevent rate/crop errors. Select the best one to proceed.</p>
                </div>
                <div class="topbar-actions" style="display: flex; gap: 12px; margin-bottom: 4px;">
                    <a class="button-link secondary" href="waiting.php?action=cancel&job=<?= urlencode($job) ?>">Cancel Upload</a>
                    <a class="button-link secondary" href="root_album.php">ArtWorks</a>
                </div>
            </div>

            <div class="selection-layout">
                <!-- Left panel: Original reference image -->
                <aside class="reference-panel">
                    <h3>Original Upload</h3>
                    <img class="reference-img" src="<?= h($originalUrl) ?>" alt="Original uploaded image">
                </aside>

                <!-- Right area: Candidate selector -->
                <section class="candidates-area">
                    <h2><?= h($candidateCount) ?> Candidates Generated</h2>

                    <div class="candidates-grid">
                        <?php foreach ($candidates as $idx => $candidate): ?>
                            <?php $candidateUrl = 'job_media.php?job=' . rawurlencode($job) . '&file=' . rawurlencode((string)$candidate); ?>
                            <div class="candidate-card" id="card_<?= $idx ?>" onclick="selectCandidate('<?= h($candidate) ?>', <?= $idx ?>)">
                                <div class="candidate-num">Version <?= $idx + 1 ?></div>
                                <div class="candidate-frame-wrapper" style="position: relative; margin-bottom: 16px;">
                                    <div class="candidate-frame" style="margin-bottom: 0;">
                                        <img src="<?= h($candidateUrl) ?>" alt="Candidate Version <?= $idx + 1 ?>">
                                    </div>
                                    <button type="button" class="zoom-trigger-btn" onclick="openLightbox('<?= h($candidateUrl) ?>', 'Version <?= $idx + 1 ?>'); event.stopPropagation();" title="Zoom in / check details" style="position: absolute; right: 10px; top: 10px; background: rgba(255,255,255,0.95); border: 1px solid var(--gal-border); border-radius: 50%; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.2s; box-shadow: var(--gal-shadow); z-index: 10;">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--gal-ink);"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line><line x1="11" y1="8" x2="11" y2="14"></line><line x1="8" y1="11" x2="14" y2="11"></line></svg>
                                    </button>
                                </div>
                                <button type="button" class="select-btn" id="btn_<?= $idx ?>">
                                    Select Version <?= $idx + 1 ?>
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            </div>
        </div>
    </main>
</div>

<!-- Lightbox Modal -->
<div class="lightbox-modal" id="lightboxModal" onclick="closeLightbox()">
    <div class="lightbox-content" onclick="event.stopPropagation()">
        <button class="lightbox-close" onclick="closeLightbox()">&times;</button>
        <img id="lightboxImage" src="" alt="Zoomed candidate">
        <div id="lightboxCaption" class="lightbox-caption"></div>
    </div>
</div>

<!-- Loading overlay -->
<div class="global-loader" id="globalLoader">
    <div class="loader-album-stage" aria-hidden="true">
        <?php if (!empty($albumSlides)): ?>
            <div class="loader-album-track dream-a">
                <?php foreach (array_merge($albumSlides, $albumSlides) as $slideUrl): ?>
                    <img class="loader-album-slide" src="<?= h($slideUrl) ?>" alt="">
                <?php endforeach; ?>
            </div>
            <div class="loader-album-track dream-b">
                <?php foreach (array_merge(array_reverse($albumSlides), array_reverse($albumSlides)) as $slideUrl): ?>
                    <img class="loader-album-slide" src="<?= h($slideUrl) ?>" alt="">
                <?php endforeach; ?>
            </div>
            <div class="loader-album-track dream-c">
                <?php foreach (array_merge($albumSlides, array_reverse($albumSlides)) as $slideUrl): ?>
                    <img class="loader-album-slide" src="<?= h($slideUrl) ?>" alt="">
                <?php endforeach; ?>
            </div>
            <div class="loader-album-track dream-d">
                <?php foreach (array_merge($albumSlides, $albumSlides) as $slideUrl): ?>
                    <img class="loader-album-slide" src="<?= h($slideUrl) ?>" alt="">
                <?php endforeach; ?>
            </div>
            <div class="loader-album-track dream-e">
                <?php foreach (array_merge(array_reverse($albumSlides), $albumSlides) as $slideUrl): ?>
                    <img class="loader-album-slide" src="<?= h($slideUrl) ?>" alt="">
                <?php endforeach; ?>
            </div>
            <div class="loader-album-track dream-f">
                <?php foreach (array_merge($albumSlides, $albumSlides) as $slideUrl): ?>
                    <img class="loader-album-slide" src="<?= h($slideUrl) ?>" alt="">
                <?php endforeach; ?>
            </div>
            <div class="loader-album-track dream-g">
                <?php foreach (array_merge(array_reverse($albumSlides), array_reverse($albumSlides)) as $slideUrl): ?>
                    <img class="loader-album-slide" src="<?= h($slideUrl) ?>" alt="">
                <?php endforeach; ?>
            </div>
            <div class="loader-album-track dream-h">
                <?php foreach (array_merge($albumSlides, array_reverse($albumSlides)) as $slideUrl): ?>
                    <img class="loader-album-slide" src="<?= h($slideUrl) ?>" alt="">
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="loader-album-empty"></div>
        <?php endif; ?>
    </div>
    <div class="loader-status-panel">
        <div class="spinner"></div>
        <div class="loader-text" data-typewriter data-typewriter-speed="28">Preparing Mockup Review...</div>
        <div class="loader-sub" data-typewriter data-typewriter-speed="18" data-typewriter-delay="720">Preparing direct mockup combinations from the selected root image.</div>
        <div class="loader-tip" id="loaderTip"></div>
    </div>
    <?php if ($isAdmin): ?>
        <aside class="admin-overlay-prompts" aria-label="Admin mockup prompts while analyzing">
            <h3 data-typewriter data-typewriter-speed="24" data-typewriter-delay="220">Admin - Mockup Prompts</h3>
            <p data-typewriter data-typewriter-speed="12" data-typewriter-delay="820">Prompts will appear here once direct combinations are prepared.</p>
            <div class="admin-overlay-empty" id="adminOverlayPromptEmpty" data-typewriter data-typewriter-speed="14" data-typewriter-delay="1560">No mockup prompts yet. Preparing direct world mother combinations...</div>
            <div class="admin-overlay-list" id="adminOverlayPromptList"></div>
        </aside>
    <?php endif; ?>
</div>

<script>
    const isAdmin = <?= $isAdmin ? 'true' : 'false' ?>;
    const adminPromptStatusUrl = 'admin_mockup_prompts_status.php?job=<?= rawurlencode($job) ?>';
    const reduceTypewriterMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function escapeHtml(value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function bindAdminPromptCopyButtons(scope = document) {
        scope.querySelectorAll('.admin-copy-overlay-prompt:not([data-bound="1"])').forEach((button) => {
            button.dataset.bound = '1';
            button.addEventListener('click', async () => {
                const target = document.getElementById(button.dataset.target);
                if (!target) return;

                target.select();
                target.setSelectionRange(0, target.value.length);

                try {
                    await navigator.clipboard.writeText(target.value);
                } catch (e) {
                    document.execCommand('copy');
                }

                const originalText = button.textContent;
                button.textContent = 'Copied';
                setTimeout(() => {
                    button.textContent = originalText;
                }, 1400);
            });
        });
    }

    function typewriterText(element) {
        if (!element || element.dataset.typewriterDone === '1') return;

        const originalText = element.dataset.typewriterText || element.textContent;
        if (!originalText) return;

        element.dataset.typewriterText = originalText;
        element.dataset.typewriterDone = '1';

        if (reduceTypewriterMotion) {
            element.textContent = originalText;
            return;
        }

        const speed = Number(element.dataset.typewriterSpeed || 18);
        const delay = Number(element.dataset.typewriterDelay || 0);
        let index = 0;

        element.textContent = '';
        element.classList.add('is-typing');

        window.setTimeout(() => {
            const writeNext = () => {
                index++;
                element.textContent = originalText.slice(0, index);

                if (index < originalText.length) {
                    window.setTimeout(writeNext, speed);
                    return;
                }

                element.classList.remove('is-typing');
            };

            writeNext();
        }, delay);
    }

    function runTypewriters(scope = document) {
        scope.querySelectorAll('[data-typewriter]').forEach(typewriterText);
    }

    async function pollAdminMockupPrompts() {
        if (!isAdmin) return;

        const list = document.getElementById('adminOverlayPromptList');
        const empty = document.getElementById('adminOverlayPromptEmpty');
        if (!list || !empty) return;

        try {
            const response = await fetch(adminPromptStatusUrl + '&t=' + Date.now(), {
                cache: 'no-store',
                headers: {'Accept': 'application/json'}
            });
            const data = await response.json();

            if (!response.ok || !data.ok) {
                throw new Error(data.error || 'Could not load admin prompts.');
            }

            if (!data.ready || !Array.isArray(data.prompts) || data.prompts.length === 0) {
                window.setTimeout(pollAdminMockupPrompts, 2500);
                return;
            }

            empty.style.display = 'none';
            list.innerHTML = data.prompts.map((item, index) => {
                const textareaId = `adminOverlayPrompt${index}`;
                return `
                    <details class="admin-overlay-prompt" ${index === 0 ? 'open' : ''}>
                        <summary>Proposal ${escapeHtml(item.number)} - ${escapeHtml(item.name)}</summary>
                        <div class="admin-overlay-meta">
                            <span>Context ID: ${escapeHtml(item.id)}</span>
                            <span>Purpose: ${escapeHtml(item.purpose)}</span>
                            <span>Camera: ${escapeHtml(item.camera)}</span>
                            <span>Time: ${escapeHtml(item.time)}</span>
                        </div>
                        <div class="admin-overlay-actions">
                            <button type="button" class="button secondary admin-copy-overlay-prompt" data-target="${textareaId}">Copy prompt</button>
                        </div>
                        <textarea id="${textareaId}" readonly>${escapeHtml(item.prompt)}</textarea>
                    </details>
                `;
            }).join('');
            bindAdminPromptCopyButtons(list);
        } catch (error) {
            delete empty.dataset.typewriterText;
            delete empty.dataset.typewriterDone;
            empty.textContent = error.message;
            typewriterText(empty);
            window.setTimeout(pollAdminMockupPrompts, 3500);
        }
    }

    function openLightbox(url, title) {
        const modal = document.getElementById('lightboxModal');
        const img = document.getElementById('lightboxImage');
        const cap = document.getElementById('lightboxCaption');
        
        img.src = url;
        cap.textContent = title;
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeLightbox() {
        const modal = document.getElementById('lightboxModal');
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }

    async function selectCandidate(filename, index) {
        // Highlight active card
        document.querySelectorAll('.candidate-card').forEach(c => c.classList.remove('selected-active'));
        document.getElementById('card_' + index).classList.add('selected-active');

        // Show loading overlay
        const loader = document.getElementById('globalLoader');
        loader.classList.add('active');
        runTypewriters(loader);
        pollAdminMockupPrompts();

        try {
            const formData = new FormData();
            formData.append('job', <?= json_encode($job) ?>);
            formData.append('filename', filename);

            const response = await fetch('select_root.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'Accept': 'application/json'
                }
            });

            const data = await response.json();

            if (!response.ok || !data.ok) {
                throw new Error(data.error || 'Could not select root image.');
            }

            // Redirect to step 2 (Curatorial direction)
            window.location.href = data.redirect;

        } catch (err) {
            alert('Error: ' + err.message);
            loader.classList.remove('active');
        }
    }

    const loaderTips = [
        ['Artist profile', 'A complete profile helps the system choose stronger interiors, audience and atmosphere.'],
        ['If mockups are not expected', 'Start by checking the selected root image: angle, crop and color fidelity matter most.'],
        ['Choosing the best context', 'Prioritize faithful artwork, believable scale and a room that supports rather than competes.'],
        ['Publication quality', 'Technique, dimensions and a concise statement make each artwork page more useful.']
    ];
    let loaderTipIndex = 0;

    function rotateLoaderTip() {
        const target = document.getElementById('loaderTip');
        if (!target) return;
        const tip = loaderTips[loaderTipIndex % loaderTips.length];
        target.innerHTML = '<strong>' + tip[0] + ':</strong> ' + tip[1];
        loaderTipIndex++;
    }

    setInterval(rotateLoaderTip, 6200);
    rotateLoaderTip();
</script>

</body>
</html>

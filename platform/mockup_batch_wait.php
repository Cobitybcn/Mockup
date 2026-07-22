<?php
// LEGACY / DO NOT USE IN PHASE 2.3 FLOW
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$currentUser = Auth::user();
if (!$currentUser) {
    header('Location: login.php');
    exit;
}
$isAdmin = Auth::isAdmin($currentUser);

$image = basename(trim((string)($_GET['image'] ?? '')));

if (!defined('LEGACY_MOCKUP_FLOW_ENABLED') || !LEGACY_MOCKUP_FLOW_ENABLED) {
    $resolvedId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
    if ($resolvedId <= 0 && $image !== '') {
        try {
            $stmt = Database::connection()->prepare("SELECT id FROM artworks WHERE root_file = :root_file LIMIT 1");
            $stmt->execute(['root_file' => $image]);
            $resolvedId = (int)$stmt->fetchColumn();
        } catch (Throwable $e) {}
    }
    if ($resolvedId > 0) {
        header('Location: mockup_prompt_drafts_review.php?id=' . $resolvedId);
        exit;
    }
}
if ($image === '') {
    header('Location: root_album.php');
    exit;
}

$reportUrl = 'report.php?image=' . rawurlencode($image) . '&auto=1';
$mockupCount = 0;
$albumSlides = [];

try {
    $pdo = Database::connection();
    $stmtArtwork = $pdo->prepare('SELECT id FROM artworks WHERE root_file = :root_file AND user_id = :user_id LIMIT 1');
    $stmtArtwork->execute([
        'root_file' => $image,
        'user_id' => (int)$currentUser['id'],
    ]);
    $artworkId = (int)($stmtArtwork->fetchColumn() ?: 0);
    if ($artworkId > 0) {
        $mockupCount = count(MockupBatchQueue::rowsForArtwork($artworkId));
    }

    $randomOrder = Database::randomOrderSql();
    $stmt = $pdo->prepare("
        SELECT mockup_file, artwork_file
        FROM mockups
        WHERE user_id = :user_id
        ORDER BY {$randomOrder}
        LIMIT 60
    ");
    $stmt->execute(['user_id' => (int)$currentUser['id']]);

    $seenArtworks = [];
    foreach ($stmt->fetchAll() as $row) {
        $artworkKey = basename((string)($row['artwork_file'] ?? ''));
        if ($artworkKey !== '' && isset($seenArtworks[$artworkKey])) {
            continue;
        }

        $file = basename((string)($row['mockup_file'] ?? ''));
        if ($file !== '' && is_file(RESULTS_DIR . DIRECTORY_SEPARATOR . $file)) {
            if ($artworkKey !== '') {
                $seenArtworks[$artworkKey] = true;
            }
            $albumSlides[$file] = [
                'url' => 'media.php?file=' . rawurlencode($file),
                'label' => 'Random artwork',
            ];
        }
        if (count($albumSlides) >= 12) {
            break;
        }
    }

    if (count($albumSlides) < 8) {
        $fallbackFiles = [];
        foreach (['*mockup*.jpg', '*mockup*.jpeg', '*mockup*.png'] as $pattern) {
            $fallbackFiles = array_merge($fallbackFiles, glob(RESULTS_DIR . DIRECTORY_SEPARATOR . $pattern) ?: []);
        }
        shuffle($fallbackFiles);
        foreach ($fallbackFiles as $path) {
            $file = basename($path);
            if (!isset($albumSlides[$file])) {
                $albumSlides[$file] = [
                    'url' => 'media.php?file=' . rawurlencode($file),
                    'label' => 'System mockup',
                ];
            }
            if (count($albumSlides) >= 12) {
                break;
            }
        }
    }
} catch (Throwable $e) {
    $albumSlides = [];
}

if ($mockupCount <= 0) {
    $mockupCount = ProviderSettings::mockupWorkerCount();
}

$albumSlides = array_values($albumSlides);
shuffle($albumSlides);
$mockupCountLabel = $mockupCount === 1 ? '1 mockup' : $mockupCount . ' mockups';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Preparing Mockups - Artwork Mockups</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <style>
        html,
        body {
            margin: 0;
            padding: 0;
            background: #080807;
            scrollbar-width: thin;
            scrollbar-color: rgba(185, 144, 85, 0.2) transparent;
        }

        body::-webkit-scrollbar {
            width: 4px;
        }

        body::-webkit-scrollbar-track {
            background: transparent;
        }

        body::-webkit-scrollbar-thumb {
            background: rgba(185, 144, 85, 0.25);
            border-radius: 99px;
        }

        .wait-wrap {
            height: 100vh;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 18px 24px;
            background: #080807;
            color: #f7f2ea;
            position: relative;
            overflow: hidden;
        }
        .wait-wrap::before {
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
            animation: waitGradientDrift 18s ease-in-out infinite alternate;
            pointer-events: none;
        }
        .album-stage {
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
        .album-stage::before,
        .album-stage::after {
            content: "";
            position: absolute;
            pointer-events: none;
            border: 1px solid rgba(255, 255, 255, 0.10);
            background: rgba(255, 255, 255, 0.018);
            mix-blend-mode: screen;
        }
        .album-stage::before {
            width: min(72vw, 980px);
            height: min(42vh, 420px);
            transform: translateX(-8vw) rotate(-5deg);
        }
        .album-stage::after {
            width: min(44vw, 620px);
            height: min(34vh, 340px);
            transform: translateX(24vw) rotate(7deg);
            border-color: rgba(214, 178, 122, 0.13);
        }
        .album-track {
            display: flex;
            align-items: center;
            gap: clamp(22px, 3vw, 44px);
            width: max-content;
            height: 100%;
            animation: albumScrollLeft 58s linear infinite;
            position: relative;
            z-index: 1;
        }
        .album-slide {
            flex: 0 0 auto;
            max-width: min(34vw, 540px);
            max-height: 86%;
            width: auto;
            height: auto;
            object-fit: contain;
            filter: drop-shadow(0 24px 72px rgba(0, 0, 0, 0.62));
            opacity: 0.94;
        }
        @keyframes albumScrollLeft {
            from { transform: translateX(0); }
            to { transform: translateX(-50%); }
        }
        .album-empty {
            width: min(82vw, 980px);
            max-height: calc(100% - 24px);
            aspect-ratio: 16 / 10;
            border: 1px solid rgba(255, 255, 255, 0.16);
            background: linear-gradient(135deg, #151311, #28231d);
            box-shadow: 0 30px 90px rgba(0, 0, 0, 0.55);
        }
        .wait-wrap::after {
            content: "";
            position: absolute;
            inset: 0;
            background:
                radial-gradient(circle at center, rgba(0, 0, 0, 0.02), rgba(0, 0, 0, 0.58)),
                linear-gradient(180deg, rgba(0, 0, 0, 0.08), rgba(0, 0, 0, 0.70));
            pointer-events: none;
        }
        .wait-panel {
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
            color: #f7f2ea;
        }
        .wait-title {
            margin: 0 0 2px;
            font-size: clamp(18px, 1.8vw, 23px);
            font-weight: 500;
            color: #f7f2ea;
            font-family: var(--font-serif);
        }
        .wait-copy {
            margin: 0 0 9px;
            font-size: 12px;
            color: rgba(247, 242, 234, 0.74);
            line-height: 1.5;
        }
        .wait-bar {
            height: 8px;
            overflow: hidden;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.18);
        }
        .wait-fill {
            height: 100%;
            width: 0%;
            border-radius: inherit;
            background: linear-gradient(90deg, #b99055, #e2c183);
            box-shadow: 0 0 18px rgba(214, 178, 122, 0.30);
            transition: width 0.35s ease;
        }
        .wait-meta {
            margin-top: 8px;
            font-size: 12px;
            color: rgba(247, 242, 234, 0.72);
        }
        .wait-tip {
            min-height: 38px;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid rgba(247, 242, 234, 0.13);
            color: rgba(247, 242, 234, 0.72);
            font-size: 12px;
            line-height: 1.45;
        }
        .wait-tip strong {
            color: #e2c183;
            font-weight: 600;
        }
        .wait-actions {
            margin-top: 12px;
        }
        .wait-actions a {
            color: #f7f2ea;
        }
        @keyframes waitGradientDrift {
            from { filter: saturate(0.95) brightness(0.88); transform: scale(1); }
            to { filter: saturate(1.16) brightness(1.02); transform: scale(1.035); }
        }
        @media (max-width: 640px) {
            .wait-panel {
                padding: 16px;
            }
            .album-slide {
                max-width: 86vw;
            }
        }

        /* Second block: dream gallery layout - background only, layout handled by third block */
        .wait-wrap {
            background: #020202;
        }

        .wait-wrap::before {
            background:
                radial-gradient(ellipse at 24% 22%, rgba(161, 124, 73, 0.22), transparent 38%),
                radial-gradient(ellipse at 76% 28%, rgba(74, 48, 33, 0.24), transparent 42%),
                radial-gradient(ellipse at 48% 78%, rgba(187, 159, 108, 0.13), transparent 46%),
                linear-gradient(120deg, #010101 0%, #090704 42%, #030303 100%);
            background-size: 150% 150%;
            opacity: 1;
            animation: sepiaBreath 28s ease-in-out infinite alternate;
        }

        .album-stage {
            position: absolute;
            inset: 0;
            z-index: 1;
            display: block;
            width: auto;
            padding: 0;
            overflow: hidden;
        }

        .album-stage::before,
        .album-stage::after {
            border: 0;
            background:
                radial-gradient(ellipse at center, rgba(222, 193, 138, 0.13), transparent 58%);
            filter: blur(24px);
            opacity: 0.36;
        }

        .album-stage::before {
            width: 70vw;
            height: 60vh;
            left: -12vw;
            top: 2vh;
            transform: rotate(-18deg);
        }

        .album-stage::after {
            width: 58vw;
            height: 54vh;
            right: -16vw;
            bottom: -8vh;
            transform: rotate(14deg);
        }

        .album-track {
            position: absolute;
            display: flex;
            align-items: center;
            gap: clamp(70px, 12vw, 180px);
            width: max-content;
            height: auto;
            animation: none;
            will-change: transform;
        }

        .album-track.dream-a {
            top: 12vh;
            left: -34vw;
            animation: dreamDriftA 112s ease-in-out infinite alternate;
        }

        .album-track.dream-b {
            top: 43vh;
            right: -42vw;
            animation: dreamDriftB 146s ease-in-out infinite alternate;
        }

        .album-track.dream-c {
            bottom: 6vh;
            left: -18vw;
            animation: dreamDriftC 128s ease-in-out infinite alternate;
        }

        .album-track.dream-d {
            top: -24vh;
            left: 10vw;
            flex-direction: column;
            animation: dreamColumnDown 168s ease-in-out infinite alternate;
        }

        .album-track.dream-e {
            bottom: -34vh;
            right: 14vw;
            flex-direction: column;
            animation: dreamColumnUp 188s ease-in-out infinite alternate;
        }

        .album-track.dream-f {
            top: 4vh;
            left: -86vw;
            transform-origin: center;
            animation: dreamWideRiver 206s ease-in-out infinite alternate;
        }

        .album-track.dream-g {
            top: 64vh;
            right: -62vw;
            animation: dreamSmallCounter 156s ease-in-out infinite alternate;
        }

        .album-track.dream-h {
            top: 28vh;
            left: -124vw;
            animation: dreamMonumentalMist 236s ease-in-out infinite alternate;
        }

        .album-slide {
            max-width: min(28vw, 430px);
            max-height: 46vh;
            filter: sepia(0.92) saturate(0.55) contrast(0.9) brightness(0.78) blur(0.15px) drop-shadow(0 30px 72px rgba(0, 0, 0, 0.72));
            opacity: 0.34;
            mix-blend-mode: screen;
            border-radius: 1px;
        }

        .album-track.dream-b .album-slide {
            max-width: min(24vw, 360px);
            opacity: 0.24;
            filter: sepia(1) saturate(0.45) contrast(0.86) brightness(0.72) blur(0.45px) drop-shadow(0 30px 70px rgba(0, 0, 0, 0.78));
        }

        .album-track.dream-c .album-slide {
            max-width: min(20vw, 320px);
            opacity: 0.18;
            filter: sepia(0.98) saturate(0.4) contrast(0.82) brightness(0.68) blur(0.7px) drop-shadow(0 24px 64px rgba(0, 0, 0, 0.78));
        }

        .album-track.dream-d .album-slide {
            max-width: min(18vw, 280px);
            max-height: 36vh;
            opacity: 0.20;
            filter: sepia(1) saturate(0.35) contrast(0.8) brightness(0.66) blur(0.8px);
        }

        .album-track.dream-e .album-slide {
            max-width: min(16vw, 240px);
            max-height: 34vh;
            opacity: 0.16;
            filter: invert(1) sepia(0.8) saturate(0.28) contrast(0.88) brightness(0.58) blur(0.6px);
            mix-blend-mode: lighten;
        }

        .album-track.dream-f .album-slide {
            max-width: min(56vw, 860px);
            max-height: 58vh;
            opacity: 0.11;
            filter: sepia(0.9) saturate(0.3) contrast(0.78) brightness(0.62) blur(1px);
        }

        .album-track.dream-g .album-slide {
            max-width: min(14vw, 210px);
            max-height: 28vh;
            opacity: 0.28;
            filter: sepia(1) saturate(0.5) contrast(0.9) brightness(0.76) blur(0.25px);
        }

        .album-track.dream-h .album-slide {
            max-width: min(82vw, 1240px);
            max-height: 76vh;
            opacity: 0.075;
            filter: invert(1) sepia(0.65) saturate(0.2) contrast(0.74) brightness(0.52) blur(1.4px);
            mix-blend-mode: screen;
        }

        .album-slide:nth-child(3n + 1) {
            transform: rotate(-5deg) scale(0.86);
        }

        .album-slide:nth-child(3n + 2) {
            transform: rotate(4deg) scale(1.06);
        }

        .album-slide:nth-child(3n) {
            transform: rotate(-1deg) scale(0.96);
        }

        .wait-wrap::after {
            background:
                radial-gradient(ellipse at center, rgba(0, 0, 0, 0.02), rgba(0, 0, 0, 0.72) 72%),
                linear-gradient(180deg, rgba(0, 0, 0, 0.18), rgba(0, 0, 0, 0.86));
        }

        .wait-panel {
            /* overridden by full-layout block below — kept for cascade safety */
            opacity: 1;
        }

        .wait-panel:hover,
        .wait-panel:focus-within {
            opacity: 1;
        }

        .wait-title {
            font-size: 14px;
            margin: 0;
        }

        .wait-bar {
            height: 3px;
            margin-top: 6px;
        }

        @keyframes dreamDriftA {
            0% { transform: translate3d(-4vw, 0, 0); }
            38% { transform: translate3d(24vw, 7vh, 0); }
            72% { transform: translate3d(52vw, -3vh, 0); }
            100% { transform: translate3d(78vw, 5vh, 0); }
        }

        @keyframes dreamDriftB {
            0% { transform: translate3d(18vw, 0, 0); }
            45% { transform: translate3d(-18vw, -8vh, 0); }
            100% { transform: translate3d(-64vw, 4vh, 0); }
        }

        @keyframes dreamDriftC {
            0% { transform: translate3d(0, 5vh, 0); }
            35% { transform: translate3d(32vw, -6vh, 0); }
            68% { transform: translate3d(58vw, 2vh, 0); }
            100% { transform: translate3d(84vw, -9vh, 0); }
        }

        @keyframes dreamColumnDown {
            0% { transform: translate3d(0, -42vh, 0) rotate(-3deg); }
            40% { transform: translate3d(6vw, 8vh, 0) rotate(2deg); }
            100% { transform: translate3d(-4vw, 78vh, 0) rotate(-1deg); }
        }

        @keyframes dreamColumnUp {
            0% { transform: translate3d(0, 46vh, 0) rotate(4deg); }
            52% { transform: translate3d(-7vw, -10vh, 0) rotate(-2deg); }
            100% { transform: translate3d(5vw, -82vh, 0) rotate(1deg); }
        }

        @keyframes dreamWideRiver {
            0% { transform: translate3d(-18vw, -4vh, 0) scale(2); }
            48% { transform: translate3d(28vw, 9vh, 0) scale(2); }
            100% { transform: translate3d(72vw, -2vh, 0) scale(2); }
        }

        @keyframes dreamSmallCounter {
            0% { transform: translate3d(34vw, 2vh, 0) scale(0.5); }
            45% { transform: translate3d(-4vw, -7vh, 0) scale(0.5); }
            100% { transform: translate3d(-52vw, 5vh, 0) scale(0.5); }
        }

        @keyframes dreamMonumentalMist {
            0% { transform: translate3d(-26vw, -12vh, 0) scale(3); }
            54% { transform: translate3d(18vw, 4vh, 0) scale(3); }
            100% { transform: translate3d(62vw, -6vh, 0) scale(3); }
        }

        @keyframes sepiaBreath {
            from { background-position: 0% 50%; filter: brightness(0.78) saturate(0.82); }
            to { background-position: 100% 48%; filter: brightness(0.98) saturate(1.08); }
        }

        @media (max-width: 640px) {
            .album-slide,
            .album-track.dream-b .album-slide,
            .album-track.dream-c .album-slide {
                max-width: 68vw;
            }

            .wait-panel {
                right: 12px;
                bottom: 12px;
            }
        }

        .wait-wrap {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            padding: 40px 24px;
            background: #080807;
            min-height: 100vh;
            color: #f7f2ea;
            box-sizing: border-box;
            overflow-y: visible;
        }

        .wait-panel {
            width: 100%;
            max-width: 1400px;
            background: rgba(18, 16, 14, 0.6);
            border: 1px solid rgba(214, 178, 122, 0.2);
            border-radius: 8px;
            padding: 24px 30px;
            box-shadow: 0 24px 70px rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(14px);
            margin-bottom: 24px;
            box-sizing: border-box;
            position: relative;
            z-index: 4;
        }

        .wait-header-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }

        .wait-title {
            margin: 0;
            font-size: 26px;
            font-weight: 500;
            color: #f7f2ea;
            font-family: var(--font-serif);
        }

        .wait-bar {
            height: 6px;
            overflow: hidden;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.12);
            flex: 1;
            max-width: 320px;
            margin: 0 20px;
        }

        .wait-fill {
            height: 100%;
            width: 0%;
            border-radius: inherit;
            background: linear-gradient(90deg, #b99055, #e2c183);
            box-shadow: 0 0 12px rgba(214, 178, 122, 0.3);
            transition: width 0.35s ease;
        }

        .wait-meta {
            font-size: 13px;
            color: rgba(247, 242, 234, 0.75);
            white-space: nowrap;
        }

        .wait-actions a {
            color: #e2c183;
            text-decoration: underline;
            font-size: 13px;
            font-weight: 500;
        }

        /* Proposals Grid */
        .proposals-heading {
            width: 100%;
            max-width: 1400px;
            margin: 10px 0 14px;
            font-family: var(--font-serif);
            font-size: 20px;
            color: #e2c183;
            border-bottom: 1px solid rgba(214, 178, 122, 0.15);
            padding-bottom: 8px;
            text-align: left;
            position: relative;
            z-index: 4;
        }

        .proposals-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 20px;
            width: 100%;
            max-width: 1400px;
            margin-bottom: 40px;
            box-sizing: border-box;
            position: relative;
            z-index: 4;
        }

        .proposal-card {
            background: rgba(22, 20, 18, 0.85);
            border: 1px solid rgba(214, 178, 122, 0.12);
            border-radius: 6px;
            padding: 20px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.3);
            display: flex;
            flex-direction: column;
            min-height: 260px;
            box-sizing: border-box;
            transition: all 0.35s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .proposal-card:hover {
            border-color: rgba(214, 178, 122, 0.35);
            transform: translateY(-2px);
        }

        .proposal-card h3 {
            margin: 0 0 6px 0;
            font-family: var(--font-serif);
            font-size: 20px;
            font-weight: 500;
            color: #f7f2ea;
            line-height: 1.25;
        }

        .proposal-meta-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 12px;
        }

        .proposal-kicker {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #b99055;
            font-weight: 600;
        }

        .proposal-badge {
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 3px 7px;
            border-radius: 3px;
        }
        .proposal-badge.queued {
            background: rgba(255, 255, 255, 0.06);
            color: rgba(255, 255, 255, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.12);
        }
        .proposal-badge.processing {
            background: rgba(214, 178, 122, 0.15);
            color: #e2c183;
            border: 1px solid rgba(214, 178, 122, 0.3);
        }
        .proposal-badge.done {
            background: rgba(46, 117, 89, 0.15);
            color: #8adcb3;
            border: 1px solid rgba(46, 117, 89, 0.35);
        }
        .proposal-badge.error {
            background: rgba(166, 60, 60, 0.15);
            color: #f2a8a8;
            border: 1px solid rgba(166, 60, 60, 0.35);
        }
        .proposal-badge.optional {
            background: rgba(255, 255, 255, 0.03);
            color: rgba(255, 255, 255, 0.35);
            border: 1px dashed rgba(255, 255, 255, 0.1);
        }

        .proposal-desc {
            font-size: 12.5px;
            line-height: 1.5;
            color: rgba(247, 242, 234, 0.75);
            margin: 0 0 10px 0;
            min-height: 48px;
        }

        .proposal-desc strong {
            display: block;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #e2c183;
            margin-bottom: 2px;
        }

        .proposal-reason-box {
            background: rgba(214, 178, 122, 0.03);
            border-left: 2px solid #b99055;
            padding: 8px 10px;
            border-radius: 0 4px 4px 0;
            font-size: 12px;
            line-height: 1.45;
            color: rgba(247, 242, 234, 0.85);
            font-style: italic;
            margin-top: auto;
        }

        .proposal-reason-box strong {
            display: block;
            font-style: normal;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #e2c183;
            margin-bottom: 2px;
        }

        .typewriter-cursor::after {
            content: "|";
            animation: blink 0.8s infinite;
        }

        @keyframes blink {
            50% { opacity: 0; }
        }

        .admin-wait-prompts {
            position: fixed;
            top: 24px;
            right: 24px;
            bottom: 24px;
            z-index: 10;
            width: min(560px, calc(100vw - 48px));
            overflow: auto;
            padding: 16px;
            background: rgba(18, 16, 14, 0.98);
            border: 1px solid rgba(214, 178, 122, 0.2);
            border-radius: 8px;
            box-shadow: 0 20px 70px rgba(0, 0, 0, 0.7);
            text-align: left;
            color: #f7f2ea;
        }

        .admin-wait-prompts h2 {
            margin: 0 0 4px;
            font-family: var(--font-serif);
            font-size: 22px;
            font-weight: 500;
            color: #e2c183;
        }

        .admin-wait-prompts > p,
        .admin-wait-empty {
            margin: 0 0 14px;
            color: rgba(247, 242, 234, 0.6);
            font-size: 12px;
            line-height: 1.45;
        }

        .admin-wait-prompt {
            display: block;
            margin-top: 10px;
            border: 1px solid rgba(214, 178, 122, 0.15);
            border-radius: 6px;
            background: rgba(255, 255, 255, 0.02);
            overflow: hidden;
        }

        .admin-wait-prompt summary {
            padding: 10px 12px;
            color: #f7f2ea;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            list-style-position: inside;
        }

        .admin-wait-meta {
            display: grid;
            gap: 3px;
            padding: 0 12px 10px;
            color: rgba(247, 242, 234, 0.6);
            font-size: 11px;
            line-height: 1.35;
        }

        .admin-wait-actions {
            display: flex;
            justify-content: flex-end;
            padding: 0 12px 10px;
        }

        .admin-wait-actions button {
            width: auto;
            margin: 0;
            padding: 7px 10px;
            border: 1px solid #b99055;
            background: transparent;
            color: #e2c183;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            cursor: pointer;
            border-radius: 4px;
            transition: all 0.2s ease;
        }

        .admin-wait-actions button:hover {
            background: #b99055;
            color: #080807;
        }

        .admin-wait-prompt textarea {
            display: block;
            width: calc(100% - 24px);
            min-height: 260px;
            margin: 0 12px 12px;
            padding: 10px;
            resize: vertical;
            border: 1px solid rgba(214, 178, 122, 0.15);
            border-radius: 4px;
            background: rgba(0,0,0,0.3);
            color: #f7f2ea;
            font-family: Consolas, Monaco, monospace;
            font-size: 11px;
            line-height: 1.45;
            box-sizing: border-box;
        }

        @media (max-width: 1200px) {
            .proposals-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 768px) {
            .proposals-grid {
                grid-template-columns: 1fr;
            }
            .wait-header-row {
                flex-direction: column;
                align-items: flex-start;
            }
            .wait-bar {
                margin: 10px 0;
                width: 100%;
                max-width: none;
            }
        }
    </style>
</head>
<body>
<div style="background: #e06c75; color: #fff; text-align: center; padding: 12px; font-weight: bold; font-family: system-ui, sans-serif; position: relative; z-index: 1000; border-bottom: 2px solid #be5046;">
    LEGACY MOCKUP BATCH FLOW — This page belongs to the old batch generation system and should not be used for Phase 2.3 testing.
</div>
<div class="wait-wrap">
    <main class="wait-panel">
        <div class="wait-header-row">
            <h1 class="wait-title" id="waitTitle">Generating Mockup Batch</h1>
            <div class="wait-bar" aria-hidden="true">
                <div class="wait-fill" id="waitFill"></div>
            </div>
            <div class="wait-meta" id="waitMeta">Starting automatic generation...</div>
            <div class="wait-actions">
                <a href="<?= h($reportUrl) ?>">Open report now</a>
            </div>
        </div>
    </main>

    <h2 class="proposals-heading">Curatorial Context Proposals</h2>
    <div class="proposals-grid" id="proposalsGrid">
        <!-- Renders dynamically with poll() -->
    </div>

    <?php if ($isAdmin): ?>
        <aside class="admin-wait-prompts" aria-label="Admin mockup prompts">
            <h2>Admin - Mockup Prompts</h2>
            <p>Prompts completos preparados para este batch. Solo visible para administradores.</p>
            <div class="admin-wait-empty" id="adminWaitPromptEmpty">Buscando prompts de mockups...</div>
            <div id="adminWaitPromptList"></div>
        </aside>
    <?php endif; ?>
</div>

<script>
    const statusUrl = 'mockup_batch_status.php?image=<?= rawurlencode($image) ?>';
    const isAdmin = <?= $isAdmin ? 'true' : 'false' ?>;
    const adminPromptStatusUrl = 'admin_mockup_prompts_status.php?image=<?= rawurlencode($image) ?>';
    const reportUrl = '<?= h($reportUrl) ?>';
    const fill = document.getElementById('waitFill');
    const meta = document.getElementById('waitMeta');
    const waitTitle = document.getElementById('waitTitle');
    const proposalsGrid = document.getElementById('proposalsGrid');
    
    const typedProposals = new Set();

    function escapeHtml(value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function typeText(el, text, speed = 8, onDone = null) {
        el.textContent = '';
        el.style.opacity = '1';
        el.classList.add('typewriter-cursor');
        let i = 0;
        function type() {
            if (i < text.length) {
                el.textContent += text.charAt(i);
                i++;
                setTimeout(type, speed);
            } else {
                el.classList.remove('typewriter-cursor');
                if (onDone) onDone();
            }
        }
        type();
    }

    function triggerTypewriter(card, prop) {
        if (typedProposals.has(prop.id)) return;
        typedProposals.add(prop.id);

        const textQueue = [];
        
        const spaceText = `${prop.space_type}. Materials: ${prop.materials.join(', ')}. Lighting: ${prop.lighting}.`;
        const descEl = card.querySelector('.prop-desc-content');
        if (descEl) {
            textQueue.push({ el: descEl, text: spaceText });
        }

        const whyEl = card.querySelector('.prop-why-content');
        if (whyEl && prop.curatorial_reason) {
            textQueue.push({ el: whyEl, text: prop.curatorial_reason });
        }

        function runNext() {
            if (textQueue.length === 0) return;
            const item = textQueue.shift();
            typeText(item.el, item.text, 8, runNext);
        }
        runNext();
    }

    function renderProposals(proposals, jobs) {
        if (!proposalsGrid || !Array.isArray(proposals)) return;

        proposals.forEach((prop, idx) => {
            let card = document.getElementById(`prop-card-${prop.id}`);
            const job = (jobs || []).find(j => String(j.context_id) === String(prop.id));
            const isInitialBatch = Boolean(job);
            
            // Find job status if initial batch
            let status = 'optional';
            let label = 'Opcional';
            if (isInitialBatch) {
                status = String(job.status);
                label = matchStatusLabel(status);
            }

            if (!card) {
                card = document.createElement('div');
                card.id = `prop-card-${prop.id}`;
                card.className = 'proposal-card';
                card.innerHTML = `
                    <div class="proposal-meta-row">
                        <span class="proposal-kicker">Proposal ${idx + 1}</span>
                        <span class="proposal-badge ${status}" id="badge-${prop.id}">${label}</span>
                    </div>
                    <h3>${escapeHtml(prop.context_name)}</h3>
                    
                    <p class="proposal-desc">
                        <strong>Space and Atmosphere</strong>
                        <span class="prop-desc-content" style="opacity: 0;">Cargando...</span>
                    </p>
                    
                    <div class="proposal-reason-box">
                        <strong>Curatorial Rationale</strong>
                        <span class="prop-why-content" style="opacity: 0;">Cargando...</span>
                    </div>
                `;
                proposalsGrid.appendChild(card);
                triggerTypewriter(card, prop);
            } else {
                // Update badge if changed
                const badge = document.getElementById(`badge-${prop.id}`);
                if (badge) {
                    badge.className = `proposal-badge ${status}`;
                    badge.textContent = label;
                }
            }
        });
    }

    function matchStatusLabel(status) {
        return matchStatusLabelEs(status);
    }

    function matchStatusLabelEs(status) {
        return matchStatus(status);
    }

    function matchStatus(status) {
        switch (status) {
            case 'queued': return 'En cola';
            case 'processing': return 'Generating';
            case 'done': return 'Ready';
            case 'error': return 'Error';
            default: return 'En espera';
        }
    }

    function bindAdminPromptCopyButtons(scope = document) {
        scope.querySelectorAll('.admin-copy-wait-prompt:not([data-bound="1"])').forEach((button) => {
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

    async function pollAdminMockupPrompts() {
        if (!isAdmin) return;

        const list = document.getElementById('adminWaitPromptList');
        const empty = document.getElementById('adminWaitPromptEmpty');
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
                empty.textContent = 'No mockup prompts yet. Waiting for contexts...';
                window.setTimeout(pollAdminMockupPrompts, 2500);
                return;
            }

            empty.style.display = 'none';
            list.innerHTML = data.prompts.map((item, index) => {
                const textareaId = `adminWaitPrompt${index}`;
                return `
                    <details class="admin-wait-prompt" ${index === 0 ? 'open' : ''}>
                        <summary>Proposal ${escapeHtml(item.number)} - ${escapeHtml(item.name)}</summary>
                        <div class="admin-wait-meta">
                            <span>Context ID: ${escapeHtml(item.id)}</span>
                            <span>Purpose: ${escapeHtml(item.purpose)}</span>
                            <span>Camera: ${escapeHtml(item.camera)}</span>
                            <span>Time: ${escapeHtml(item.time)}</span>
                        </div>
                        <div class="admin-wait-actions">
                            <button type="button" class="admin-copy-wait-prompt" data-target="${textareaId}">Copy prompt</button>
                        </div>
                        <textarea id="${textareaId}" readonly>${escapeHtml(item.prompt)}</textarea>
                    </details>
                `;
            }).join('');
            bindAdminPromptCopyButtons(list);
        } catch (error) {
            empty.textContent = error.message;
            window.setTimeout(pollAdminMockupPrompts, 3500);
        }
    }

    async function poll() {
        try {
            const response = await fetch(statusUrl, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            const data = await response.json();
            if (!response.ok || !data.ok) {
                throw new Error(data.error || 'Could not read generation status.');
            }

            const total = Number(data.total || 0);
            const done = Number(data.done || 0);
            const error = Number(data.error || 0);
            const queued = Number(data.queued || 0);
            const processing = Number(data.processing || 0);
            const pending = queued + processing;
            const complete = done + error;
            const pct = total > 0 ? Math.round((complete / total) * 100) : 0;
            const statusParts = [
                `${done} ready`,
                `${processing} generando`,
                `${queued} en cola`,
            ];
            if (error > 0) {
                statusParts.push(`${error} con error${error === 1 ? '' : 'es'}`);
            }

            fill.style.width = `${pct}%`;
            if (waitTitle && total > 0) {
                waitTitle.textContent = `Generating Mockup Batch (${complete} of ${total} processed)`;
            }
            meta.textContent = total > 0
                ? `${statusParts.join(' · ')}. Total: ${total}.`
                : 'Starting automatic generation...';

            if (data.proposals) {
                renderProposals(data.proposals, data.jobs);
            }

            if (total > 0 && pending === 0) {
                window.location.href = reportUrl;
                return;
            }
        } catch (error) {
            meta.textContent = error.message;
        }

        window.setTimeout(poll, 3000);
    }

    window.setTimeout(poll, 600);
    pollAdminMockupPrompts();
</script>
</body>
</html>

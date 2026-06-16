<?php
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
if ($image === '') {
    header('Location: dashboard.php');
    exit;
}

$form2Url = 'form2.php?image=' . rawurlencode($image) . '&auto=1';
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
    $mockupCount = MockupBatchQueue::INITIAL_BATCH_LIMIT;
}

$albumSlides = array_values($albumSlides);
shuffle($albumSlides);
$mockupCountLabel = $mockupCount === 1 ? '1 mockup' : $mockupCount . ' mockups';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Preparing Mockups - The Artwork Curator</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <style>
        html,
        body {
            zoom: 1;
            height: 100%;
            overflow: hidden;
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

        .wait-wrap {
            background: #020202;
            padding: 0;
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

        .wait-panel:hover,
        .wait-panel:focus-within {
            opacity: 0.88;
        }

        .wait-title {
            font-size: 14px;
            margin: 0;
        }

        .wait-copy,
        .wait-meta,
        .wait-tip,
        .wait-actions {
            display: none;
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
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: var(--bg);
        }

        .wait-wrap::before,
        .wait-wrap::after,
        .album-stage {
            display: none;
        }

        .wait-panel {
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

        .wait-panel::before {
            content: "";
            display: block;
            width: 34px;
            height: 34px;
            margin: 0 auto 18px;
            border-radius: 50%;
            border: 2px solid var(--line);
            border-top-color: var(--accent);
            animation: simpleSpin 0.9s linear infinite;
        }

        .wait-title {
            margin: 0 0 8px;
            color: var(--ink);
            font-size: 22px;
        }

        .wait-copy {
            display: block;
            margin: 0 0 14px;
            color: var(--muted);
            font-size: 13px;
        }

        .wait-meta,
        .wait-tip,
        .wait-actions {
            display: none;
        }

        .wait-bar {
            display: block;
            height: 3px;
            margin-top: 0;
            background: var(--line);
        }

        @keyframes simpleSpin {
            to { transform: rotate(360deg); }
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
            background: rgba(255, 255, 255, 0.96);
            border: 1px solid #e5e3dd;
            border-radius: 8px;
            box-shadow: 0 20px 70px rgba(20, 20, 18, 0.16);
            text-align: left;
        }

        .admin-wait-prompts h2 {
            margin: 0 0 4px;
            font-family: var(--font-serif);
            font-size: 22px;
            font-weight: 500;
            color: #141412;
        }

        .admin-wait-prompts > p,
        .admin-wait-empty {
            margin: 0 0 14px;
            color: #7a7872;
            font-size: 12px;
            line-height: 1.45;
        }

        .admin-wait-prompt {
            display: block;
            margin-top: 10px;
            border: 1px solid #e5e3dd;
            border-radius: 6px;
            background: #faf9f6;
            overflow: hidden;
        }

        .admin-wait-prompt summary {
            padding: 10px 12px;
            color: #141412;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            list-style-position: inside;
        }

        .admin-wait-meta {
            display: grid;
            gap: 3px;
            padding: 0 12px 10px;
            color: #7a7872;
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
            border: 1px solid #9a7b56;
            background: #fff;
            color: #141412;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            cursor: pointer;
        }

        .admin-wait-prompt textarea {
            display: block;
            width: calc(100% - 24px);
            min-height: 260px;
            margin: 0 12px 12px;
            padding: 10px;
            resize: vertical;
            border: 1px solid #e5e3dd;
            border-radius: 4px;
            background: #fbfaf7;
            color: #141412;
            font-family: Consolas, Monaco, monospace;
            font-size: 11px;
            line-height: 1.45;
            box-sizing: border-box;
        }
    </style>
</head>
<body>
<div class="wait-wrap">
    <div class="album-stage" aria-hidden="true">
        <?php if (!empty($albumSlides)): ?>
            <div class="album-track dream-a">
                <?php foreach (array_merge($albumSlides, $albumSlides) as $slide): ?>
                    <img class="album-slide" src="<?= h($slide['url']) ?>" alt="">
                <?php endforeach; ?>
            </div>
            <div class="album-track dream-b">
                <?php foreach (array_merge(array_reverse($albumSlides), array_reverse($albumSlides)) as $slide): ?>
                    <img class="album-slide" src="<?= h($slide['url']) ?>" alt="">
                <?php endforeach; ?>
            </div>
            <div class="album-track dream-c">
                <?php foreach (array_merge($albumSlides, array_reverse($albumSlides)) as $slide): ?>
                    <img class="album-slide" src="<?= h($slide['url']) ?>" alt="">
                <?php endforeach; ?>
            </div>
            <div class="album-track dream-d">
                <?php foreach (array_merge($albumSlides, $albumSlides) as $slide): ?>
                    <img class="album-slide" src="<?= h($slide['url']) ?>" alt="">
                <?php endforeach; ?>
            </div>
            <div class="album-track dream-e">
                <?php foreach (array_merge(array_reverse($albumSlides), $albumSlides) as $slide): ?>
                    <img class="album-slide" src="<?= h($slide['url']) ?>" alt="">
                <?php endforeach; ?>
            </div>
            <div class="album-track dream-f">
                <?php foreach (array_merge($albumSlides, $albumSlides) as $slide): ?>
                    <img class="album-slide" src="<?= h($slide['url']) ?>" alt="">
                <?php endforeach; ?>
            </div>
            <div class="album-track dream-g">
                <?php foreach (array_merge(array_reverse($albumSlides), array_reverse($albumSlides)) as $slide): ?>
                    <img class="album-slide" src="<?= h($slide['url']) ?>" alt="">
                <?php endforeach; ?>
            </div>
            <div class="album-track dream-h">
                <?php foreach (array_merge($albumSlides, array_reverse($albumSlides)) as $slide): ?>
                    <img class="album-slide" src="<?= h($slide['url']) ?>" alt="">
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="album-empty"></div>
        <?php endif; ?>
    </div>
    <main class="wait-panel">
        <h1 class="wait-title" id="waitTitle">Preparing your <?= h($mockupCountLabel) ?></h1>
        <p class="wait-copy">The initial proposal set is being generated now. Your mockup proposals will open automatically when the batch is ready.</p>
        <div class="wait-bar" aria-hidden="true">
            <div class="wait-fill" id="waitFill"></div>
        </div>
        <div class="wait-meta" id="waitMeta">Starting automatic generation...</div>
        <div class="wait-tip" id="waitTip"></div>
        <div class="wait-actions">
            <a href="<?= h($form2Url) ?>">Open proposals now</a>
        </div>
    </main>
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
    const form2Url = '<?= h($form2Url) ?>';
    const fill = document.getElementById('waitFill');
    const meta = document.getElementById('waitMeta');
    const waitTitle = document.getElementById('waitTitle');
    const waitTip = document.getElementById('waitTip');
    const waitTips = [
        ['Artist profile', 'The richer the profile, the more precise the room, audience and atmosphere choices become.'],
        ['If a mockup misses', 'Use the prompt button to inspect the direction, then regenerate with a cleaner context or root image.'],
        ['Best mockups', 'Scale, wall contact, light and color fidelity matter more than a dramatic room.'],
        ['Ready to publish', 'A strong title, dimensions and short artwork text turn the mockup into a sales tool.']
    ];
    let waitTipIndex = 0;

    function escapeHtml(value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
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
                empty.textContent = 'Aun no hay prompts de mockups. Esperando contextos...';
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

    function rotateWaitTip() {
        if (!waitTip) return;
        const tip = waitTips[waitTipIndex % waitTips.length];
        waitTip.innerHTML = '<strong>' + tip[0] + ':</strong> ' + tip[1];
        waitTipIndex++;
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
            const pending = Number(data.queued || 0) + Number(data.processing || 0);
            const complete = done + error;
            const pct = total > 0 ? Math.round((complete / total) * 100) : 0;

            fill.style.width = `${pct}%`;
            if (waitTitle && total > 0) {
                waitTitle.textContent = `Preparing your ${total} ${total === 1 ? 'mockup' : 'mockups'}`;
            }
            meta.textContent = total > 0
                ? `${complete} of ${total} mockups ready${error > 0 ? `, ${error} with errors` : ''}.`
                : 'Preparing automatic generation...';

            if (total > 0 && pending === 0) {
                window.location.href = form2Url;
                return;
            }
        } catch (error) {
            meta.textContent = error.message;
        }

        window.setTimeout(poll, 3500);
    }

    window.setTimeout(poll, 900);
    pollAdminMockupPrompts();
    setInterval(rotateWaitTip, 6200);
    rotateWaitTip();
</script>
</body>
</html>

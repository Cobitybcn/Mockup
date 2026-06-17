<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$currentUser = Auth::requireUser();
$isAdmin = Auth::isAdmin($currentUser);

$job = $_GET['job'] ?? '';
$job = basename($job);

if (!$job) {
    die('Falta job.');
}

$jobDir = __DIR__ . '/jobs/' . $job;
$statusFile = $jobDir . '/status.json';

if (!is_file($statusFile)) {
    die('No se encontro el trabajo.');
}

$status = json_decode((string)file_get_contents($statusFile), true);

if (!$status) {
    die('No se pudo leer el estado.');
}

if ((int)($status['user_id'] ?? 0) !== (int)$currentUser['id']) {
    http_response_code(403);
    die('No tienes acceso a este trabajo.');
}

$currentStatus = $status['status'] ?? 'unknown';
$message = $status['message'] ?? '';
$resultFile = $status['result_file'] ?? null;
$error = $status['error'] ?? null;

$resultUrl = null;

if ($currentStatus === 'done') {
    header('Location: root_select.php?job=' . urlencode($job));
    exit;
}

$statusLabels = [
    'queued' => 'Analyzing artwork composition...',
    'processing' => 'Evaluating visual atmosphere...',
    'done' => 'Refining artwork presentation...',
    'error' => 'Error',
];

$publicStatus = $statusLabels[(string)$currentStatus] ?? (string)$currentStatus;
$publicMessage = match ((string)$currentStatus) {
    'queued' => 'Analyzing artwork composition and structure...',
    'processing' => 'Evaluating visual atmosphere and dominant palette...',
    default => 'The system is preparing the artwork presentation.',
};

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
            $albumSlides[$file] = 'media.php?file=' . rawurlencode($file);
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
                $albumSlides[$file] = 'media.php?file=' . rawurlencode($file);
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

function read_admin_root_prompt(string $jobId, array $measurements = []): string
{
    $promptFile = __DIR__ . '/jobs/' . basename($jobId) . '/prompt.txt';

    if (is_file($promptFile)) {
        return trim((string)file_get_contents($promptFile));
    }

    return trim(PromptSettings::rootArtworkRules());
}

function admin_root_waiting_prompts(array $currentUser, string $currentJob): array
{
    $items = [];

    try {
        $pdo = Database::connection();
        $dateOrder = Database::dateOrderSql('created_at', 'DESC');
        $stmt = $pdo->prepare("
            SELECT job_id, main_file, status, width, height, depth, unit, created_at, updated_at
            FROM artworks
            WHERE user_id = :user_id
            AND status IN ('queued', 'processing')
            ORDER BY {$dateOrder}
            LIMIT 10
        ");
        $stmt->execute(['user_id' => (int)$currentUser['id']]);

        foreach ($stmt->fetchAll() as $row) {
            $jobId = basename((string)($row['job_id'] ?? ''));
            if ($jobId === '') {
                continue;
            }

            $row['prompt'] = read_admin_root_prompt($jobId, [
                'width' => $row['width'] ?? '',
                'height' => $row['height'] ?? '',
                'depth' => $row['depth'] ?? '',
                'unit' => $row['unit'] ?? 'cm',
            ]);
            $row['prompt_source'] = is_file(__DIR__ . '/jobs/' . $jobId . '/prompt.txt') ? 'prompt.txt' : 'current root rules fallback';
            $items[$jobId] = $row;
        }
    } catch (Throwable $e) {
        $items = [];
    }

    if ($currentJob !== '' && !isset($items[$currentJob])) {
        $items[$currentJob] = [
            'job_id' => $currentJob,
            'main_file' => basename((string)($GLOBALS['status']['main_file'] ?? '')),
            'status' => (string)($GLOBALS['currentStatus'] ?? 'unknown'),
            'width' => (string)($GLOBALS['status']['measurements']['width'] ?? ''),
            'height' => (string)($GLOBALS['status']['measurements']['height'] ?? ''),
            'depth' => (string)($GLOBALS['status']['measurements']['depth'] ?? ''),
            'unit' => (string)($GLOBALS['status']['measurements']['unit'] ?? 'cm'),
            'created_at' => (string)($GLOBALS['status']['created_at'] ?? ''),
            'updated_at' => (string)($GLOBALS['status']['updated_at'] ?? ''),
            'prompt' => read_admin_root_prompt($currentJob, (array)($GLOBALS['status']['measurements'] ?? [])),
            'prompt_source' => is_file(__DIR__ . '/jobs/' . $currentJob . '/prompt.txt') ? 'prompt.txt' : 'current root rules fallback',
        ];
    }

    return array_values($items);
}

$adminWaitingPrompts = $isAdmin ? admin_root_waiting_prompts($currentUser, $job) : [];

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Preparing Root Image - The Artwork Curator</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">

    <style>
        html,
        body {
            zoom: 1;
        }

        body {
            background: #080807;
        }

        .main-area {
            background: #080807;
        }

        .album-wait {
            height: calc(100vh - 86px);
            box-sizing: border-box;
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 18px 24px;
        }

        .album-wait::before {
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
            width: min(76vw, 980px);
            max-height: calc(100% - 24px);
            aspect-ratio: 16 / 10;
            border: 1px solid rgba(255, 255, 255, 0.16);
            background: linear-gradient(135deg, #151311, #28231d);
            box-shadow: 0 30px 90px rgba(0, 0, 0, 0.55);
        }

        .album-wait::after {
            content: "";
            position: absolute;
            inset: 0;
            background:
                radial-gradient(circle at center, rgba(0, 0, 0, 0.02), rgba(0, 0, 0, 0.58)),
                linear-gradient(180deg, rgba(0, 0, 0, 0.08), rgba(0, 0, 0, 0.70));
            pointer-events: none;
        }

        .process-card {
            width: min(560px, calc(100vw - 48px));
            background: linear-gradient(135deg, rgba(18, 16, 14, 0.82), rgba(10, 9, 8, 0.68));
            border: 1px solid rgba(214, 178, 122, 0.20);
            padding: 14px 16px 13px;
            box-shadow: 0 24px 70px rgba(0, 0, 0, 0.42), inset 0 1px 0 rgba(255, 255, 255, 0.06);
            border-radius: 8px;
            color: #f7f2ea;
            backdrop-filter: blur(14px);
            position: relative;
            z-index: 3;
            margin: 0;
        }

        .process-card h2 {
            margin: 0 0 2px;
            font-size: clamp(18px, 1.8vw, 23px);
            letter-spacing: -0.01em;
            font-family: var(--font-serif);
            font-weight: 500;
            color: #f7f2ea;
        }

        .process-card .page-kicker,
        .process-card p {
            color: rgba(247, 242, 234, 0.74);
            margin-top: 0;
        }

        .status-box {
            font-size: 13px;
            background: rgba(255, 255, 255, 0.08);
            padding: 10px 12px;
            border-left: 3px solid #d6b27a;
            margin: 9px 0;
            border-radius: 0 var(--radius) var(--radius) 0;
        }

        .artist-wait-tip {
            min-height: 38px;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid rgba(247, 242, 234, 0.13);
            color: rgba(247, 242, 234, 0.72);
            font-size: 12px;
            line-height: 1.45;
        }

        .artist-wait-tip strong {
            color: #e2c183;
            font-weight: 600;
        }

        .progress {
            width: 100%;
            height: 6px;
            overflow: hidden;
            background: rgba(255, 255, 255, 0.18);
            margin: 10px 0;
            position: relative;
            border-radius: 99px;
        }

        .progress-bar {
            width: 42%;
            height: 100%;
            background: linear-gradient(90deg, #b99055, #e2c183);
            box-shadow: 0 0 18px rgba(214, 178, 122, 0.30);
            position: absolute;
            left: -42%;
            top: 0;
            animation: progressMove 1.6s ease-in-out infinite;
        }

        @keyframes progressMove {
            0% { left: -42%; }
            55% { left: 100%; }
            100% { left: 100%; }
        }

        @keyframes waitGradientDrift {
            from { filter: saturate(0.95) brightness(0.88); transform: scale(1); }
            to { filter: saturate(1.16) brightness(1.02); transform: scale(1.035); }
        }

        .root-preview {
            width: 100%;
            max-height: 78vh;
            object-fit: contain;
            display: block;
            margin: 24px 0;
            background: var(--surface-soft);
            border: 12px solid var(--surface);
            box-shadow: var(--shadow);
            border-radius: var(--radius);
        }

        .error {
            color: var(--danger);
            background: #FFF5F5;
            border-left-color: var(--danger);
        }

        code {
            background: rgba(255, 255, 255, 0.12);
            padding: 2px 5px;
            font-family: monospace;
            font-size: 12px;
            border-radius: 2px;
        }

        @media (max-width: 640px) {
            .album-slide {
                max-width: 86vw;
            }

            .process-card {
                padding: 16px;
            }
        }

        iframe {
            display: none;
            width: 0;
            height: 0;
            border: 0;
        }

        .album-wait {
            background: #020202;
            padding: 0;
        }

        .album-wait::before {
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

        .album-wait::after {
            background:
                radial-gradient(ellipse at center, rgba(0, 0, 0, 0.02), rgba(0, 0, 0, 0.72) 72%),
                linear-gradient(180deg, rgba(0, 0, 0, 0.18), rgba(0, 0, 0, 0.86));
        }

        .process-card {
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

        .process-card:hover,
        .process-card:focus-within {
            opacity: 0.88;
        }

        .process-card h2 {
            font-size: 14px;
            margin: 0;
        }

        .process-card .page-kicker,
        .status-box,
        .artist-wait-tip,
        .process-card p {
            display: none;
        }

        .progress {
            height: 3px;
            margin: 6px 0 0;
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

            .process-card {
                right: 12px;
                bottom: 12px;
            }
        }

        .album-wait {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: var(--bg);
        }

        .album-wait::before,
        .album-wait::after,
        .album-stage {
            display: none;
        }

        .process-card {
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

        .process-card h2 {
            margin: 0 0 8px;
            color: var(--ink);
            font-size: 22px;
        }

        .process-card .page-kicker {
            display: block;
            margin: 0;
            color: var(--muted);
            font-size: 13px;
        }

        .status-box,
        .artist-wait-tip,
        .process-card p,
        .progress,
        code {
            display: none;
        }

        .process-card::before {
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

        @keyframes simpleSpin {
            to { transform: rotate(360deg); }
        }

        .admin-root-prompts {
            position: fixed;
            top: 104px;
            right: 24px;
            bottom: 24px;
            z-index: 20;
            width: min(560px, calc(100vw - 48px));
            overflow: auto;
            padding: 16px;
            background: rgba(255, 255, 255, 0.96);
            border: 1px solid var(--line);
            border-radius: 8px;
            box-shadow: 0 20px 70px rgba(20, 20, 18, 0.16);
            text-align: left;
        }

        .admin-root-prompts h3 {
            margin: 0 0 4px;
            font-family: var(--font-serif);
            font-size: 22px;
            font-weight: 500;
            color: var(--ink);
        }

        .admin-root-prompts > p {
            margin: 0 0 14px;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.45;
        }

        .admin-prompt-job {
            border: 1px solid var(--line);
            border-radius: 6px;
            background: var(--surface);
            margin-top: 10px;
            overflow: hidden;
        }

        .admin-prompt-job summary {
            cursor: pointer;
            padding: 10px 12px;
            color: var(--ink);
            font-size: 12px;
            font-weight: 600;
            list-style-position: inside;
        }

        .admin-prompt-meta {
            display: grid;
            gap: 3px;
            padding: 0 12px 10px;
            color: var(--muted);
            font-size: 11px;
            line-height: 1.35;
        }

        .admin-prompt-actions {
            display: flex;
            justify-content: flex-end;
            padding: 0 12px 10px;
        }

        .admin-prompt-actions button {
            width: auto;
            margin: 0;
            padding: 7px 10px;
            font-size: 11px;
        }

        .admin-prompt-text {
            display: block;
            width: calc(100% - 24px);
            min-height: 260px;
            margin: 0 12px 12px;
            padding: 10px;
            resize: vertical;
            border: 1px solid var(--line);
            border-radius: 4px;
            background: #fbfaf7;
            color: var(--ink);
            font-family: Consolas, Monaco, monospace;
            font-size: 11px;
            line-height: 1.45;
            box-sizing: border-box;
        }

        @media (max-width: 900px) {
            .admin-root-prompts {
                left: 16px;
                right: 16px;
                top: auto;
                bottom: 16px;
                width: auto;
                max-height: 58vh;
            }
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
            Step 1 · Create Root Image: preparing a faithful, clean and proportional base image for future mockups.
        </div>

        <div class="workspace album-wait">
            <div class="album-stage" aria-hidden="true">
                <?php if (!empty($albumSlides)): ?>
                    <div class="album-track dream-a">
                        <?php foreach (array_merge($albumSlides, $albumSlides) as $slideUrl): ?>
                            <img class="album-slide" src="<?= h($slideUrl) ?>" alt="">
                        <?php endforeach; ?>
                    </div>
                    <div class="album-track dream-b">
                        <?php foreach (array_merge(array_reverse($albumSlides), array_reverse($albumSlides)) as $slideUrl): ?>
                            <img class="album-slide" src="<?= h($slideUrl) ?>" alt="">
                        <?php endforeach; ?>
                    </div>
                    <div class="album-track dream-c">
                        <?php foreach (array_merge($albumSlides, array_reverse($albumSlides)) as $slideUrl): ?>
                            <img class="album-slide" src="<?= h($slideUrl) ?>" alt="">
                        <?php endforeach; ?>
                    </div>
                    <div class="album-track dream-d">
                        <?php foreach (array_merge($albumSlides, $albumSlides) as $slideUrl): ?>
                            <img class="album-slide" src="<?= h($slideUrl) ?>" alt="">
                        <?php endforeach; ?>
                    </div>
                    <div class="album-track dream-e">
                        <?php foreach (array_merge(array_reverse($albumSlides), $albumSlides) as $slideUrl): ?>
                            <img class="album-slide" src="<?= h($slideUrl) ?>" alt="">
                        <?php endforeach; ?>
                    </div>
                    <div class="album-track dream-f">
                        <?php foreach (array_merge($albumSlides, $albumSlides) as $slideUrl): ?>
                            <img class="album-slide" src="<?= h($slideUrl) ?>" alt="">
                        <?php endforeach; ?>
                    </div>
                    <div class="album-track dream-g">
                        <?php foreach (array_merge(array_reverse($albumSlides), array_reverse($albumSlides)) as $slideUrl): ?>
                            <img class="album-slide" src="<?= h($slideUrl) ?>" alt="">
                        <?php endforeach; ?>
                    </div>
                    <div class="album-track dream-h">
                        <?php foreach (array_merge($albumSlides, array_reverse($albumSlides)) as $slideUrl): ?>
                            <img class="album-slide" src="<?= h($slideUrl) ?>" alt="">
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="album-empty"></div>
                <?php endif; ?>
            </div>
            <section class="process-card">
                <?php if ($currentStatus === 'done' && $resultUrl): ?>

                    <h2>Root Image Created</h2>
                    <p class="page-kicker">Review the base image before proceeding to curatorial direction.</p>

                    <div class="status-box">
                        Root image is ready. Please inspect the framing and alignment.
                    </div>

                    <img class="root-preview" src="<?= h($resultUrl) ?>" alt="Generated root artwork">

                    <div class="topbar-actions">
                        <a class="button-link" href="report.php?image=<?= rawurlencode(basename($resultFile)) ?>">
                            Proceed to Step 2 · Curatorial Direction
                        </a>

                        <a class="button-link secondary" href="artwork_new.php">
                            Upload another artwork
                        </a>
                    </div>

                <?php elseif ($currentStatus === 'error'): ?>

                    <h2>Generation Error</h2>

                    <div class="status-box error">
                        <?= h($error ?: $message ?: 'An unknown error occurred.') ?>
                    </div>

                    <p>Job ID: <code><?= h($job) ?></code></p>

                    <a class="button-link" href="artwork_new.php">Go back</a>

                <?php else: ?>

                    <h2>Preparing Root Image</h2>
                    <p class="page-kicker">You can leave this window open. The system will update the progress automatically.</p>

                    <div class="status-box" id="statusBox">
                        Status: <strong id="statusText"><?= h($publicStatus) ?></strong><br>
                        <span id="messageText"><?= h($publicMessage) ?></span>
                    </div>

                    <div class="progress" aria-label="Generating image">
                        <div class="progress-bar"></div>
                    </div>

                    <p>Job ID: <code><?= h($job) ?></code></p>
                    <div class="artist-wait-tip" id="artistWaitTip"></div>

                    <script>
                        const job = <?= json_encode($job) ?>;
                        const statusUrl = 'job_status.php?job=' + encodeURIComponent(job);
                        const waitTips = [
                            ['Artist profile', 'A complete profile helps the system choose better spaces, atmosphere and market positioning.'],
                            ['If a result feels wrong', 'Try another root image or regenerate from a cleaner, more frontal base.'],
                            ['Best mockup signal', 'Look for faithful color, believable scale, wall contact and a context that does not compete with the artwork.'],
                            ['Publishing', 'Title, dimensions, technique and a short statement make the final artwork page stronger.']
                        ];
                        let waitTipIndex = 0;

                        function rotateWaitTip() {
                            const target = document.getElementById('artistWaitTip');
                            if (!target) return;
                            const tip = waitTips[waitTipIndex % waitTips.length];
                            target.innerHTML = '<strong>' + tip[0] + ':</strong> ' + tip[1];
                            waitTipIndex++;
                        }

                        async function checkStatus() {
                            try {
                                const response = await fetch(statusUrl + '&t=' + Date.now(), {
                                    cache: 'no-store'
                                });

                                const data = await response.json();

                                const labels = {
                                    queued: 'Analyzing artwork composition...',
                                    processing: 'Evaluating visual atmosphere...',
                                    done: 'Refining artwork presentation...',
                                    error: 'Error'
                                };

                                const messages = {
                                    queued: 'Analyzing artwork composition and structure...',
                                    processing: 'Evaluating visual atmosphere and dominant palette...',
                                    done: 'Artwork presentation refined. Reviewing base image...',
                                    error: 'Generation could not be completed.'
                                };

                                document.getElementById('statusText').textContent = labels[data.status] || data.status || 'Pending';
                                document.getElementById('messageText').textContent = data.message || messages[data.status] || 'The system is preparing the image.';

                                if (data.status === 'done') {
                                    window.location.href = 'root_select.php?job=' + encodeURIComponent(job);
                                } else if (data.status === 'error') {
                                    window.location.reload();
                                }

                            } catch (e) {
                                console.log('Waiting for status update...', e);
                            }
                        }

                        setInterval(checkStatus, 5000);
                        setInterval(rotateWaitTip, 6200);
                        rotateWaitTip();
                        checkStatus();

                    </script>

                <?php endif; ?>
            </section>

            <?php if ($isAdmin && !empty($adminWaitingPrompts)): ?>
                <aside class="admin-root-prompts" aria-label="Admin root prompts">
                    <h3>Admin - Root Prompts</h3>
                    <p>Prompts completos usados para los root images actualmente en espera. Solo visible para administradores.</p>

                    <?php foreach ($adminWaitingPrompts as $index => $promptJob): ?>
                        <?php
                            $promptJobId = basename((string)($promptJob['job_id'] ?? ''));
                            $promptTextId = 'adminRootPrompt' . $index;
                            $dims = trim(
                                (string)($promptJob['width'] ?? '') . ' x ' .
                                (string)($promptJob['height'] ?? '') .
                                (((string)($promptJob['depth'] ?? '') !== '') ? ' x ' . (string)$promptJob['depth'] : '') . ' ' .
                                (string)($promptJob['unit'] ?? '')
                            );
                        ?>
                        <details class="admin-prompt-job" <?= $promptJobId === $job ? 'open' : '' ?>>
                            <summary><?= h($promptJobId) ?> - <?= h((string)($promptJob['status'] ?? 'unknown')) ?></summary>
                            <div class="admin-prompt-meta">
                                <span>Archivo: <?= h((string)($promptJob['main_file'] ?? '')) ?></span>
                                <span>Medidas: <?= h($dims) ?></span>
                                <span>Fuente: <?= h((string)($promptJob['prompt_source'] ?? '')) ?></span>
                            </div>
                            <div class="admin-prompt-actions">
                                <button type="button" class="button secondary admin-copy-prompt" data-target="<?= h($promptTextId) ?>">Copy prompt</button>
                            </div>
                            <textarea id="<?= h($promptTextId) ?>" class="admin-prompt-text" readonly><?= h((string)($promptJob['prompt'] ?? '')) ?></textarea>
                        </details>
                    <?php endforeach; ?>
                </aside>

                <script>
                    document.querySelectorAll('.admin-copy-prompt').forEach((button) => {
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
                </script>
            <?php endif; ?>
        </div>
    </main>
</div>
</body>
</html>

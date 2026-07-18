<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
FeatureAccess::requirePage($user, FeatureAccess::MOCKUPS_GENERATE, 'Mockup generation');

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function scene_preview_urls(array $images, int $limit = 3): array
{
    $urls = [];
    foreach (array_slice($images, 0, $limit) as $image) {
        $path = str_replace('\\', '/', (string)($image['relative_path'] ?? ''));
        if ($path === '' || !str_starts_with($path, 'storage/world_mothers/')) {
            continue;
        }
        $urls[] = [
            'url' => 'world_mother_media.php?file=' . rawurlencode($path) . '&thumb=1&w=640',
            'title' => (string)($image['title'] ?? 'Scene reference'),
        ];
    }
    return $urls;
}

$library = new WorldMotherLibrary();
$sceneCategories = array_values(array_filter(
    $library->categories(),
    static fn(array $category): bool => (int)($category['image_count'] ?? 0) > 0
));

usort($sceneCategories, static function (array $a, array $b): int {
    return strcmp((string)($a['category_name'] ?? ''), (string)($b['category_name'] ?? ''));
});

$defaultScene = (string)($sceneCategories[0]['category_slug'] ?? 'selected');
$defaultSceneName = (string)($sceneCategories[0]['category_name'] ?? $defaultScene);
$defaultScenePreviews = scene_preview_urls($library->imagesForCategory($defaultScene), 24);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Create Scenes - Artwork Mockups</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <style>
        body {
            background: var(--bg);
        }
        .scene-flow-shell {
            min-height: 100vh;
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            align-items: start;
            padding: 18px;
        }
        .scene-flow {
            width: min(980px, 100%);
            margin: 0 auto;
            display: grid;
            align-content: start;
            gap: 18px;
        }
        .scene-flow-header {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 20px;
            padding: 10px 0 4px;
        }
        .scene-flow-header h1 {
            margin: 0;
            font-family: var(--font-serif);
            font-size: clamp(34px, 8vw, 58px);
            font-weight: 500;
            line-height: 0.92;
        }
        .scene-flow-header p {
            max-width: 420px;
            margin: 0;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.5;
        }
        .mobile-header-copy {
            display: none;
        }
        .mobile-selected-scene {
            display: none;
        }
        .mobile-scene-viewer {
            display: none;
        }
        .desktop-header-copy .desc-kicker,
        .desktop-header-copy .desc-instructions {
            display: block;
        }
        .desktop-header-copy .desc-kicker {
            margin-bottom: 8px;
            color: var(--muted);
            font-size: 14px;
        }
        .desktop-header-copy .desc-instructions {
            max-width: 900px;
            color: var(--accent);
            font-size: 16px;
            font-weight: 600;
        }
        .scene-panel {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: clamp(16px, 3vw, 26px);
            box-shadow: var(--shadow);
        }
        .flow-stage {
            display: grid;
            gap: 18px;
        }
        .flow-stage[hidden] {
            display: none;
        }
        .step-label {
            margin: 0 0 12px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: var(--accent);
        }
        .scene-stage-toolbar {
            display: flex;
            align-items: end;
            justify-content: space-between;
            gap: 18px;
            margin-bottom: 12px;
        }
        .scene-stage-toolbar .step-label {
            margin: 0;
        }
        .capture-grid {
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            gap: 18px;
            align-items: stretch;
        }
        .capture-card {
            position: relative;
            min-height: 360px;
            border: 1.5px dashed var(--line);
            border-radius: 8px;
            background: var(--surface-soft);
            display: grid;
            place-items: center;
            overflow: hidden;
            cursor: pointer;
        }
        .capture-card.has-preview {
            border-style: solid;
            background: #111;
            overflow: visible;
        }
        .capture-empty {
            display: grid;
            gap: 14px;
            justify-items: center;
            text-align: center;
            padding: 28px;
        }
        .capture-empty svg {
            width: 54px;
            height: 54px;
            color: var(--accent);
        }
        .capture-empty strong {
            font-size: clamp(20px, 5vw, 30px);
            font-family: var(--font-serif);
            font-weight: 500;
        }
        .capture-empty span {
            color: var(--muted);
            font-size: 13px;
        }
        .capture-preview {
            width: 100%;
            height: 100%;
            object-fit: contain;
            border-radius: inherit;
            display: none;
        }
        .capture-card.has-preview .capture-preview {
            display: block;
        }
        .capture-card.has-preview .capture-empty {
            display: none;
        }
        .capture-card input[type="file"] {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
        }
        .capture-card.has-preview input[type="file"] {
            pointer-events: none;
        }
        .capture-actions {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            align-content: start;
            align-items: start;
        }
        .capture-image-column {
            min-width: 0;
            display: grid;
            align-content: start;
        }
        .artwork-confirm-button {
            width: 100%;
            min-height: 58px;
            margin: 38px 0 0;
            border-color: #b77f86;
            border-radius: 8px;
            background: #b77f86;
            color: #fffaf7;
            box-shadow: 0 8px 20px rgba(183, 127, 134, 0.24);
        }
        .artwork-confirm-button:hover,
        .artwork-confirm-button:focus-visible {
            border-color: #9c6870;
            background: #9c6870;
            color: #fffaf7;
            box-shadow: 0 10px 24px rgba(156, 104, 112, 0.3);
        }
        .artwork-confirm-button[hidden] {
            display: none;
        }
        .primary-capture,
        .secondary-capture {
            width: 100%;
            min-height: 58px;
            margin: 0;
            border-radius: 8px;
            border: 1px solid var(--ink);
            font-family: var(--font-sans);
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
        }
        .primary-capture {
            width: 100%;
            aspect-ratio: 1 / 1;
            min-height: 0;
            background: var(--ink);
            color: var(--surface);
            display: grid;
            place-items: center;
            align-content: center;
            gap: 10px;
            padding: clamp(14px, 4vw, 28px);
            font-size: 11px;
            letter-spacing: .12em;
        }
        .primary-capture svg {
            width: clamp(30px, 7vw, 44px);
            height: clamp(30px, 7vw, 44px);
            stroke-width: 1.4;
            opacity: .94;
        }
        .secondary-capture {
            background: var(--surface);
            color: var(--ink);
            border-color: var(--line);
        }
        #galleryBtn {
            aspect-ratio: 1 / 1;
            min-height: 0;
            margin: 0;
            padding: clamp(14px, 4vw, 28px);
            border-color: transparent;
            background: rgba(183, 127, 134, 0.1);
            color: #9c6870;
            box-shadow: none;
            display: grid;
            place-items: center;
            align-content: center;
            gap: 7px;
            font-size: 10px;
            letter-spacing: .08em;
        }
        #galleryBtn:hover,
        #galleryBtn:focus-visible {
            border-color: rgba(183, 127, 134, 0.3);
            background: rgba(183, 127, 134, 0.16);
            color: #8d5c65;
            box-shadow: none;
        }
        .gallery-alternative {
            color: var(--muted);
            font-size: 8px;
            font-weight: 500;
            letter-spacing: .08em;
            text-transform: lowercase;
            opacity: .72;
        }
        .desktop-copy {
            display: block;
        }
        .mobile-copy {
            display: none;
        }
        .capture-orientation-picker {
            display: none;
            position: absolute;
            top: 0;
            left: 50%;
            right: auto;
            z-index: 4;
            transform: translate(-50%, -50%);
        }
        .capture-card.has-preview .capture-orientation-picker {
            display: block;
        }
        .capture-shape-pill {
            width: auto;
            min-height: 0;
            margin: 0;
            padding: 7px 10px 6px;
            border: 1px solid rgba(164, 128, 94, 0.35);
            border-radius: 999px;
            background: rgba(250, 248, 244, 0.64);
            color: var(--ink);
            display: inline-flex;
            align-items: center;
            gap: 7px;
            font-family: var(--font-sans);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            box-shadow: 0 10px 22px rgba(42, 35, 28, 0.12);
            backdrop-filter: blur(6px);
        }
        .capture-shape-pill:hover,
        .capture-shape-pill:focus-visible {
            background: rgba(250, 248, 244, 0.88);
            border-color: var(--accent);
            color: var(--ink);
            transform: none;
        }
        .capture-shape-chevron {
            width: 7px;
            height: 7px;
            border-right: 1.5px solid currentColor;
            border-bottom: 1.5px solid currentColor;
            transform: translateY(-2px) rotate(45deg);
            transition: transform .18s ease;
        }
        .capture-shape-pill[aria-expanded="true"] .capture-shape-chevron {
            transform: translateY(2px) rotate(225deg);
        }
        .capture-orientation-menu {
            position: absolute;
            top: calc(100% + 8px);
            left: 50%;
            right: auto;
            transform: translateX(-50%);
            min-width: 150px;
            padding: 5px;
            border: 1px solid rgba(164, 128, 94, 0.35);
            border-radius: 10px;
            background: rgba(250, 248, 244, 0.96);
            box-shadow: 0 14px 30px rgba(42, 35, 28, 0.18);
            backdrop-filter: blur(10px);
        }
        .capture-orientation-menu[hidden] {
            display: none;
        }
        .capture-orientation-option {
            width: 100%;
            min-height: 38px;
            margin: 0;
            padding: 9px 11px;
            border: 0;
            border-radius: 7px;
            background: transparent;
            color: var(--ink);
            box-shadow: none;
            text-align: left;
            font-family: var(--font-sans);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .1em;
            text-transform: uppercase;
        }
        .capture-orientation-option:hover,
        .capture-orientation-option:focus-visible,
        .capture-orientation-option.is-active {
            background: rgba(183, 127, 134, 0.14);
            color: var(--accent);
            box-shadow: none;
            transform: none;
        }
        .capture-measure-scrubber {
            display: none;
            position: absolute;
            z-index: 4;
            align-items: center;
            flex-direction: column;
            justify-content: center;
            gap: 0;
            width: 54px;
            height: 54px;
            min-height: 54px;
            margin: 0;
            padding: 5px;
            border: 1px solid rgba(164, 128, 94, 0.35);
            border-radius: 50%;
            background: rgba(250, 248, 244, 0.56);
            color: var(--ink);
            box-shadow: 0 6px 16px rgba(42, 35, 28, 0.1);
            backdrop-filter: blur(6px);
            font-family: var(--font-sans);
            font-size: 10px;
            font-weight: 600;
            line-height: 1;
            letter-spacing: 0;
            text-transform: none;
            -webkit-tap-highlight-color: transparent;
            user-select: none;
            touch-action: none;
            cursor: ns-resize;
            transition: background .12s ease, border-color .12s ease, box-shadow .12s ease;
        }
        .capture-card.has-preview .capture-measure-scrubber {
            display: inline-flex;
        }
        .capture-measure-width {
            left: 50%;
            bottom: 0;
            transform: translate(-50%, 50%);
        }
        .capture-measure-height {
            left: 0;
            top: 50%;
            transform: translate(-50%, -50%);
        }
        .capture-measure-scrubber:hover,
        .capture-measure-scrubber:focus-visible,
        .capture-measure-scrubber.is-dragging {
            background: rgba(250, 248, 244, 0.94);
            border-color: var(--accent);
            color: var(--ink);
            box-shadow: 0 8px 20px rgba(42, 35, 28, 0.16);
        }
        .capture-measure-axis {
            color: var(--muted);
            font-size: 7px;
            font-weight: 800;
            line-height: 1;
            letter-spacing: .06em;
            text-transform: uppercase;
        }
        .capture-measure-number {
            color: var(--ink);
            margin: 2px 0 1px;
            font-size: 16px;
            font-weight: 750;
            line-height: 1;
            text-align: center;
        }
        .capture-measure-scrubber [data-measure-unit] {
            color: var(--muted);
            font-size: 7px;
            font-weight: 700;
            line-height: 1;
            text-transform: lowercase;
        }
        .scene-grid {
            display: grid;
            gap: 10px;
        }
        .scene-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
            align-items: start;
        }
        .choice-card {
            position: relative;
            display: grid;
            gap: 8px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: var(--surface);
            min-height: 120px;
            padding: 14px;
            cursor: pointer;
        }
        .choice-card input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }
        .choice-card:has(input:checked) {
            border-color: var(--accent);
            box-shadow: 0 0 0 2px rgba(183, 127, 134, 0.18);
        }
        .choice-card strong {
            font-size: 15px;
        }
        .choice-card span {
            color: var(--muted);
            font-size: 12px;
            line-height: 1.35;
        }
        .real-size-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }
        .measure-field {
            display: grid;
            gap: 10px;
            padding: 14px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: var(--surface-soft);
        }
        .measure-field span {
            color: var(--muted);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .measure-field input {
            width: 100%;
            min-height: 44px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: var(--surface);
            color: var(--ink);
            padding: 0 11px;
            font: inherit;
        }
        .measure-field input[type="range"] {
            min-height: 0;
            padding: 0;
            border: none;
            background: transparent;
            accent-color: var(--accent);
        }
        .size-hint {
            margin: 12px 0 0;
            color: var(--muted);
            font-size: 13px;
        }
        .desktop-dimension-controls {
            display: none;
        }
        .scene-card {
            min-height: 190px;
            padding: 12px;
            overflow: visible;
            align-content: start;
            z-index: 1;
        }
        .scene-card-item {
            display: contents;
        }
        .scene-card:hover,
        .scene-card:focus-within {
            z-index: 30;
        }
        .scene-card-preview {
            width: 100%;
            aspect-ratio: 4 / 3;
            overflow: hidden;
            border: 1px solid var(--line);
            border-radius: 5px;
            background: var(--surface-soft);
        }
        .scene-card-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .scene-card-nested {
            display: grid;
            grid-auto-flow: column;
            grid-auto-columns: minmax(420px, 1fr);
            grid-template-columns: none;
            gap: 8px;
            position: absolute;
            left: 10px;
            right: auto;
            top: 10px;
            width: min(560px, calc(100vw - 72px));
            z-index: 4;
            max-height: 520px;
            overflow-x: auto;
            overflow-y: hidden;
            scroll-snap-type: x mandatory;
            padding: 10px;
            border: 1px solid rgba(154, 123, 86, 0.28);
            border-radius: 7px;
            background: rgba(250, 248, 244, 0.94);
            opacity: 0;
            pointer-events: none;
            transform: translateY(8px);
            transition: opacity .16s ease, transform .16s ease;
            box-shadow: 0 18px 38px rgba(20, 20, 18, .14);
            backdrop-filter: blur(10px);
            scrollbar-width: thin;
        }
        .scene-card-item:nth-child(3n) .scene-card-nested {
            left: auto;
            right: 10px;
        }
        @media (min-width: 1180px) {
            .scene-card-nested {
                width: min(640px, calc(100vw - 96px));
                grid-auto-columns: minmax(520px, 1fr);
            }
        }
        .scene-card:hover .scene-card-nested,
        .scene-card:focus-within .scene-card-nested {
            opacity: 1;
            pointer-events: auto;
            transform: translateY(0);
        }
        .scene-card-nested.is-loading {
            opacity: 0 !important;
            pointer-events: none !important;
        }
        .scene-card-nested img {
            width: 100%;
            aspect-ratio: 4 / 3;
            object-fit: cover;
            scroll-snap-align: start;
            border: 1px solid rgba(154, 123, 86, 0.22);
            border-radius: 4px;
            display: block;
            background: var(--surface-soft);
        }
        .scene-card-empty {
            display: grid;
            place-items: center;
            aspect-ratio: 4 / 3;
            border: 1px dashed var(--line);
            border-radius: 5px;
            color: var(--muted);
            font-size: 12px;
        }
        .scene-card-body {
            display: grid;
            gap: 4px;
            padding: 0;
        }
        .scene-card-meta {
            color: var(--muted);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }
        .submit-row {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 12px;
        }
        .submit-row button {
            min-height: 52px;
            padding: 0 22px;
            border-radius: 8px;
        }
        .submit-row .secondary-capture {
            width: auto;
            min-height: 52px;
            padding: 0 18px;
        }
        @media (max-width: 760px) {
            .scene-flow-shell {
                padding: 0 10px 12px;
            }
            .scene-flow {
                gap: 8px;
            }
            .scene-flow-header {
                display: grid;
                gap: 0;
                padding: 0 2px 2px;
            }
            .scene-flow-header h1 {
                display: none;
            }
            .desktop-header-copy {
                display: none;
            }
            .scene-flow-header .mobile-header-copy {
                display: -webkit-box;
                max-width: 340px;
                margin: 0 auto;
                font-size: 12px;
                line-height: 1.4;
                text-align: center;
                -webkit-box-orient: vertical;
                -webkit-line-clamp: 4;
                overflow: hidden;
            }
            .create-scenes-desktop-header,
            .create-scenes-alert {
                display: none;
            }
            .capture-grid {
                grid-template-columns: 1fr;
            }
            .capture-card {
                min-height: 340px;
            }
            .scene-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                column-gap: 6px;
                row-gap: 6px;
            }
            #sceneStage .scene-panel {
                padding: 12px 8px 14px;
            }
            .mobile-scene-viewer {
                display: grid;
                gap: 8px;
                margin: 0 0 10px;
                padding: 7px;
                border: 1px solid var(--accent);
                border-radius: 8px;
                background: var(--surface);
                box-shadow: 0 0 0 2px rgba(183, 127, 134, 0.12);
            }
            .mobile-scene-viewer-carousel {
                display: grid;
                grid-auto-flow: column;
                grid-auto-columns: minmax(220px, 78vw);
                gap: 7px;
                overflow-x: auto;
                overflow-y: hidden;
                padding: 0;
                scroll-snap-type: x mandatory;
                scrollbar-width: thin;
            }
            .mobile-scene-viewer-carousel img {
                width: 100%;
                aspect-ratio: 3 / 4;
                object-fit: cover;
                scroll-snap-align: start;
                border: 1px solid rgba(154, 123, 86, 0.22);
                border-radius: 4px;
                display: block;
                background: var(--surface-soft);
            }
            .mobile-scene-submit {
                width: 100%;
                min-height: 44px;
                margin: 0;
                padding: 8px 12px;
                border: 1px solid #b77f86;
                border-radius: 4px;
                background: #b77f86;
                color: #fffaf7;
                box-shadow: none;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                font-size: 10px;
                line-height: 1.25;
                letter-spacing: .1em;
                text-align: center;
            }
            .scene-card-item {
                position: relative;
                display: block;
                min-width: 0;
            }
            .scene-card-item .scene-card {
                height: auto;
                min-height: 0;
                gap: 4px;
                padding: 4px;
                transition: transform .1s ease, border-color .1s ease, background-color .1s ease;
            }
            .scene-card-item .scene-card:active {
                transform: scale(.985);
                background: var(--surface-soft);
            }
            .scene-card-item .scene-card-preview {
                aspect-ratio: 1 / 1;
                border-radius: 4px;
            }
            .scene-card-item .scene-card-body {
                gap: 0;
                min-width: 0;
                padding: 1px 2px;
            }
            .scene-card-item .scene-card-body strong {
                display: block;
                min-width: 0;
                overflow: hidden;
                font-size: 11px;
                line-height: 1.2;
                letter-spacing: .02em;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
            .scene-card:hover,
            .scene-card:focus-within {
                z-index: 1;
            }
            .scene-card-nested {
                display: none !important;
            }
            .real-size-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .desktop-scene-submit-panel {
                display: none;
            }
            .scene-card-meta {
                display: none;
            }
            .scene-style-count {
                display: none;
            }
            .mobile-selected-scene {
                display: inline;
                margin-left: 4px;
                color: var(--ink);
            }
            .desktop-copy {
                display: none;
            }
            .mobile-copy {
                display: block;
            }
            .submit-row {
                display: grid;
            }
            .submit-row button,
            .submit-row .secondary-capture {
                width: 100%;
            }
            .scene-stage-toolbar {
                align-items: stretch;
                flex-direction: column;
            }
        }
        @media (min-width: 761px) {
            .scene-flow-shell {
                min-height: 0;
                padding: 20px 30px 40px;
            }
            .scene-flow {
                width: min(1180px, 100%);
                margin: 0 auto;
                gap: 22px;
            }
            .scene-flow-header {
                display: grid;
                justify-content: stretch;
                align-items: start;
                gap: 0;
                padding: 6px 0 24px;
                border-bottom: 1px solid var(--line);
            }
            .scene-flow-header h1 {
                margin: 0 0 18px;
                font-size: clamp(36px, 3vw, 46px);
                line-height: 1;
            }
            .scene-flow-header p {
                max-width: 920px;
                line-height: 1.55;
            }
            #artworkStage .scene-panel {
                padding: 20px;
            }
            #artworkStage .capture-grid {
                width: min(820px, 100%);
                margin-inline: auto;
                justify-self: center;
                grid-template-columns: minmax(0, 1fr);
                gap: 14px;
            }
            #artworkStage .capture-card {
                width: 100%;
                height: clamp(360px, 48vh, 440px);
                min-height: 0;
            }
            #artworkStage .capture-card.has-preview {
                overflow: hidden;
            }
            #artworkStage .capture-card.has-preview .capture-preview {
                position: absolute;
                inset: 0;
                width: 100%;
                height: 100%;
                object-fit: contain;
            }
            #artworkStage .capture-orientation-picker,
            #artworkStage .capture-measure-scrubber {
                display: none !important;
            }
            #artworkStage .desktop-dimension-controls {
                width: min(620px, 100%);
                margin: 12px auto 0;
                display: grid;
                grid-template-columns: minmax(0, 1fr) 28px minmax(0, 1fr) minmax(0, 1fr);
                align-items: end;
                gap: 10px;
            }
            .desktop-dimension-field {
                display: grid;
                gap: 5px;
                color: var(--muted);
                font-size: 10px;
                font-weight: 700;
                letter-spacing: .1em;
                text-transform: uppercase;
            }
            .desktop-dimension-input {
                display: grid;
                grid-template-columns: minmax(0, 1fr) auto;
                align-items: center;
                min-height: 42px;
                padding: 0 12px;
                border: 1px solid var(--line);
                border-radius: 6px;
                background: var(--surface-soft);
            }
            .desktop-dimension-input input {
                width: 100%;
                min-width: 0;
                border: 0;
                outline: 0;
                background: transparent;
                color: var(--ink);
                font: inherit;
                font-size: 14px;
            }
            .desktop-dimension-input [data-measure-unit] {
                color: var(--muted);
                font-size: 10px;
                text-transform: lowercase;
            }
            .desktop-orientation-toggle {
                align-self: end;
                width: 28px;
                height: 42px;
                min-height: 42px;
                margin: 0;
                padding: 0;
                border: 0;
                border-radius: 0;
                background: transparent;
                color: var(--accent);
                box-shadow: none;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                line-height: 0;
                cursor: pointer;
                transition: color .18s ease, opacity .18s ease, transform .18s ease;
            }
            .desktop-orientation-toggle:hover,
            .desktop-orientation-toggle:focus-visible {
                color: var(--ink);
                background: transparent;
                box-shadow: none;
                opacity: .8;
            }
            .desktop-orientation-toggle:focus-visible {
                outline: 1px solid currentColor;
                outline-offset: 2px;
            }
            .desktop-orientation-toggle:active {
                transform: scale(.95);
            }
            .desktop-orientation-toggle svg {
                width: 20px;
                height: 20px;
            }
            #artworkStage .artwork-confirm-button {
                margin-top: 12px;
            }
            #artworkStage .capture-actions {
                width: min(250px, 100%);
                justify-self: center;
                grid-template-columns: minmax(0, 1fr);
                gap: 10px;
            }
            #artworkStage #galleryBtn {
                display: none;
            }
            #artworkStage .primary-capture,
            #artworkStage #galleryBtn {
                aspect-ratio: auto;
                min-height: 76px;
                padding: 12px 20px;
            }
            #artworkStage .primary-capture svg {
                width: 28px;
                height: 28px;
            }
        }
        @media (max-width: 980px) {
            .create-scenes-page .sidebar-tabs {
                display: none !important;
            }
        }
    </style>
</head>
<body class="create-scenes-page">
<div class="app-shell">
<?php include __DIR__ . '/sidebar.php'; ?>
<main class="main-area">
    <header class="app-header create-scenes-desktop-header">
        <a class="user-chip" href="account.php"><?= h($user['email'] ?? '') ?></a>
    </header>
    <div class="alert-strip create-scenes-alert">Prepare your artwork image before choosing a scene style.</div>
    <div class="scene-flow-shell">
    <form class="scene-flow" action="start_generate.php" method="post" enctype="multipart/form-data" id="createScenesForm">
        <input type="hidden" name="user_scene_flow" value="1">
        <input type="hidden" name="scene_board" value="1">
        <input type="hidden" name="scene_limit" value="4">
        <input type="hidden" name="generation_provider" value="gemini">
        <input type="hidden" name="unit" id="measureUnitInput" value="cm">
        <input type="hidden" name="real_dimensions_enabled" value="1">

        <header class="scene-flow-header">
            <h1>Create Art</h1>
            <p class="desktop-header-copy">
                <span class="desc-kicker">Create realistic scene mockups from a clear photograph or image file.</span>
                <span class="desc-instructions">Upload the artwork, confirm its orientation and approximate dimensions, then choose the visual environment for the first mockups.</span>
            </p>
            <p class="mobile-header-copy">Adjust the artwork width and height on the photo. They do not need to be exact—an approximate size helps create more realistic mockups.</p>
        </header>

        <div class="flow-stage" id="artworkStage">
            <section class="scene-panel">
                <p class="step-label">Artwork image</p>
                <div class="capture-grid">
                    <div class="capture-image-column">
                        <div class="capture-card" id="captureCard">
                        <div class="capture-empty">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 7h3l1.4-2h7.2L17 7h3v12H4V7Z"/>
                                <circle cx="12" cy="13" r="4" stroke-width="1.5"/>
                            </svg>
                            <strong><span class="desktop-copy">Upload artwork image</span><span class="mobile-copy">Take a photo of the artwork</span></strong>
                            <span>Use a clear, well-lit image. You can confirm it before choosing a scene.</span>
                        </div>
                        <img class="capture-preview" id="capturePreview" alt="Selected artwork preview">
                        <div class="capture-orientation-picker" id="orientationPicker">
                            <button type="button" class="capture-shape-pill" id="shapePill" aria-haspopup="menu" aria-expanded="false" aria-controls="orientationMenu">
                                <span id="shapePillText">Vertical</span>
                                <span class="capture-shape-chevron" aria-hidden="true"></span>
                            </button>
                            <div class="capture-orientation-menu" id="orientationMenu" role="menu" hidden>
                                <button type="button" class="capture-orientation-option" data-orientation="vertical" role="menuitemradio">Vertical</button>
                                <button type="button" class="capture-orientation-option" data-orientation="horizontal" role="menuitemradio">Horizontal</button>
                                <button type="button" class="capture-orientation-option" data-orientation="square" role="menuitemradio">Square</button>
                            </div>
                        </div>
                        <button type="button" class="capture-measure-scrubber capture-measure-height" data-measure-target="height" role="slider" aria-label="Artwork height in centimeters. Swipe up to increase and down to decrease." aria-valuemin="1" aria-valuemax="300" aria-valuenow="120">
                            <span class="capture-measure-axis">H</span>
                            <strong class="capture-measure-number" data-measure-output="height">120</strong>
                            <span data-measure-unit>cm</span>
                        </button>
                        <input type="hidden" name="height" id="realHeightInput" value="120">
                        <button type="button" class="capture-measure-scrubber capture-measure-width" data-measure-target="width" role="slider" aria-label="Artwork width in centimeters. Swipe up to increase and down to decrease." aria-valuemin="1" aria-valuemax="300" aria-valuenow="80">
                            <span class="capture-measure-axis">W</span>
                            <strong class="capture-measure-number" data-measure-output="width">80</strong>
                            <span data-measure-unit>cm</span>
                        </button>
                        <input type="hidden" name="width" id="realWidthInput" value="80">
                            <input id="cameraInput" type="file" name="main_artwork" accept="image/*" capture="environment" required>
                        </div>
                        <div class="desktop-dimension-controls" aria-label="Artwork dimensions">
                            <label class="desktop-dimension-field" for="desktopWidthInput">
                                <span>Width</span>
                                <span class="desktop-dimension-input">
                                    <input type="number" id="desktopWidthInput" value="80" min="1" max="300" step="1" inputmode="decimal">
                                    <span data-measure-unit>cm</span>
                                </span>
                            </label>
                            <button type="button" class="desktop-orientation-toggle" id="desktopOrientationToggle" aria-label="Switch to horizontal orientation" title="Switch to horizontal orientation">
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M7 7h11m0 0-3-3m3 3-3 3M17 17H6m0 0 3 3m-3-3 3-3"/>
                                </svg>
                            </button>
                            <label class="desktop-dimension-field" for="desktopHeightInput">
                                <span>Height</span>
                                <span class="desktop-dimension-input">
                                    <input type="number" id="desktopHeightInput" value="120" min="1" max="300" step="1" inputmode="decimal">
                                    <span data-measure-unit>cm</span>
                                </span>
                            </label>
                            <label class="desktop-dimension-field" for="desktopDepthInput">
                                <span>Depth (optional)</span>
                                <span class="desktop-dimension-input">
                                    <input type="number" id="desktopDepthInput" name="depth" value="3" min="0" max="300" step="1" inputmode="decimal" placeholder="Optional" disabled>
                                    <span data-measure-unit>cm</span>
                                </span>
                            </label>
                        </div>
                        <button type="button" class="artwork-confirm-button" id="continueToSceneBtn" hidden disabled>Use this artwork</button>
                        <span id="sizeHint" hidden>Swipe up or down on H and W to adjust the real artwork size.</span>
                    </div>
                    <div class="capture-actions">
                        <button type="button" class="primary-capture" id="primaryCaptureBtn">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 7h3l1.4-2h7.2L17 7h3v12H4V7Z"/>
                                <circle cx="12" cy="13" r="4"/>
                            </svg>
                            <span class="desktop-copy">Upload image</span>
                            <span class="mobile-copy">Take photo</span>
                        </button>
                        <button type="button" class="secondary-capture" id="galleryBtn">
                            <span class="gallery-alternative">or</span>
                            <span>Choose from gallery</span>
                        </button>
                    </div>
                </div>
            </section>
        </div>

        <div class="flow-stage" id="sceneStage" hidden>
            <section class="scene-panel">
                <div class="scene-stage-toolbar">
                    <p class="step-label">Scene style<span class="mobile-selected-scene">: <span id="selectedSceneTitle"><?= h($defaultSceneName) ?></span></span><span class="scene-style-count"> - <?= count($sceneCategories) ?> styles</span></p>
                </div>
                <div class="mobile-scene-viewer" id="mobileSceneViewer">
                    <div class="mobile-scene-viewer-carousel" id="mobileSceneCarousel" aria-live="polite">
                        <?php foreach ($defaultScenePreviews as $previewIndex => $preview): ?>
                            <img src="<?= h($preview['url']) ?>" alt="<?= h($preview['title']) ?>" loading="<?= $previewIndex === 0 ? 'eager' : 'lazy' ?>" decoding="async">
                        <?php endforeach; ?>
                    </div>
                    <button type="submit" class="mobile-scene-submit">Create Mockups</button>
                </div>
                <div class="scene-grid">
                    <?php foreach ($sceneCategories as $index => $category): ?>
                        <?php
                        $slug = (string)$category['category_slug'];
                        $images = $library->imagesForCategory($slug);
                        $previews = scene_preview_urls($images, 24);
                        $mainPreview = $previews[0] ?? null;
                        $imageCount = (int)($category['image_count'] ?? count($images));
                        ?>
                        <div class="scene-card-item<?= $slug === $defaultScene ? ' is-selected' : '' ?>">
                            <label class="choice-card scene-card">
                                <input type="radio" name="scene_category" value="<?= h($slug) ?>" <?= $slug === $defaultScene ? 'checked' : '' ?>>
                                <?php if ($mainPreview): ?>
                                    <span class="scene-card-preview">
                                        <img src="<?= h($mainPreview['url']) ?>" alt="<?= h($mainPreview['title']) ?>" loading="lazy" decoding="async">
                                    </span>
                                    <?php if (count($previews) > 1): ?>
                                    <span class="scene-card-nested" aria-label="<?= h((string)($category['category_name'] ?? $slug)) ?> references">
                                        <?php foreach ($previews as $preview): ?>
                                            <img data-src="<?= h($preview['url']) ?>" alt="<?= h($preview['title']) ?>" decoding="async">
                                        <?php endforeach; ?>
                                    </span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="scene-card-empty">No references yet</span>
                                <?php endif; ?>
                                <span class="scene-card-body">
                                    <strong><?= h((string)($category['category_name'] ?? $slug)) ?></strong>
                                    <span class="scene-card-meta"><?= $imageCount ?> references</span>
                                </span>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
            <section class="scene-panel desktop-scene-submit-panel">
                <div class="submit-row">
                    <button type="button" class="secondary-capture" id="backToArtworkBtn">Change artwork</button>
                    <button type="submit" class="button">Create 4 scenes</button>
                </div>
            </section>
        </div>
    </form>
    </div>
</main>
</div>

<script>
const cameraInput = document.getElementById('cameraInput');
const captureCard = document.getElementById('captureCard');
const capturePreview = document.getElementById('capturePreview');
const artworkStage = document.getElementById('artworkStage');
const sceneStage = document.getElementById('sceneStage');
const continueToSceneBtn = document.getElementById('continueToSceneBtn');
const backToArtworkBtn = document.getElementById('backToArtworkBtn');
const shapePill = document.getElementById('shapePill');
const shapePillText = document.getElementById('shapePillText');
const orientationPicker = document.getElementById('orientationPicker');
const orientationMenu = document.getElementById('orientationMenu');
const orientationOptions = Array.from(document.querySelectorAll('[data-orientation]'));
const realWidthInput = document.getElementById('realWidthInput');
const realHeightInput = document.getElementById('realHeightInput');
const desktopWidthInput = document.getElementById('desktopWidthInput');
const desktopHeightInput = document.getElementById('desktopHeightInput');
const desktopDepthInput = document.getElementById('desktopDepthInput');
const desktopOrientationToggle = document.getElementById('desktopOrientationToggle');
const measureScrubbers = Array.from(document.querySelectorAll('.capture-measure-scrubber[data-measure-target]'));
const measureOutputs = {
    width: document.querySelector('[data-measure-output="width"]'),
    height: document.querySelector('[data-measure-output="height"]'),
};
const measureUnitInput = document.getElementById('measureUnitInput');
const sizeHint = document.getElementById('sizeHint');
const selectedSceneTitle = document.getElementById('selectedSceneTitle');
const mobileSceneCarousel = document.getElementById('mobileSceneCarousel');
const sceneCategoryInputs = Array.from(document.querySelectorAll('input[name="scene_category"]'));
const sceneCards = Array.from(document.querySelectorAll('.scene-card'));
let artworkReady = false;
let mobileSceneRenderToken = 0;
const desktopLayoutQuery = window.matchMedia('(min-width: 761px)');

measureScrubbers.forEach(bindMeasureScrubber);
[desktopWidthInput, desktopHeightInput].forEach(input => {
    if (!input) return;
    input.addEventListener('input', () => applyDesktopDimension(input));
    input.addEventListener('blur', () => {
        applyDesktopDimension(input);
        const target = input === desktopHeightInput ? 'height' : 'width';
        input.value = formatMeasure(readMeasure(target));
    });
});
if (desktopOrientationToggle) {
    desktopOrientationToggle.addEventListener('click', toggleDesktopOrientation);
}
const syncDesktopDepthAvailability = () => {
    if (desktopDepthInput) {
        desktopDepthInput.disabled = !desktopLayoutQuery.matches;
    }
};
syncDesktopDepthAvailability();
if (typeof desktopLayoutQuery.addEventListener === 'function') {
    desktopLayoutQuery.addEventListener('change', syncDesktopDepthAvailability);
}
setMeasureUnit(detectMeasureUnit());
sceneCategoryInputs.forEach(input => input.addEventListener('change', () => updateSelectedSceneTitle(true)));
sceneCards.forEach(card => {
    card.addEventListener('pointerenter', () => hydrateDesktopSceneCard(card), { passive: true });
    card.addEventListener('focusin', () => hydrateDesktopSceneCard(card));
});
updateSelectedSceneTitle(false);

if (window.history && window.history.replaceState) {
    window.history.replaceState({ createScenesStage: 'artwork' }, '', window.location.href);
}

document.getElementById('primaryCaptureBtn').addEventListener('click', () => {
    cameraInput.setAttribute('capture', 'environment');
    cameraInput.click();
});
document.getElementById('galleryBtn').addEventListener('click', () => {
    cameraInput.removeAttribute('capture');
    cameraInput.click();
});

shapePill.addEventListener('click', event => {
    event.stopPropagation();
    setOrientationMenuOpen(orientationMenu.hidden);
});
shapePill.addEventListener('keydown', event => {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        setOrientationMenuOpen(true, true);
    }
});
orientationOptions.forEach(option => {
    option.addEventListener('click', event => {
        event.stopPropagation();
        applyOrientation(option.dataset.orientation);
        setOrientationMenuOpen(false);
        shapePill.focus();
    });
});
orientationMenu.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
        event.preventDefault();
        setOrientationMenuOpen(false);
        shapePill.focus();
        return;
    }
    if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') {
        return;
    }
    event.preventDefault();
    const currentIndex = orientationOptions.indexOf(document.activeElement);
    const direction = event.key === 'ArrowDown' ? 1 : -1;
    const nextIndex = (currentIndex + direction + orientationOptions.length) % orientationOptions.length;
    orientationOptions[nextIndex].focus();
});
document.addEventListener('click', event => {
    if (!orientationPicker.contains(event.target)) {
        setOrientationMenuOpen(false);
    }
});

cameraInput.addEventListener('change', () => previewFile(cameraInput.files[0]));
continueToSceneBtn.addEventListener('click', () => {
    const hasSelectedFile = cameraInput.files && cameraInput.files.length > 0;
    if (!artworkReady && !hasSelectedFile) {
        captureCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
    }
    showSceneStage(true);
});
backToArtworkBtn.addEventListener('click', () => {
    if (window.history && window.history.state && window.history.state.createScenesStage === 'scene') {
        window.history.back();
        return;
    }
    showArtworkStage(false);
});

function showSceneStage(pushHistory = false) {
    artworkStage.hidden = true;
    sceneStage.hidden = false;
    if (pushHistory && window.history && window.history.pushState) {
        window.history.pushState({ createScenesStage: 'scene' }, '', window.location.href);
    }
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function showArtworkStage(pushHistory = false) {
    sceneStage.hidden = true;
    artworkStage.hidden = false;
    if (pushHistory && window.history && window.history.pushState) {
        window.history.pushState({ createScenesStage: 'artwork' }, '', window.location.href);
    }
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function updateSelectedSceneTitle(refreshMobileViewer = true) {
    const selectedInput = sceneCategoryInputs.find(input => input.checked);
    const selectedCard = selectedInput ? selectedInput.closest('.scene-card-item') : null;
    sceneCategoryInputs.forEach(input => {
        const card = input.closest('.scene-card-item');
        if (card) {
            card.classList.toggle('is-selected', input === selectedInput);
        }
    });
    const title = selectedCard ? selectedCard.querySelector('.scene-card-body strong') : null;
    if (selectedSceneTitle && title) {
        selectedSceneTitle.textContent = title.textContent.trim();
    }
    if (refreshMobileViewer && selectedCard) {
        renderMobileSceneViewer(selectedCard);
    }
}

function renderMobileSceneViewer(selectedCard) {
    if (!mobileSceneCarousel || !window.matchMedia('(max-width: 760px)').matches) {
        return;
    }

    const nestedImages = Array.from(selectedCard.querySelectorAll('.scene-card-nested img'));
    const mainImage = selectedCard.querySelector('.scene-card-preview img');
    const references = nestedImages.length > 0
        ? nestedImages.map(image => ({ src: image.dataset.src || image.currentSrc || image.src, alt: image.alt }))
        : (mainImage ? [{ src: mainImage.currentSrc || mainImage.src, alt: mainImage.alt }] : []);
    const usableReferences = references.filter(reference => reference.src);
    const renderToken = ++mobileSceneRenderToken;

    if (usableReferences.length === 0) {
        mobileSceneCarousel.replaceChildren();
        return;
    }

    const fragment = document.createDocumentFragment();
    const images = usableReferences.map((reference, index) => {
        const image = document.createElement('img');
        image.alt = reference.alt || 'Scene reference';
        image.decoding = 'async';
        image.loading = index === 0 ? 'eager' : 'lazy';
        fragment.appendChild(image);
        return image;
    });
    let committed = false;
    const commit = () => {
        if (committed || renderToken !== mobileSceneRenderToken) {
            return;
        }
        committed = true;
        images.forEach((image, index) => {
            if (!image.src) {
                image.src = usableReferences[index].src;
            }
        });
        mobileSceneCarousel.replaceChildren(fragment);
        mobileSceneCarousel.scrollLeft = 0;
    };

    images[0].addEventListener('load', commit, { once: true });
    images[0].addEventListener('error', commit, { once: true });
    images[0].src = usableReferences[0].src;
    if (images[0].complete) {
        queueMicrotask(commit);
    }
}

function hydrateDesktopSceneCard(card) {
    if (!window.matchMedia('(min-width: 761px)').matches) {
        return;
    }
    const nested = card.querySelector('.scene-card-nested');
    const pendingImages = nested ? Array.from(nested.querySelectorAll('img[data-src]')) : [];
    if (!nested || pendingImages.length === 0) {
        return;
    }
    nested.classList.add('is-loading');
    const reveal = () => nested.classList.remove('is-loading');
    pendingImages[0].addEventListener('load', reveal, { once: true });
    pendingImages[0].addEventListener('error', reveal, { once: true });
    pendingImages.forEach(image => {
        image.src = image.dataset.src;
        image.removeAttribute('data-src');
        image.loading = 'lazy';
    });
    if (pendingImages[0].complete) {
        queueMicrotask(reveal);
    }
}

window.addEventListener('popstate', event => {
    const stage = event.state && event.state.createScenesStage;
    if (stage === 'scene') {
        showSceneStage(false);
        return;
    }
    showArtworkStage(false);
});

function previewFile(file) {
    if (!file) return;
    const reader = new FileReader();
    reader.onload = event => {
        artworkReady = true;
        capturePreview.src = event.target.result;
        captureCard.classList.add('has-preview');
        continueToSceneBtn.hidden = false;
        continueToSceneBtn.disabled = false;
    };
    reader.readAsDataURL(file);
}

function bindMeasureScrubber(button) {
    const target = button.dataset.measureTarget;
    let dragging = false;
    let startY = 0;
    let startValue = 0;

    const stopDragging = () => {
        dragging = false;
        button.classList.remove('is-dragging');
    };

    button.addEventListener('pointerdown', event => {
        if (event.button !== 0) return;
        event.preventDefault();
        dragging = true;
        startY = event.clientY;
        startValue = readMeasure(target);
        button.classList.add('is-dragging');
        button.focus({ preventScroll: true });
        if (button.setPointerCapture) {
            button.setPointerCapture(event.pointerId);
        }
    });
    button.addEventListener('pointermove', event => {
        if (!dragging) return;
        event.preventDefault();
        const steps = Math.round((startY - event.clientY) / 9);
        const nextValue = normalizeMeasure(startValue + (steps * measureIncrement()), startValue);
        if (nextValue !== readMeasure(target)) {
            writeMeasure(target, nextValue);
            if (navigator.vibrate) navigator.vibrate(3);
        }
    });
    button.addEventListener('pointerup', stopDragging);
    button.addEventListener('pointercancel', stopDragging);
    button.addEventListener('lostpointercapture', stopDragging);
    button.addEventListener('keydown', event => {
        const increase = event.key === 'ArrowUp' || event.key === 'ArrowRight';
        const decrease = event.key === 'ArrowDown' || event.key === 'ArrowLeft';
        if (!increase && !decrease) return;
        event.preventDefault();
        adjustMeasure(target, increase ? 1 : -1);
    });
}

function readMeasure(target) {
    const input = target === 'height' ? realHeightInput : realWidthInput;
    return Number.parseFloat(String(input.value).replace(',', '.')) || 1;
}

function writeMeasure(target, value) {
    const input = target === 'height' ? realHeightInput : realWidthInput;
    input.value = roundMeasure(normalizeMeasure(value, readMeasure(target)));
    updateSizeHint();
}

function adjustMeasure(target, direction) {
    writeMeasure(target, readMeasure(target) + (measureIncrement() * direction));
    if (navigator.vibrate) navigator.vibrate(4);
}

function measureIncrement(unit = measureUnitInput.value) {
    return 1;
}

function measureMaximum(unit = measureUnitInput.value) {
    return unit === 'in' ? 120 : 300;
}

function roundMeasure(value, unit = measureUnitInput.value) {
    const increment = measureIncrement(unit);
    return Math.round(value / increment) * increment;
}

function formatMeasure(value) {
    return Math.round(Number(value)).toString();
}

function normalizeMeasure(value, fallback, unit = measureUnitInput.value) {
    const normalized = String(value).replace(',', '.');
    const number = Number.parseFloat(normalized);
    if (!Number.isFinite(number)) {
        return fallback;
    }
    return Math.max(measureIncrement(unit), Math.min(measureMaximum(unit), number));
}

function applyDesktopDimension(input) {
    const target = input === desktopHeightInput ? 'height' : 'width';
    const fallback = readMeasure(target);
    const value = roundMeasure(normalizeMeasure(input.value, fallback));
    const hiddenInput = target === 'height' ? realHeightInput : realWidthInput;
    hiddenInput.value = value;
    updateSizeHint();
}

function toggleDesktopOrientation() {
    const width = readMeasure('width');
    const height = readMeasure('height');

    if (width === height) {
        applyOrientation('vertical');
        return;
    }

    realWidthInput.value = roundMeasure(normalizeMeasure(height, 80));
    realHeightInput.value = roundMeasure(normalizeMeasure(width, 120));
    updateSizeHint();
}

function detectMeasureUnit() {
    const locale = (navigator.languages && navigator.languages[0]) || navigator.language || '';
    let region = '';
    try {
        const resolvedLocale = new Intl.Locale(locale);
        region = resolvedLocale.region || resolvedLocale.maximize().region || '';
    } catch (error) {
        region = locale.split('-')[1] || '';
    }
    return ['US', 'LR', 'MM'].includes(region.toUpperCase()) ? 'in' : 'cm';
}

function setMeasureUnit(unit) {
    const normalizedUnit = unit === 'in' ? 'in' : 'cm';
    const currentUnit = measureUnitInput.value === 'in' ? 'in' : 'cm';

    if (normalizedUnit !== currentUnit) {
        const conversionFactor = currentUnit === 'cm' ? (1 / 2.54) : 2.54;
        const width = normalizeMeasure(readMeasure('width') * conversionFactor, readMeasure('width'), normalizedUnit);
        const height = normalizeMeasure(readMeasure('height') * conversionFactor, readMeasure('height'), normalizedUnit);
        measureUnitInput.value = normalizedUnit;
        realWidthInput.value = Math.round(width);
        realHeightInput.value = Math.round(height);
    }

    updateSizeHint();
}

function setOrientationMenuOpen(open, focusSelected = false) {
    orientationMenu.hidden = !open;
    shapePill.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open && focusSelected) {
        const selectedOption = orientationOptions.find(option => option.classList.contains('is-active')) || orientationOptions[0];
        selectedOption.focus();
    }
}

function applyOrientation(orientation) {
    let width = Number.parseFloat(String(realWidthInput.value).replace(',', '.')) || 80;
    let height = Number.parseFloat(String(realHeightInput.value).replace(',', '.')) || 120;

    if (orientation === 'square') {
        const side = Math.max(width, height);
        width = side;
        height = side;
    } else if (orientation === 'horizontal' && width <= height) {
        if (width < height) {
            [width, height] = [height, width];
        } else if (width < 300) {
            width = Math.min(300, width * 4 / 3);
        } else {
            height = width * 3 / 4;
        }
    } else if (orientation === 'vertical' && height <= width) {
        if (height < width) {
            [width, height] = [height, width];
        } else if (height < 300) {
            height = Math.min(300, height * 4 / 3);
        } else {
            width = height * 3 / 4;
        }
    }

    width = roundMeasure(normalizeMeasure(width, 80));
    height = roundMeasure(normalizeMeasure(height, 120));
    realWidthInput.value = width;
    realHeightInput.value = height;
    updateSizeHint();
}

function updateSizeHint() {
    const width = Number.parseFloat(String(realWidthInput.value).replace(',', '.')) || 0;
    const height = Number.parseFloat(String(realHeightInput.value).replace(',', '.')) || 0;
    let orientation = 'square';
    if (width > height) {
        orientation = 'horizontal';
    } else if (height > width) {
        orientation = 'vertical';
    }
    shapePillText.textContent = orientation.charAt(0).toUpperCase() + orientation.slice(1);
    if (desktopOrientationToggle) {
        const nextOrientation = orientation === 'horizontal' ? 'vertical' : 'horizontal';
        const toggleLabel = `Switch to ${nextOrientation} orientation`;
        desktopOrientationToggle.setAttribute('aria-label', toggleLabel);
        desktopOrientationToggle.title = toggleLabel;
    }
    orientationOptions.forEach(option => {
        const isActive = option.dataset.orientation === orientation;
        option.classList.toggle('is-active', isActive);
        option.setAttribute('aria-checked', isActive ? 'true' : 'false');
    });
    const unit = measureUnitInput.value === 'in' ? 'in' : 'cm';
    if (desktopWidthInput) {
        desktopWidthInput.max = measureMaximum(unit);
        desktopWidthInput.step = measureIncrement(unit);
        if (document.activeElement !== desktopWidthInput) {
            desktopWidthInput.value = formatMeasure(width);
        }
    }
    if (desktopHeightInput) {
        desktopHeightInput.max = measureMaximum(unit);
        desktopHeightInput.step = measureIncrement(unit);
        if (document.activeElement !== desktopHeightInput) {
            desktopHeightInput.value = formatMeasure(height);
        }
    }
    if (desktopDepthInput) {
        desktopDepthInput.max = measureMaximum(unit);
        desktopDepthInput.step = measureIncrement(unit);
    }
    measureOutputs.width.textContent = formatMeasure(width);
    measureOutputs.height.textContent = formatMeasure(height);
    document.querySelectorAll('[data-measure-unit]').forEach(label => {
        label.textContent = unit;
    });
    measureScrubbers.forEach(scrubber => {
        const target = scrubber.dataset.measureTarget;
        const value = target === 'height' ? height : width;
        scrubber.setAttribute('aria-valuenow', formatMeasure(value));
        scrubber.setAttribute('aria-valuemax', measureMaximum(unit));
        scrubber.setAttribute('aria-valuetext', `${formatMeasure(value)} ${unit}`);
        scrubber.setAttribute('aria-label', `Artwork ${target} in ${unit}. Swipe up to increase and down to decrease.`);
    });
    sizeHint.textContent = `Current size: ${formatMeasure(width)} × ${formatMeasure(height)} ${unit}. Swipe up or down on H and W to adjust.`;
}
</script>
</body>
</html>

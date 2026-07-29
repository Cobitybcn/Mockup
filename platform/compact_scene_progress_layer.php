<?php
// The sidebar renders this layer on every authenticated page so background
// scenes can be recovered anywhere. Pages that also include it directly must
// not paint a second copy.
if (defined('COMPACT_SCENE_PROGRESS_LAYER_RENDERED')) {
    return;
}
define('COMPACT_SCENE_PROGRESS_LAYER_RENDERED', true);

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}
?>
<style>
    .compact-scene-progress-layer {
        position: fixed;
        right: 18px;
        bottom: 18px;
        z-index: 1500;
        display: grid;
        grid-template-rows: auto minmax(0, 1fr);
        width: min(440px, calc(100vw - 36px));
        height: min(640px, calc(100vh - 96px));
        overflow: hidden;
        border: 1px solid rgba(205, 196, 184, .92);
        border-radius: 10px;
        background: rgba(255, 254, 252, .97);
        box-shadow: 0 18px 48px rgba(40, 34, 28, .18);
        transition: height .2s ease, width .2s ease, transform .2s ease;
    }
    .compact-scene-progress-layer[hidden],
    .compact-scene-progress-reopen[hidden] {
        display: none !important;
    }
    .compact-scene-progress-layer.is-minimized {
        width: min(360px, calc(100vw - 36px));
        height: 58px;
    }
    .compact-scene-progress-layer.is-complete .compact-scene-progress-head {
        background: linear-gradient(135deg, #e4f0df, #f1f6ed);
        border-bottom-color: rgba(127, 158, 116, .3);
    }
    .compact-scene-progress-layer.has-errors .compact-scene-progress-head {
        background: linear-gradient(135deg, #f5ead3, #fbf5e8);
        border-bottom-color: rgba(177, 139, 72, .3);
    }
    .compact-scene-progress-head {
        position: relative;
        z-index: 2;
        min-height: 57px;
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        align-items: center;
        gap: 12px;
        padding: 0 10px 0 15px;
        border-bottom: 1px solid rgba(222, 216, 207, .88);
        background: linear-gradient(135deg, #f6eee9, #f3f6ee);
    }
    .compact-scene-progress-title {
        min-width: 0;
        display: flex;
        align-items: center;
        gap: 9px;
        color: var(--ink, #171714);
        font-size: 12px;
        font-weight: 700;
        letter-spacing: .01em;
    }
    .compact-scene-progress-title span:last-child {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .compact-scene-progress-live {
        width: 9px;
        height: 9px;
        flex: 0 0 auto;
        border: 2px solid #b77f86;
        border-top-color: transparent;
        border-radius: 50%;
        animation: compact-scene-spin .9s linear infinite;
    }
    .compact-scene-progress-layer.is-complete .compact-scene-progress-live,
    .compact-scene-progress-reopen.is-complete .compact-scene-progress-live {
        border-color: #72936a;
        background: #91b389;
        animation: none;
    }
    .compact-scene-progress-layer.has-errors .compact-scene-progress-live,
    .compact-scene-progress-reopen.has-errors .compact-scene-progress-live {
        border-color: #b18b48;
        background: #d7b66f;
        animation: none;
    }
    .compact-scene-progress-actions {
        display: flex;
        gap: 4px;
    }
    .compact-scene-progress-action {
        width: 34px;
        height: 34px;
        display: inline-grid;
        place-items: center;
        padding: 0;
        border: 1px solid transparent;
        border-radius: 6px;
        background: transparent;
        color: #6f655d;
        cursor: pointer;
    }
    .compact-scene-progress-action:hover,
    .compact-scene-progress-action:focus-visible {
        border-color: rgba(183, 127, 134, .3);
        background: rgba(255, 255, 255, .72);
        color: #8d5e67;
        outline: none;
    }
    .compact-scene-progress-action svg {
        width: 17px;
        height: 17px;
    }
    .compact-scene-progress-action .icon-expand,
    .compact-scene-progress-layer.is-minimized .compact-scene-progress-action .icon-minimize {
        display: none;
    }
    .compact-scene-progress-layer.is-minimized .compact-scene-progress-action .icon-expand {
        display: block;
    }
    .compact-scene-progress-track {
        position: absolute;
        left: 0;
        right: 0;
        bottom: -1px;
        height: 3px;
        overflow: hidden;
        background: rgba(183, 127, 134, .12);
    }
    .compact-scene-progress-track::after {
        content: "";
        position: absolute;
        inset: 0 auto 0 0;
        width: 38%;
        border-radius: 99px;
        background: linear-gradient(90deg, #d8b9b9, #bd878f);
        animation: compact-scene-progress 1.45s ease-in-out infinite;
    }
    .compact-scene-progress-layer.is-complete .compact-scene-progress-track::after,
    .compact-scene-progress-layer.has-errors .compact-scene-progress-track::after {
        width: 100%;
        transform: none;
        animation: none;
    }
    .compact-scene-progress-layer.is-complete .compact-scene-progress-track::after {
        background: #91b389;
    }
    .compact-scene-progress-layer.has-errors .compact-scene-progress-track::after {
        background: #d7b66f;
    }
    .compact-scene-progress-frame {
        width: 100%;
        height: 100%;
        min-height: 0;
        border: 0;
        display: block;
        background: var(--bg, #faf9f6);
    }
    .compact-scene-progress-frame[hidden],
    .compact-scene-progress-restored[hidden],
    .compact-scene-progress-foot[hidden] {
        display: none !important;
    }
    .compact-scene-progress-restored {
        min-height: 0;
        display: flex;
        flex-direction: column;
        background: var(--bg, #faf9f6);
    }
    .compact-scene-progress-list {
        flex: 1;
        min-height: 0;
        display: flex;
        flex-direction: column;
        gap: 8px;
        margin: 0;
        padding: 12px;
        overflow-y: auto;
        list-style: none;
    }
    .compact-scene-progress-item {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        align-items: center;
        gap: 10px;
        padding: 10px 12px;
        border: 1px solid rgba(222, 216, 207, .9);
        border-radius: 8px;
        background: #fff;
    }
    .compact-scene-progress-item-name {
        min-width: 0;
        overflow: hidden;
        color: var(--ink, #171714);
        font-size: 12px;
        font-weight: 700;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .compact-scene-progress-item-scene {
        display: block;
        margin-top: 2px;
        overflow: hidden;
        color: #7a7069;
        font-size: 11px;
        font-weight: 400;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .compact-scene-progress-item-scene:empty {
        display: none;
    }
    .compact-scene-progress-item-state {
        padding: 5px 9px;
        border-radius: 999px;
        background: #f0ece6;
        color: #6f655d;
        font-size: 10px;
        font-weight: 700;
        letter-spacing: .05em;
        text-transform: uppercase;
        white-space: nowrap;
    }
    .compact-scene-progress-item.is-creating .compact-scene-progress-item-state {
        background: #f4e5e5;
        color: #78535a;
    }
    .compact-scene-progress-item.is-ready .compact-scene-progress-item-state {
        background: #e4f0df;
        color: #486342;
    }
    .compact-scene-progress-item.is-failed .compact-scene-progress-item-state {
        background: #f5ead3;
        color: #725a2f;
    }
    .compact-scene-progress-foot {
        padding: 0 12px 12px;
    }
    .compact-scene-progress-foot a {
        display: block;
        padding: 12px 15px;
        border: 1px solid #a9bfa3;
        border-radius: 6px;
        background: #dcead8;
        color: #3f593c;
        font-size: 10px;
        font-weight: 700;
        letter-spacing: .06em;
        text-align: center;
        text-decoration: none;
        text-transform: uppercase;
    }
    .compact-scene-progress-layer.is-minimized .compact-scene-progress-frame {
        visibility: hidden;
        pointer-events: none;
    }
    .compact-scene-progress-reopen {
        position: fixed;
        right: 18px;
        bottom: 18px;
        z-index: 1499;
        min-height: 44px;
        display: inline-flex;
        align-items: center;
        gap: 9px;
        padding: 0 15px;
        border: 1px solid rgba(183, 127, 134, .34);
        border-radius: 999px;
        background: #f4e5e5;
        color: #78535a;
        box-shadow: 0 10px 28px rgba(40, 34, 28, .14);
        font: 700 11px/1 var(--font-sans, Arial, sans-serif);
        cursor: pointer;
    }
    /* The sidebar activity pill is the entry point to a restored panel, so no
       second chip repeats the same state. The chip below is the fallback for
       screens without that pill. */
    .global-generation-activity.is-scene-progress-entry {
        cursor: pointer;
    }
    .compact-scene-progress-reopen.is-restored {
        bottom: 84px;
    }
    .compact-scene-progress-reopen:hover,
    .compact-scene-progress-reopen:focus-visible {
        background: #ecd6d7;
        outline: none;
    }
    .compact-scene-progress-reopen.is-complete {
        border-color: rgba(114, 147, 106, .38);
        background: #e4f0df;
        color: #486342;
    }
    .compact-scene-progress-reopen.has-errors {
        border-color: rgba(177, 139, 72, .38);
        background: #f5ead3;
        color: #725a2f;
    }
    @keyframes compact-scene-spin {
        to { transform: rotate(360deg); }
    }
    @keyframes compact-scene-progress {
        0% { transform: translateX(-105%); }
        55%, 100% { transform: translateX(275%); }
    }
    @media (max-width: 640px) {
        .compact-scene-progress-layer {
            right: 10px;
            bottom: 10px;
            width: calc(100vw - 20px);
            height: min(72vh, 590px);
        }
        .compact-scene-progress-layer.is-minimized {
            width: calc(100vw - 20px);
        }
        .compact-scene-progress-reopen {
            right: 10px;
            bottom: 10px;
        }
        .compact-scene-progress-reopen.is-restored {
            bottom: 132px;
        }
    }
</style>
<section
    class="compact-scene-progress-layer"
    data-compact-scene-progress-layer
    aria-label="<?= h(t('Background scene creation progress', 'Progreso de creación de escenas en segundo plano')) ?>"
    hidden
>
    <header class="compact-scene-progress-head">
        <div class="compact-scene-progress-title" aria-live="polite">
            <span class="compact-scene-progress-live" aria-hidden="true"></span>
            <span data-compact-scene-progress-title><?= h(t('Creating scenes in background', 'Creando escenas en segundo plano')) ?></span>
        </div>
        <div class="compact-scene-progress-actions">
            <button class="compact-scene-progress-action" type="button" data-compact-scene-progress-minimize aria-label="<?= h(t('Minimize progress', 'Minimizar progreso')) ?>" title="<?= h(t('Minimize progress', 'Minimizar progreso')) ?>">
                <svg class="icon-minimize" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-width="1.8" d="M6 12h12"/></svg>
                <svg class="icon-expand" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 3H3v5m13-5h5v5M8 21H3v-5m13 5h5v-5"/></svg>
            </button>
            <button class="compact-scene-progress-action" type="button" data-compact-scene-progress-hide aria-label="<?= h(t('Hide progress', 'Ocultar progreso')) ?>" title="<?= h(t('Hide progress', 'Ocultar progreso')) ?>">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-width="1.8" d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </div>
        <span class="compact-scene-progress-track" aria-hidden="true"></span>
    </header>
    <iframe
        class="compact-scene-progress-frame"
        data-compact-scene-progress-frame
        name="artwork-scene-progress-frame"
        title="<?= h(t('Scene creation progress', 'Progreso de creación de escenas')) ?>"
        src="about:blank"
    ></iframe>
    <div class="compact-scene-progress-restored" data-compact-scene-progress-restored hidden>
        <ul class="compact-scene-progress-list" data-compact-scene-progress-list></ul>
        <div class="compact-scene-progress-foot" data-compact-scene-progress-foot hidden>
            <a data-compact-scene-progress-results href="mockups.php"><?= h(t('View results', 'Ver resultados')) ?></a>
        </div>
    </div>
</section>
<button class="compact-scene-progress-reopen" type="button" data-compact-scene-progress-reopen hidden>
    <span class="compact-scene-progress-live" aria-hidden="true"></span>
    <span><?= h(t('Scenes in background', 'Escenas en segundo plano')) ?></span>
</button>
<script>
(function () {
    const compactSceneI18n = {
        expandProgress: <?= json_encode(t('Expand progress', 'Expandir progreso')) ?>,
        minimizeProgress: <?= json_encode(t('Minimize progress', 'Minimizar progreso')) ?>,
        scenesInBackground: <?= json_encode(t('Scenes in background', 'Escenas en segundo plano')) ?>,
        creatingScenesInBackground: <?= json_encode(t('Creating scenes in background', 'Creando escenas en segundo plano')) ?>,
        preparingArtworkInBackground: <?= json_encode(t('Preparing artwork in background', 'Preparando la obra en segundo plano')) ?>,
        sceneCreationNeedsAttention: <?= json_encode(t('Scene creation needs attention', 'La creación de escenas necesita atención')) ?>,
        oneSceneReady: <?= json_encode(t('1 scene is ready', '1 escena está lista')) ?>,
        scenesNeedAttention: <?= json_encode(t('Scenes need attention', 'Las escenas necesitan atención')) ?>,
        scenesReady: <?= json_encode(t('Scenes ready', 'Escenas listas')) ?>,
        scenesReadySuffix: <?= json_encode(t(' scenes are ready', ' escenas están listas')) ?>,
        scenesReadyFailedSuffix: <?= json_encode(t(' scenes ready · ', ' escenas listas · ')) ?>,
        failedSuffix: <?= json_encode(t(' failed', ' fallaron')) ?>,
        stateQueued: <?= json_encode(t('In queue', 'En cola')) ?>,
        stateCreating: <?= json_encode(t('Creating', 'Creando')) ?>,
        stateReady: <?= json_encode(t('Ready', 'Lista')) ?>,
        stateFailed: <?= json_encode(t('Failed', 'Falló')) ?>,
        viewSceneDetail: <?= json_encode(t('View the detail of each scene', 'Ver el detalle de cada escena')) ?>,
    };
    const layer = document.querySelector('[data-compact-scene-progress-layer]');
    const frame = document.querySelector('[data-compact-scene-progress-frame]');
    const title = document.querySelector('[data-compact-scene-progress-title]');
    const minimizeButton = document.querySelector('[data-compact-scene-progress-minimize]');
    const hideButton = document.querySelector('[data-compact-scene-progress-hide]');
    const reopenButton = document.querySelector('[data-compact-scene-progress-reopen]');
    const reopenLabel = reopenButton?.querySelector('span:last-child');
    if (!layer || !frame || !minimizeButton || !hideButton || !reopenButton) return;

    const restoredBody = document.querySelector('[data-compact-scene-progress-restored]');
    const restoredList = document.querySelector('[data-compact-scene-progress-list]');
    const restoredFoot = document.querySelector('[data-compact-scene-progress-foot]');
    const restoredResults = document.querySelector('[data-compact-scene-progress-results]');
    const brandLink = document.querySelector('.brand');
    const activityEndpoint = new URL(
        'mockup_generation_activity.php',
        brandLink ? brandLink.href : window.location.href
    ).href;
    // The runner page drives its own progress, and the embedded frame is the
    // live panel itself. Neither should grow a second restored panel on top.
    const ownsItsOwnProgress = document.body.classList.contains('compact-scene-runner')
        || (window.parent !== window && window.name === 'artwork-scene-progress-frame');
    const activityPill = document.querySelector('[data-global-generation-activity]');
    const restoredJobIds = new Set();
    let restoredPollTimer = 0;
    let restoredMode = false;

    let submittedForm = null;

    function setMinimized(minimized) {
        layer.classList.toggle('is-minimized', minimized);
        minimizeButton.setAttribute('aria-label', minimized ? compactSceneI18n.expandProgress : compactSceneI18n.minimizeProgress);
        minimizeButton.title = minimized ? compactSceneI18n.expandProgress : compactSceneI18n.minimizeProgress;
    }

    function exitRestoredMode() {
        restoredMode = false;
        window.clearTimeout(restoredPollTimer);
        restoredJobIds.clear();
        if (restoredBody) restoredBody.hidden = true;
        reopenButton.classList.remove('is-restored');
        frame.hidden = false;
    }

    function showProgress(label) {
        exitRestoredMode();
        layer.classList.remove('is-complete', 'has-errors');
        reopenButton.classList.remove('is-complete', 'has-errors');
        if (reopenLabel) reopenLabel.textContent = compactSceneI18n.scenesInBackground;
        if (title && label) title.textContent = label;
        layer.hidden = false;
        reopenButton.hidden = true;
        setMinimized(false);
    }

    function hideProgress() {
        layer.hidden = true;
        // While restoring, the sidebar activity pill already reopens the panel.
        reopenButton.hidden = restoredMode && activityPill !== null;
    }

    function finishProgress() {
        exitRestoredMode();
        layer.hidden = true;
        reopenButton.hidden = true;
        frame.src = 'about:blank';
        layer.classList.remove('is-complete', 'has-errors');
        reopenButton.classList.remove('is-complete', 'has-errors');
        if (submittedForm) {
            submittedForm.querySelectorAll('[type="submit"]').forEach(button => { button.disabled = false; });
            submittedForm.removeAttribute('aria-busy');
            submittedForm = null;
        }
    }

    function completeProgress(detail) {
        const readyCount = Math.max(0, Number(detail?.readyCount) || 0);
        const failedCount = Math.max(0, Number(detail?.failedCount) || 0);
        const hasErrors = failedCount > 0;
        layer.classList.add('is-complete');
        layer.classList.toggle('has-errors', hasErrors);
        reopenButton.classList.add('is-complete');
        reopenButton.classList.toggle('has-errors', hasErrors);
        const completedLabel = hasErrors
            ? (readyCount > 0 ? readyCount + compactSceneI18n.scenesReadyFailedSuffix + failedCount + compactSceneI18n.failedSuffix : compactSceneI18n.sceneCreationNeedsAttention)
            : (readyCount === 1 ? compactSceneI18n.oneSceneReady : readyCount + compactSceneI18n.scenesReadySuffix);
        if (title) title.textContent = completedLabel;
        if (reopenLabel) reopenLabel.textContent = hasErrors ? compactSceneI18n.scenesNeedAttention : compactSceneI18n.scenesReady;
    }

    window.openArtworkSceneProgress = function (sourceUrl) {
        const target = new URL(sourceUrl, window.location.href);
        target.searchParams.set('embedded', '1');
        frame.src = target.href;
        showProgress(compactSceneI18n.creatingScenesInBackground);
    };

    window.submitArtworkSceneProgress = function (form) {
        if (!(form instanceof HTMLFormElement)) return false;
        submittedForm = form;
        form.setAttribute('aria-busy', 'true');
        form.querySelectorAll('[type="submit"]').forEach(button => { button.disabled = true; });
        const previousTarget = form.getAttribute('target');
        form.setAttribute('target', frame.name);
        showProgress(compactSceneI18n.preparingArtworkInBackground);
        HTMLFormElement.prototype.submit.call(form);
        if (previousTarget === null) form.removeAttribute('target');
        else form.setAttribute('target', previousTarget);
        return true;
    };

    minimizeButton.addEventListener('click', () => {
        setMinimized(!layer.classList.contains('is-minimized'));
    });
    hideButton.addEventListener('click', hideProgress);
    reopenButton.addEventListener('click', () => {
        layer.hidden = false;
        reopenButton.hidden = true;
        // A restored panel is the only place the per-scene detail lives, so it
        // opens at full height instead of the minimized live-run bar.
        setMinimized(!restoredMode);
    });

    frame.addEventListener('load', () => {
        try {
            const path = frame.contentWindow.location.pathname;
            if (title && path.endsWith('/create_scenes_wait.php')) {
                title.textContent = compactSceneI18n.preparingArtworkInBackground;
            } else if (title && path.endsWith('/mockup_combinations_review.php')) {
                title.textContent = compactSceneI18n.creatingScenesInBackground;
            }
        } catch (error) {
        }
    });

    window.addEventListener('message', event => {
        if (event.origin !== window.location.origin || event.source !== frame.contentWindow) return;
        if (event.data?.type === 'artworkmockups:scene-progress-complete') {
            completeProgress(event.data);
            return;
        }
        if (event.data?.type === 'artworkmockups:hide-scene-progress') finishProgress();
    });

    function restoredStateFor(status) {
        if (status === 'done') return { key: 'ready', label: compactSceneI18n.stateReady };
        if (status === 'error' || status === 'failed_enqueue') return { key: 'failed', label: compactSceneI18n.stateFailed };
        if (status === 'processing') return { key: 'creating', label: compactSceneI18n.stateCreating };
        return { key: 'queued', label: compactSceneI18n.stateQueued };
    }

    function renderRestored(items) {
        if (!restoredList) return;
        restoredList.textContent = '';
        let readyCount = 0;
        let failedCount = 0;
        let pendingCount = 0;
        let resultsUrl = '';

        items.forEach(item => {
            const state = restoredStateFor(String(item.status || ''));
            if (state.key === 'ready') {
                readyCount++;
                if (resultsUrl === '' && item.results_url) resultsUrl = String(item.results_url);
            } else if (state.key === 'failed') {
                failedCount++;
            } else {
                pendingCount++;
            }

            const row = document.createElement('li');
            row.className = 'compact-scene-progress-item is-' + state.key;
            const name = document.createElement('div');
            name.className = 'compact-scene-progress-item-name';
            name.textContent = String(item.artwork_title || '');
            const scene = document.createElement('span');
            scene.className = 'compact-scene-progress-item-scene';
            scene.textContent = String(item.scene_category || '');
            name.appendChild(scene);
            const badge = document.createElement('span');
            badge.className = 'compact-scene-progress-item-state';
            badge.textContent = state.label;
            row.appendChild(name);
            row.appendChild(badge);
            restoredList.appendChild(row);
        });

        if (restoredFoot && restoredResults) {
            restoredFoot.hidden = resultsUrl === '';
            if (resultsUrl !== '') restoredResults.href = resultsUrl;
        }

        if (pendingCount > 0) {
            layer.classList.remove('is-complete', 'has-errors');
            reopenButton.classList.remove('is-complete', 'has-errors');
            if (title) title.textContent = compactSceneI18n.creatingScenesInBackground;
            if (reopenLabel) reopenLabel.textContent = compactSceneI18n.scenesInBackground;
            return;
        }
        completeProgress({ readyCount: readyCount, failedCount: failedCount });
    }

    async function pollRestored() {
        if (!restoredMode) return;
        try {
            const requestUrl = new URL(activityEndpoint);
            const requestedIds = Array.from(restoredJobIds);
            if (requestedIds.length) requestUrl.searchParams.set('ids', requestedIds.join(','));
            const response = await fetch(requestUrl.href, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            const data = await response.json();
            if (data?.ok) {
                (Array.isArray(data.active) ? data.active : [])
                    .forEach(item => restoredJobIds.add(Number(item.id)));
                const items = (Array.isArray(data.items) ? data.items : [])
                    .filter(item => restoredJobIds.has(Number(item.id)));
                renderRestored(items);
                if (!items.some(item => item.active)) {
                    window.clearTimeout(restoredPollTimer);
                    return;
                }
            }
        } catch (error) {
        }
        window.clearTimeout(restoredPollTimer);
        restoredPollTimer = window.setTimeout(pollRestored, 3000);
    }

    function openRestoredPanel() {
        layer.hidden = false;
        reopenButton.hidden = true;
        setMinimized(false);
    }

    function bindActivityPillEntry() {
        if (!activityPill || activityPill.dataset.sceneProgressEntry === '1') return;
        activityPill.dataset.sceneProgressEntry = '1';
        activityPill.classList.add('is-scene-progress-entry');
        activityPill.setAttribute('role', 'button');
        activityPill.setAttribute('tabindex', '0');
        activityPill.title = compactSceneI18n.viewSceneDetail;
        activityPill.addEventListener('click', event => {
            if (event.target.closest('[data-global-generation-sound]')) return;
            openRestoredPanel();
        });
        activityPill.addEventListener('keydown', event => {
            if (event.key !== 'Enter' && event.key !== ' ') return;
            if (event.target.closest('[data-global-generation-sound]')) return;
            event.preventDefault();
            openRestoredPanel();
        });
    }

    // Scenes keep being created after the page that launched them is gone. On
    // every load we ask the server what is still running and offer the panel
    // back. Restoring only reads status: it never reopens the generation flow,
    // which would enqueue a second batch and spend credits again.
    async function restoreFromServer() {
        if (ownsItsOwnProgress || !restoredBody || !layer.hidden) return;
        try {
            const response = await fetch(activityEndpoint, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            const data = await response.json();
            const active = (data?.ok && Array.isArray(data.active)) ? data.active : [];
            if (!active.length || restoredMode || !layer.hidden) return;

            active.forEach(item => restoredJobIds.add(Number(item.id)));
            restoredMode = true;
            frame.hidden = true;
            restoredBody.hidden = false;
            setMinimized(false);
            renderRestored(active);
            if (activityPill) {
                bindActivityPillEntry();
            } else {
                reopenButton.classList.add('is-restored');
                reopenButton.hidden = false;
            }
            pollRestored();
        } catch (error) {
        }
    }

    restoreFromServer();
})();
</script>

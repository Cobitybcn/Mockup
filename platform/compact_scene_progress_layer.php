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
    }
</style>
<section
    class="compact-scene-progress-layer"
    data-compact-scene-progress-layer
    aria-label="Background scene creation progress"
    hidden
>
    <header class="compact-scene-progress-head">
        <div class="compact-scene-progress-title" aria-live="polite">
            <span class="compact-scene-progress-live" aria-hidden="true"></span>
            <span data-compact-scene-progress-title>Creating scenes in background</span>
        </div>
        <div class="compact-scene-progress-actions">
            <button class="compact-scene-progress-action" type="button" data-compact-scene-progress-minimize aria-label="Minimize progress" title="Minimize progress">
                <svg class="icon-minimize" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-width="1.8" d="M6 12h12"/></svg>
                <svg class="icon-expand" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 3H3v5m13-5h5v5M8 21H3v-5m13 5h5v-5"/></svg>
            </button>
            <button class="compact-scene-progress-action" type="button" data-compact-scene-progress-hide aria-label="Hide progress" title="Hide progress">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-width="1.8" d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </div>
        <span class="compact-scene-progress-track" aria-hidden="true"></span>
    </header>
    <iframe
        class="compact-scene-progress-frame"
        data-compact-scene-progress-frame
        name="artwork-scene-progress-frame"
        title="Scene creation progress"
        src="about:blank"
    ></iframe>
</section>
<button class="compact-scene-progress-reopen" type="button" data-compact-scene-progress-reopen hidden>
    <span class="compact-scene-progress-live" aria-hidden="true"></span>
    <span>Scenes in background</span>
</button>
<script>
(function () {
    const layer = document.querySelector('[data-compact-scene-progress-layer]');
    const frame = document.querySelector('[data-compact-scene-progress-frame]');
    const title = document.querySelector('[data-compact-scene-progress-title]');
    const minimizeButton = document.querySelector('[data-compact-scene-progress-minimize]');
    const hideButton = document.querySelector('[data-compact-scene-progress-hide]');
    const reopenButton = document.querySelector('[data-compact-scene-progress-reopen]');
    const reopenLabel = reopenButton?.querySelector('span:last-child');
    if (!layer || !frame || !minimizeButton || !hideButton || !reopenButton) return;

    let submittedForm = null;

    function setMinimized(minimized) {
        layer.classList.toggle('is-minimized', minimized);
        minimizeButton.setAttribute('aria-label', minimized ? 'Expand progress' : 'Minimize progress');
        minimizeButton.title = minimized ? 'Expand progress' : 'Minimize progress';
    }

    function showProgress(label) {
        layer.classList.remove('is-complete', 'has-errors');
        reopenButton.classList.remove('is-complete', 'has-errors');
        if (reopenLabel) reopenLabel.textContent = 'Scenes in background';
        if (title && label) title.textContent = label;
        layer.hidden = false;
        reopenButton.hidden = true;
        setMinimized(false);
    }

    function hideProgress() {
        layer.hidden = true;
        reopenButton.hidden = false;
    }

    function finishProgress() {
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
            ? (readyCount > 0 ? readyCount + ' scenes ready · ' + failedCount + ' failed' : 'Scene creation needs attention')
            : (readyCount === 1 ? '1 scene is ready' : readyCount + ' scenes are ready');
        if (title) title.textContent = completedLabel;
        if (reopenLabel) reopenLabel.textContent = hasErrors ? 'Scenes need attention' : 'Scenes ready';
    }

    window.openArtworkSceneProgress = function (sourceUrl) {
        const target = new URL(sourceUrl, window.location.href);
        target.searchParams.set('embedded', '1');
        frame.src = target.href;
        showProgress('Creating scenes in background');
    };

    window.submitArtworkSceneProgress = function (form) {
        if (!(form instanceof HTMLFormElement)) return false;
        submittedForm = form;
        form.setAttribute('aria-busy', 'true');
        form.querySelectorAll('[type="submit"]').forEach(button => { button.disabled = true; });
        const previousTarget = form.getAttribute('target');
        form.setAttribute('target', frame.name);
        showProgress('Preparing artwork in background');
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
        setMinimized(true);
    });

    frame.addEventListener('load', () => {
        try {
            const path = frame.contentWindow.location.pathname;
            if (title && path.endsWith('/create_scenes_wait.php')) {
                title.textContent = 'Preparing artwork in background';
            } else if (title && path.endsWith('/mockup_combinations_review.php')) {
                title.textContent = 'Creating scenes in background';
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
})();
</script>

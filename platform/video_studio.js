(() => {
    'use strict';

    const root = document.querySelector('[data-video-studio]');
    const dataNode = document.getElementById('video-studio-data');
    if (!root || !dataNode) return;

    let initial = {};
    try { initial = JSON.parse(dataNode.textContent || '{}'); } catch (_) { initial = {}; }

    const state = {
        csrf: String(initial.csrf || ''),
        projects: Array.isArray(initial.projects) ? initial.projects : [],
        studio: initial.studio || null,
        assets: initial.assets || { mockups: [], rootArtworks: [], generatedClips: [], uploadedReferences: [] },
        capabilities: initial.capabilities || {},
        endpoints: initial.endpoints || {},
        artworkFilter: String(initial.initialArtworkFilter || ''),
        seriesFilter: '',
        selectedAssetKey: null,
        pendingGenerationSceneId: null,
        mutation: Promise.resolve(),
        saving: false,
        seeding: false,
        uploadingSlots: new Set(),
        openContexts: new Set(),
        sortables: [],
        generationTimer: null,
        exportTimer: null,
        musicUploading: false,
        musicAudio: null,
        pxs: 18,
        playhead: 0,
    };

    const $ = (selector, context = root) => context.querySelector(selector);
    const $$ = (selector, context = root) => Array.from(context.querySelectorAll(selector));
    const dom = {
        projectTitle: $('[data-project-title]'),
        aspectButtons: $$('[data-project-aspect-ratio]'),
        saveState: $('[data-save-state]'),
        artworkFilter: $('[data-artwork-filter]'),
        seriesFilter: $('[data-series-filter]'),
        catalogRail: $('[data-catalog-rail]'),
        catalogHelp: $('[data-catalog-help]'),
        boardGrid: $('[data-sequence-boards]'),
        exportPanel: $('[data-export-panel]'),
        generationModal: $('[data-generation-modal]'),
        generationSummary: $('[data-generation-summary]'),
        toast: $('[data-video-toast]'),
    };

    const labels = {
        queued: 'En cola',
        submitting: 'Enviando',
        polling: 'Generating',
        processing: 'Generating',
        succeeded: 'Video ready',
        failed: 'Error',
        ready: 'Ready to generate',
        draft: 'Not prepared',
    };

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>'"]/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' })[char]);
    }

    function currentProject() { return state.studio?.project || null; }
    function scenes() { return Array.isArray(state.studio?.scenes) ? state.studio.scenes : []; }
    function sceneById(id) { return scenes().find(scene => Number(scene.id) === Number(id)) || null; }
    function referenceAssets() {
        return [
            ...(state.assets.mockups || []),
            ...(state.assets.rootArtworks || []),
            ...(state.assets.generatedClips || []),
            ...(state.assets.uploadedReferences || []),
        ];
    }
    function assetByKey(key) { return referenceAssets().find(asset => String(asset.assetKey) === String(key)) || null; }
    function artworkFilterKey(asset) {
        const groupId = Number(asset?.artworkGroupId || 0);
        const artworkId = Number(asset?.artworkId || 0);
        return groupId > 0 ? `group:${groupId}` : (artworkId > 0 ? `artwork:${artworkId}` : '');
    }
    function artworkFilterForArtworkId(artworkId) {
        artworkId = Number(artworkId || 0);
        if (artworkId <= 0) return '';
        const asset = [...(state.assets.rootArtworks || []), ...(state.assets.mockups || [])].find(candidate =>
            Number(candidate.artworkId || 0) === artworkId || Number(candidate.canonicalArtworkId || 0) === artworkId
        );
        return artworkFilterKey(asset);
    }

    function toast(message, isError = false) {
        dom.toast.textContent = String(message || '');
        dom.toast.classList.toggle('is-error', isError);
        dom.toast.classList.add('is-visible');
        window.clearTimeout(toast.timer);
        toast.timer = window.setTimeout(() => dom.toast.classList.remove('is-visible'), 3600);
    }

    function setSaveState(text, mode = '') {
        if (!dom.saveState) return;
        dom.saveState.textContent = text;
        dom.saveState.classList.toggle('is-saving', mode === 'saving');
        dom.saveState.classList.toggle('is-error', mode === 'error');
    }

    async function request(endpoint, payload) {
        const response = await fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ csrf: state.csrf, ...payload }),
        });
        let data;
        try { data = await response.json(); } catch (_) { data = { ok: false, error: `The request failed (${response.status}).` }; }
        if (!response.ok || !data.ok) {
            const error = new Error(data.error || `The request failed (${response.status}).`);
            error.status = response.status;
            throw error;
        }
        return data;
    }

    function api(payload) { return request(state.endpoints.api || 'video_api.php', payload); }

    function refreshLibrary() {
        if (refreshLibrary.pending) return refreshLibrary.pending;
        refreshLibrary.pending = api({ action: 'library_list' })
            .then(result => {
                if (!result.assets || typeof result.assets !== 'object') return;
                state.assets = result.assets;
                if (state.artworkFilter && !artworkMap().has(state.artworkFilter)) {
                    state.artworkFilter = artworkFilterForArtworkId(currentProject()?.artworkId);
                    state.selectedAssetKey = null;
                }
                renderProjectControls();
                renderCatalog();
                setupSortables();
            })
            .catch(() => undefined)
            .finally(() => { refreshLibrary.pending = null; });
        return refreshLibrary.pending;
    }
    refreshLibrary.pending = null;

    function queueMutation(work, successMessage = '') {
        const operation = state.mutation.catch(() => undefined).then(async () => {
            state.saving = true;
            setSaveState('Guardando…', 'saving');
            try {
                const result = await work();
                if (result?.project && result?.scenes) applyStudio(result);
                setSaveState('Saved');
                if (successMessage) toast(successMessage);
                return result;
            } catch (error) {
                setSaveState('Save failed', 'error');
                toast(error.message, true);
                if (error.status === 409) window.setTimeout(() => window.location.reload(), 1500);
                throw error;
            } finally {
                state.saving = false;
            }
        });
        state.mutation = operation.catch(() => undefined);
        return operation;
    }

    function applyStudio(payload, projectChanged = false) {
        const previousId = Number(currentProject()?.id || 0);
        if (payload.assets && typeof payload.assets === 'object') state.assets = payload.assets;
        state.studio = {
            project: payload.project,
            scenes: Array.isArray(payload.scenes) ? payload.scenes : [],
            summary: payload.summary || {},
            latestExport: payload.latestExport || null,
        };
        const isDifferentProject = projectChanged || previousId !== Number(payload.project.id);
        if (isDifferentProject) {
            state.artworkFilter = artworkFilterForArtworkId(payload.project.artworkId);
            state.seriesFilter = '';
            state.selectedAssetKey = null;
        }
        const summary = { ...payload.project, sceneCount: state.studio.scenes.length };
        const index = state.projects.findIndex(project => Number(project.id) === Number(payload.project.id));
        if (index >= 0) state.projects[index] = summary;
        else state.projects.unshift(summary);
        root.dataset.projectId = String(payload.project.id);
        renderAll();
    }

    function createProjectNow() {
        const aspectRatio = String(currentProject()?.aspectRatio || '9:16');
        return queueMutation(() => api({
            action: 'project_create',
            project: {
                title: '',
                artworkId: null,
                aspectRatio,
                targetDurationSeconds: 24,
                projectType: 'social_clip',
            },
        }), 'Project created').then(result => {
            applyStudio(result, true);
            window.history.replaceState({}, '', `video.php?project=${result.project.id}`);
            ensureMinimumSequences();
            dom.projectTitle?.focus();
            dom.projectTitle?.select();
            return result;
        });
    }

    function artworkMap() {
        const values = new Map();
        [...(state.assets.rootArtworks || []), ...(state.assets.mockups || [])].forEach(asset => {
            const key = artworkFilterKey(asset);
            const artworkId = Number(asset.artworkId || 0);
            if (!key || artworkId <= 0) return;
            const title = String(asset.groupTitle || asset.artworkTitle || '').trim() || `Artwork #${artworkId}`;
            const canonicalArtworkId = Number(asset.canonicalArtworkId || artworkId);
            if (!values.has(key) || String(values.get(key).title).startsWith('Artwork #')) {
                values.set(key, { title, canonicalArtworkId });
            }
        });
        return new Map([...values.entries()].sort((left, right) => left[1].title.localeCompare(right[1].title, 'es', { sensitivity: 'base' })));
    }

    function seriesMap() {
        const values = new Map();
        (state.assets.mockups || []).forEach(asset => {
            const id = Number(asset.seriesId || 0);
            const title = String(asset.seriesTitle || '').trim();
            if (id > 0) values.set(id, title || `Series #${id}`);
        });
        return new Map([...values.entries()].sort((left, right) => left[1].localeCompare(right[1], 'es', { sensitivity: 'base' })));
    }

    function renderProjectControls() {
        const project = currentProject();
        if (dom.projectTitle && document.activeElement !== dom.projectTitle) {
            dom.projectTitle.value = String(project?.title || '');
        }
        dom.aspectButtons.forEach(button => {
            const selected = String(button.dataset.projectAspectRatio || '') === String(project?.aspectRatio || '9:16');
            button.classList.toggle('is-selected', selected);
            button.setAttribute('aria-pressed', selected ? 'true' : 'false');
        });

        const artworks = artworkMap();
        dom.artworkFilter.innerHTML = '<option value="">Filter by artwork</option>' + [...artworks.entries()].map(([key, artwork]) =>
            `<option value="${escapeHtml(key)}"${String(key) === state.artworkFilter ? ' selected' : ''}>${escapeHtml(artwork.title)}</option>`
        ).join('');
        const series = seriesMap();
        dom.seriesFilter.innerHTML = '<option value="">Filter by series</option><option value="none">No series</option>' + [...series.entries()].map(([id, title]) =>
            `<option value="${id}"${String(id) === state.seriesFilter ? ' selected' : ''}>${escapeHtml(title)}</option>`
        ).join('');
    }

    function visibleReferenceAssets() {
        return referenceAssets()
            .filter(asset => asset.type === 'reference_asset' || !state.artworkFilter || artworkFilterKey(asset) === state.artworkFilter)
            .filter(asset => asset.type === 'reference_asset' || !state.seriesFilter
                || (state.seriesFilter === 'none' ? Number(asset.seriesId || 0) === 0 : Number(asset.seriesId) === Number(state.seriesFilter)))
            .sort((left, right) => {
                if (Boolean(left.favorite) !== Boolean(right.favorite)) return left.favorite ? -1 : 1;
                if (left.favorite && right.favorite) return Number(left.favoriteRank || 0) - Number(right.favoriteRank || 0);
                return String(right.createdAt || '').localeCompare(String(left.createdAt || '')) || Number(right.id) - Number(left.id);
            });
    }

    function renderCatalog() {
        const assets = visibleReferenceAssets();
        dom.catalogRail.innerHTML = assets.length ? assets.map(asset => `
            <article class="vds-catalog-card${asset.favorite ? ' is-favorite' : ''}${String(asset.assetKey) === String(state.selectedAssetKey) ? ' is-selected' : ''}${asset.mediaType === 'video' ? ' is-video' : ''}"
                data-catalog-card data-asset-key="${escapeHtml(asset.assetKey)}" data-asset-id="${asset.id}" data-asset-type="${escapeHtml(asset.type)}" data-media-type="${escapeHtml(asset.mediaType || 'image')}" tabindex="0" aria-label="${escapeHtml(asset.label)}">
                ${asset.thumbnailUrl
                    ? `<img src="${escapeHtml(asset.thumbnailUrl)}" alt="${escapeHtml(asset.artworkTitle || asset.label)}" loading="lazy" draggable="false">`
                    : `<div class="vds-catalog-video-placeholder" aria-hidden="true"><span>▶</span><small>Video</small></div>`}
                ${asset.type === 'mockup' ? `<button class="vds-favorite media-icon-button media-icon-button--compact media-thumb-action media-thumb-action--right${asset.favorite ? ' active' : ''}" type="button" data-toggle-favorite aria-pressed="${asset.favorite ? 'true' : 'false'}" aria-label="${asset.favorite ? 'Remove from favorites' : 'Add to favorites'}"><svg class="media-action-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3.7 2.55 5.17 5.71.83-4.13 4.03.97 5.69L12 16.73l-5.1 2.69.97-5.69L3.74 9.7l5.71-.83L12 3.7Z"/></svg></button>` : ''}
                <div class="vds-catalog-card-copy"><strong>${escapeHtml(asset.contextTitle || asset.label)}</strong><span>${escapeHtml(asset.type === 'reference_asset' ? 'From your computer' : (asset.mediaType === 'video' ? (asset.projectTitle || 'Generated video') : (asset.artworkTitle || 'Reference image')))}</span></div>
            </article>`).join('') : '<div class="vds-catalog-empty">No references are available for this selection.</div>';
        dom.catalogHelp.textContent = state.selectedAssetKey
            ? 'Reference selected. Click the destination where you want to use it, or drag it there.'
            : 'Drag images into their reference slots. A generated video can continue another sequence from its final frame.';
    }

    function defaultGenerationMode() {
        return String(state.capabilities.defaultMode || 'image_to_video');
    }

    function defaultGenerationDuration() {
        return Number(state.capabilities.defaultDuration || 4);
    }

    function referenceFor(scene, role) {
        const references = Array.isArray(scene.references) ? scene.references : [];
        return references.find(reference => String(reference.role) === String(role)) || null;
    }

    function referencesFor(scene, role) {
        return (scene.references || []).filter(reference => String(reference.role) === String(role));
    }

    function sourceVideoReference(scene) {
        return referenceFor(scene, 'source_video')
            || (scene.references || []).find(reference => reference.mediaType === 'video' && reference.sourceType === 'reference_asset')
            || null;
    }

    function attachedImageCount(scene) {
        return (scene.references || []).filter(reference => reference.mediaType === 'image').length;
    }

    function omniImageUsage(scene, index) {
        const explicitStart = explicitStartReference(scene);
        const generatedStart = explicitStart?.mediaType === 'video' && explicitStart?.sourceType === 'generation_job';
        const automaticContinuity = index > 0 && !explicitStart && !sourceVideoReference(scene);
        return attachedImageCount(scene) + (generatedStart || automaticContinuity ? 1 : 0);
    }

    function sceneHasUpload(sceneId) {
        const prefix = `${Number(sceneId)}:`;
        return [...state.uploadingSlots].some(key => String(key).startsWith(prefix));
    }

    function explicitStartReference(scene) {
        return (scene.references || []).find(reference => String(reference.role) === 'start_frame') || null;
    }

    function generatedContinuation(scene) {
        const reference = explicitStartReference(scene);
        return reference?.sourceType === 'generation_job' ? reference : null;
    }

    function uploadSlotKey(sceneId, role) {
        return `${Number(sceneId)}:${String(role)}`;
    }

    function frameSlot(scene, role, label) {
        const candidate = referenceFor(scene, role);
        const reference = candidate?.mediaType === 'image'
            || (role === 'start_frame' && candidate?.sourceType === 'generation_job')
            ? candidate
            : null;
        const uploading = state.uploadingSlots.has(uploadSlotKey(scene.id, role));
        const media = reference
            ? (reference.mediaType === 'video'
                ? `<video src="${escapeHtml(reference.previewUrl)}" data-continuation-frame-preview muted preload="metadata" playsinline aria-label="Final frame of ${escapeHtml(reference.label)}"></video><span class="vds-continuation-frame-badge">Final frame</span>`
                : `<img src="${escapeHtml(reference.thumbnailUrl || reference.previewUrl)}" alt="${escapeHtml(reference.label)}">`)
                + `<button class="vds-remove-frame media-icon-button media-icon-button--compact media-thumb-action media-thumb-action--right is-danger" type="button" data-remove-reference="${reference.id}" aria-label="Remove ${escapeHtml(label)}"><svg class="media-action-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 7l10 10M17 7 7 17"/></svg></button><span class="vds-frame-caption">${escapeHtml(reference.label)}</span>`
            : `<div class="vds-frame-placeholder"><span class="vds-frame-plus">${uploading ? '◌' : '＋'}</span><strong>${uploading ? 'Uploading file…' : 'Drag here'}</strong><button class="vds-frame-upload-button" type="button" data-upload-reference="${scene.id}" data-role="${role}"${uploading ? ' disabled' : ''}>From computer</button><span>or select a reference from the catalog</span></div>`;
        return `<div class="vds-frame-column">
            <span class="vds-frame-label">${escapeHtml(label)}</span>
            <div class="vds-frame-slot${reference ? ' has-media' : ''}${uploading ? ' is-uploading' : ''}" data-frame-drop data-scene-id="${scene.id}" data-role="${role}" tabindex="0">
                ${media}
                <input type="file" data-reference-file-input data-scene-id="${scene.id}" data-role="${role}" accept="image/jpeg,image/png,image/webp,image/gif" hidden>
            </div>
        </div>`;
    }

    function prepareContinuationFramePreviews() {
        $$('video[data-continuation-frame-preview]', dom.boardGrid).forEach(video => {
            const showLastFrame = () => {
                const duration = Number(video.duration);
                if (!Number.isFinite(duration) || duration <= 0) return;
                video.pause();
                // Keep the preview aligned with VideoFfmpeg::lastFrame(), which
                // extracts the actual provider input 0.12 seconds before the end.
                video.currentTime = Math.max(0, duration - 0.12);
            };
            if (video.readyState >= 1) showLastFrame();
            else video.addEventListener('loadedmetadata', showLastFrame, { once: true });
        });
    }

    function compactReferenceSlot(scene, role, number, label, reference = null, optional = false) {
        if (!reference && role !== 'reference') reference = referenceFor(scene, role);
        const uploading = state.uploadingSlots.has(uploadSlotKey(scene.id, role));
        const body = reference
            ? `<div class="vds-compact-reference-media"><img src="${escapeHtml(reference.thumbnailUrl || reference.previewUrl)}" alt="${escapeHtml(reference.label)}"><button class="media-icon-button media-icon-button--compact media-thumb-action media-thumb-action--right is-danger" type="button" data-remove-reference="${reference.id}" aria-label="Remove ${escapeHtml(label)}"><svg class="media-action-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 7l10 10M17 7 7 17"/></svg></button></div>`
            : `<div class="vds-compact-reference-empty"><span>${uploading ? '◌' : '＋'}</span><strong>${uploading ? 'Uploading…' : 'Add'}</strong>${optional ? '<small>Optional</small>' : ''}</div>`;
        return `<div class="vds-priority-reference${reference ? ' has-media' : ''}" data-reference-drop data-scene-id="${scene.id}" data-role="${role}" tabindex="0">
            <header><span>${number}</span><strong>${escapeHtml(label)}</strong></header>
            ${body}
            <input type="file" data-reference-file-input data-scene-id="${scene.id}" data-role="${role}" accept="image/jpeg,image/png,image/webp,image/gif" hidden>
        </div>`;
    }

    function additionalReferences(scene) {
        const excluded = ['start_frame','end_frame','artwork_fidelity','character_identity','wardrobe_identity','source_video'];
        return (scene.references || []).filter(reference => reference.mediaType === 'image' && !excluded.includes(String(reference.role)));
    }

    function referenceManager(scene, index) {
        const usedImages = omniImageUsage(scene, index);
        const maxImages = Number(state.capabilities.referenceLimits?.images || 10);
        const extras = additionalReferences(scene).slice(0, 5);
        const sourceVideo = sourceVideoReference(scene);
        const extraSlots = Array.from({ length: 5 }, (_, slot) => compactReferenceSlot(
            scene,
            'reference',
            slot + 6,
            'Reference',
            extras[slot] || null,
            true
        )).join('');
        const legacyEditorUrl = sourceVideo?.sourceType === 'generation_job'
            ? `video_editor.php?generation_id=${Number(sourceVideo.sourceId)}`
            : `video_editor.php?reference_asset_id=${Number(sourceVideo?.sourceId || 0)}`;

        return `<section class="vds-reference-manager">
            <div class="vds-reference-section-head"><div><strong>Visual references</strong><small>Write “Image 3”, “Image 4”… in the prompt to indicate how each one should be used.</small></div><span>${usedImages}/${maxImages}</span></div>
            <div class="vds-priority-grid">
                ${compactReferenceSlot(scene, 'artwork_fidelity', 3, 'Artwork')}
                ${compactReferenceSlot(scene, 'character_identity', 4, 'Personaje', null, true)}
                ${compactReferenceSlot(scene, 'wardrobe_identity', 5, 'Vestuario', null, true)}
                ${extraSlots}
            </div>
            <p class="vds-reference-empty-note">Empty slots are not sent to Omni.</p>
            ${sourceVideo ? `<div class="vds-legacy-edit-reference"><span>This source video belongs to the previous workflow.</span><a href="${escapeHtml(legacyEditorUrl)}">Open in Video Editor</a><button type="button" data-remove-reference="${sourceVideo.id}">Remove</button></div>` : ''}
        </section>`;
    }

    function resultPreview(scene, index) {
        const result = scene.active_generation;
        if (result?.previewUrl) {
            const nextScene = scenes()[index + 1] || null;
            const assetKey = `generation_job:${Number(result.id)}`;
            const nextAction = nextScene
                ? `<button class="vds-use-next" type="button" data-use-clip-next="${nextScene.id}" data-asset-key="${escapeHtml(assetKey)}">Use at the start of Sequence ${index + 2}</button>`
                : '';
            return `<div class="vds-generated-clip" data-generated-clip data-asset-key="${escapeHtml(assetKey)}" data-asset-type="generation_job" data-media-type="video">
                <video class="vds-result-video" src="${escapeHtml(result.previewUrl)}"${result.thumbnailUrl ? ` poster="${escapeHtml(result.thumbnailUrl)}"` : ''} controls preload="metadata" playsinline></video>
                <div class="vds-generated-continuation">
                    <button class="vds-generated-drag" type="button" draggable="true" data-generated-drag data-asset-key="${escapeHtml(assetKey)}" aria-label="Drag this result to the start of another sequence">
                        <span class="vds-generated-grip" aria-hidden="true">⋮⋮</span>
                        <span><strong>Drag to continue</strong><small>Its final frame will become the starting image of another sequence.</small></span>
                    </button>
                    ${nextAction}
                </div>
            </div>`;
        }
        const pending = ['queued','submitting','polling','processing'].includes(String(scene.generation?.status || ''));
        return `<div class="vds-result-placeholder"><span aria-hidden="true">${pending ? '◌' : '▶'}</span><strong>${pending ? 'Generating result' : 'No result generated'}</strong><small>${pending ? 'The preview will appear when generation finishes.' : 'Generate this sequence to view it here.'}</small></div>`;
    }

    function generationState(scene, previousScene = null) {
        const jobStatus = String(scene.generation?.status || '');
        if (jobStatus) return { id: jobStatus, label: labels[jobStatus] || jobStatus };
        if (String(scene.prompt || '').trim() || scene.references?.length || previousScene?.active_generation) return { id: 'ready', label: labels.ready };
        return { id: 'draft', label: labels.draft };
    }

    // Only the transitions ffmpeg renders distinctly are offered. 'fade' and
    // 'cross_dissolve' both reach xfade as a plain fade, so one stands for both,
    // and 'ai_transition' is left out because nothing generates it.
    const transitionOptions = [
        { id: 'cut', label: 'Cut' },
        { id: 'cross_dissolve', label: 'Cross dissolve' },
        { id: 'dip_black', label: 'Dip to black' },
        { id: 'dip_white', label: 'Dip to white' },
    ];

    function transitionControls(scene, index) {
        if (index >= scenes().length - 1) return '';
        const type = String(scene.transitionOut?.type || 'cut');
        const known = transitionOptions.some(option => option.id === type) ? type : 'cut';
        // 0.5s is what the builder falls back to when no length was stored, so
        // the control shows that rather than implying a value nobody chose.
        const stored = Number(scene.transitionOut?.durationSeconds || 0);
        const seconds = stored > 0 ? stored : 0.5;
        const durations = [0.3, 0.5, 0.8, 1.2];
        return `<label><span>Transition to Sequence ${index + 2}</span><select data-scene-field="transitionType" data-scene-id="${scene.id}">${
            transitionOptions.map(option => `<option value="${option.id}"${option.id === known ? ' selected' : ''}>${escapeHtml(option.label)}</option>`).join('')
        }</select></label>
        ${known === 'cut' ? '' : `<label><span>Transition length</span><select data-scene-field="transitionDurationSeconds" data-scene-id="${scene.id}">${
            durations.map(value => `<option value="${value}"${Math.abs(seconds - value) < 0.01 ? ' selected' : ''}>${value} s</option>`).join('')
        }</select></label>`}`;
    }

    function renderBoards() {
        const durationValues = state.capabilities.durations || [4,6,8];
        dom.boardGrid.innerHTML = scenes().map((scene, index) => {
            const previousScene = index > 0 ? scenes()[index - 1] : null;
            const status = generationState(scene, previousScene);
            const pending = ['queued','submitting','polling','processing'].includes(status.id)
                || sceneHasUpload(scene.id);
            const previousReady = Boolean(previousScene?.active_generation?.previewUrl);
            const chosenContinuation = generatedContinuation(scene);
            const baseVideo = sourceVideoReference(scene);
            const usedImages = omniImageUsage(scene, index);
            const maxImages = Number(state.capabilities.referenceLimits?.images || 10);
            const expanded = state.openContexts.has(Number(scene.id));
            const download = scene.active_generation?.previewUrl
                ? `<a class="vds-download-clip" href="${escapeHtml(scene.active_generation.previewUrl)}&download=1">Download MP4</a>` : '';
            const resultActions = scene.active_generation?.previewUrl
                ? `<a class="vds-secondary" href="video_editor.php?generation_id=${Number(scene.active_generation.id)}">Edit video</a>`
                : '';
            const generateLabel = scene.active_generation?.previewUrl
                ? 'Regenerar'
                : 'Generate';
            const generationError = status.id === 'failed' && String(scene.generation?.error || '').trim()
                ? `<p class="vds-generation-error" role="alert"><strong>The video could not be generated.</strong><span>${escapeHtml(String(scene.generation.error).trim())}</span></p>`
                : '';
            const continuityText = index === 0
                ? (chosenContinuation
                    ? `Continuation selected from ${chosenContinuation.label}; its final frame will be used.`
                    : 'Add an image from the catalog or from your computer.')
                : chosenContinuation
                    ? `Continuation selected from ${chosenContinuation.label}; it replaces automatic continuity.`
                : previousReady
                    ? `Automatic continuity from the final frame of Sequence ${index}.`
                    : `Generate Sequence ${index} first to link the continuity.`;
            return `<article class="vds-sequence-board" data-sequence-board data-scene-id="${scene.id}" data-accent="${(index % 4) + 1}">
                <header class="vds-board-head">
                    <div class="vds-board-title"><span class="vds-sequence-number">${index + 1}</span><h3>Sequence ${index + 1}</h3></div>
                    <div class="vds-board-actions">
                        <button class="vds-board-drag" type="button" aria-label="Reorder sequence">⋮⋮</button>
                        <button class="vds-board-menu" type="button" data-duplicate-sequence="${scene.id}" aria-label="Duplicate sequence">⧉</button>
                        <button class="vds-board-menu" type="button" data-delete-sequence="${scene.id}" aria-label="Delete sequence">×</button>
                    </div>
                </header>
                <p class="vds-board-subtitle">${escapeHtml(continuityText)}</p>
                <div class="vds-frame-flow">${frameSlot(scene, 'start_frame', '1 · Start image')}<span class="vds-frame-arrow" aria-hidden="true">→</span>${frameSlot(scene, 'end_frame', '2 · Target end image')}</div>
                <button class="vds-context-toggle" type="button" data-toggle-context="${scene.id}" aria-expanded="${expanded ? 'true' : 'false'}"><span>＋ Prompt, references, and duration · ${usedImages}/${maxImages}</span><span>${expanded ? '−' : '+'}</span></button>
                <div class="vds-context-panel" data-context-panel${expanded ? '' : ' hidden'}>
                    <label><span>Prompt</span><textarea data-scene-field="prompt" data-scene-id="${scene.id}" placeholder="Describe camera, movement, pace, light, atmosphere, action, and transition.">${escapeHtml(scene.prompt || '')}</textarea></label>
                    ${referenceManager(scene, index)}
                    <div class="vds-context-grid">
                        <label><span>Duration</span><select data-scene-field="durationSeconds" data-scene-id="${scene.id}">${durationValues.map(value => `<option value="${value}"${Number(scene.durationSeconds) === Number(value) ? ' selected' : ''}>${value} seconds</option>`).join('')}</select></label>
                        ${transitionControls(scene, index)}
                    </div>
                </div>
                ${scene.active_generation?.previewUrl ? `<div class="vds-inline-result"><span>Generated result</span>${resultPreview(scene, index)}</div>` : ''}
                <footer class="vds-board-footer">
                    <span class="vds-generation-state is-${escapeHtml(status.id)}">${escapeHtml(status.label)}</span>
                    <div class="vds-board-footer-actions">${download}${resultActions}<button type="button" data-generate-sequence="${scene.id}"${pending || baseVideo ? ' disabled' : ''}>${generateLabel}</button></div>
                </footer>
                ${generationError}
            </article>`;
        }).join('');
        prepareContinuationFramePreviews();
    }

    function latestExport() { return state.studio?.latestExport || null; }

    function exportPending() {
        return ['queued','processing'].includes(String(latestExport()?.status || ''));
    }

    function ungeneratedScenes() {
        return scenes().filter(scene => !scene.active_generation?.previewUrl).length;
    }

    function sceneSeconds(scene) {
        return Number(scene.active_generation?.durationSeconds || scene.durationSeconds || 0);
    }

    function videoSeconds() {
        return scenes().reduce((total, scene) => total + sceneSeconds(scene), 0);
    }

    const pad = value => String(Math.floor(value)).padStart(2, '0');
    function timecode(seconds) {
        const s = Math.max(0, Number(seconds) || 0);
        return `00:00:${pad(s % 60)}:${pad((s % 1) * 24)}`;
    }
    function clockTime(seconds) {
        const s = Math.max(0, Number(seconds) || 0);
        return `${Math.floor(s / 60)}:${pad(s % 60)}`;
    }
    function decibels(volume) {
        if (!(volume > 0.001)) return '−∞ dB';
        const db = 20 * Math.log10(volume);
        return `${db >= 0 ? '+' : '−'}${Math.abs(db).toFixed(1)} dB`;
    }

    // Geometry of the audio lane, shared by the rubber band and its grips.
    const LANE = { videoHeight: 84, audioHeight: 118, labelHeight: 15, unityAt: 0.42, maxGain: 4 };
    const laneTop = () => LANE.labelHeight + 5;
    const laneBottom = () => LANE.audioHeight - 5;
    const unityY = () => laneTop() + (laneBottom() - laneTop()) * LANE.unityAt;
    function gainToY(volume) {
        return volume >= 1
            ? unityY() - (unityY() - laneTop()) * Math.min(1, (volume - 1) / (LANE.maxGain - 1))
            : laneBottom() - (laneBottom() - unityY()) * volume;
    }
    function yToGain(y) {
        const clamped = Math.max(laneTop(), Math.min(laneBottom(), y));
        return clamped <= unityY()
            ? 1 + ((unityY() - clamped) / (unityY() - laneTop())) * (LANE.maxGain - 1)
            : (laneBottom() - clamped) / (laneBottom() - unityY());
    }

    /**
     * style.css zooms the whole page, so pointer coordinates arrive in a
     * different unit than the CSS pixels the timeline is laid out in. Every
     * drag divides by this factor before converting to seconds.
     */
    function laneScale(element) {
        const width = element?.offsetWidth || 0;
        if (width <= 0) return 1;
        const rendered = element.getBoundingClientRect().width;
        return rendered > 0 ? rendered / width : 1;
    }

    function musicOf() { return currentProject()?.music || null; }

    /**
     * The panel is rebuilt only when its shape changes. Re-rendering on every
     * nudge of the level would tear down the montage <video> mid-playback.
     */
    function panelSignature() {
        const result = latestExport();
        const music = musicOf();
        return [
            currentProject()?.id,
            result?.id, result?.status, result?.previewUrl,
            scenes().map(scene => `${scene.id}:${sceneSeconds(scene)}`).join(','),
            music?.assetId || 0,
            ungeneratedScenes(),
            state.musicUploading ? 1 : 0,
        ].join('|');
    }

    function renderExportPanel() {
        if (!dom.exportPanel) return;
        if (!currentProject() || scenes().length === 0) {
            dom.exportPanel.innerHTML = '';
            dom.exportPanel.dataset.signature = '';
            return;
        }
        const signature = panelSignature();
        if (dom.exportPanel.dataset.signature === signature) { layoutTimeline(); return; }
        dom.exportPanel.dataset.signature = signature;

        const result = latestExport();
        const music = musicOf();
        const missing = ungeneratedScenes();
        const pending = exportPending();
        const total = videoSeconds();

        const monitor = result?.previewUrl
            ? `<video class="vds-monitor-video" src="${escapeHtml(result.previewUrl)}"${
                result.thumbnailUrl ? ` poster="${escapeHtml(result.thumbnailUrl)}"` : ''
              } preload="metadata" playsinline data-montage-video></video>`
            : `<div class="vds-monitor-empty"><strong>${pending ? 'Building the montage' : 'No montage yet'}</strong><small>${
                missing > 0
                    ? `Generate the ${missing === 1 ? 'remaining sequence' : `${missing} remaining sequences`} first.`
                    : pending ? 'It will appear here when it finishes.' : 'Join the sequences to see it here.'
              }</small></div>`;

        const failure = String(result?.status || '') === 'failed' && String(result?.error || '').trim()
            ? `<p class="vds-nle-error" role="alert"><strong>The montage could not be built.</strong><span>${escapeHtml(String(result.error).trim())}</span></p>`
            : '';

        dom.exportPanel.innerHTML = `
        <div class="vds-nle">
            <section class="vds-nle-pane vds-nle-program">
                <div class="vds-monitor">${monitor}</div>
            </section>

            <section class="vds-nle-pane vds-nle-timeline">
                <header class="vds-nle-head"><span>Timeline · ${escapeHtml(currentProject().title || 'Montage')}</span></header>
                <div class="vds-nle-grid">
                    <div class="vds-nle-gutter"><span></span><span>V1</span><span>A1</span></div>
                    <div class="vds-nle-scroll" data-timeline-scroll>
                        <div class="vds-nle-lane" data-timeline-lane>
                            <div class="vds-nle-ruler" data-timeline-ruler><div class="vds-nle-work" data-timeline-work></div></div>
                            <div class="vds-nle-row vds-nle-row--v" data-track-video></div>
                            <div class="vds-nle-row vds-nle-row--a" data-track-audio>${music ? `
                                <div class="vds-nle-clip vds-nle-clip--a" data-music-clip>
                                    <div class="vds-nle-clip-label"><span>${escapeHtml(music.label || 'Music')}</span><em>fx</em></div>
                                    <div class="vds-nle-bed"></div>
                                    <canvas data-wave-canvas></canvas>
                                    <div class="vds-nle-out" data-out-left></div>
                                    <div class="vds-nle-out" data-out-right></div>
                                </div>
                                <svg class="vds-nle-rubber" data-rubber aria-hidden="true"></svg>
                                <button class="vds-nle-grip vds-nle-grip--v" data-grip-gain aria-label="Music level. Drag up or down, or use the arrow keys."></button>
                                <button class="vds-nle-grip vds-nle-grip--h" data-grip-fade-in aria-label="Fade in. Drag sideways, or use the arrow keys."></button>
                                <button class="vds-nle-grip vds-nle-grip--h" data-grip-fade aria-label="Fade out. Drag sideways, or use the arrow keys."></button>
                                <span class="vds-nle-tip" data-tip-gain></span>
                                <span class="vds-nle-tip" data-tip-fade></span>
                                <span class="vds-nle-tip" data-tip-fade-in></span>` : `
                                <label class="vds-nle-drop">
                                    <span>${state.musicUploading ? 'Uploading…' : '＋ Add music'}</span>
                                    <input type="file" accept="audio/*" data-music-file${state.musicUploading ? ' disabled' : ''}>
                                </label>`}
                            </div>
                            <div class="vds-nle-end" data-timeline-end></div>
                            <div class="vds-nle-cti" data-timeline-cti></div>
                        </div>
                    </div>
                </div>
                <footer class="vds-nle-foot">
                    <span class="vds-nle-hint" data-timeline-hint></span>
                    <div class="vds-nle-transport">
                        <button class="vds-nle-icon" type="button" data-montage-step="-1" aria-label="Previous frame"><svg viewBox="0 0 16 16"><path d="M12 2v12l-8-6z"/></svg></button>
                        <button class="vds-nle-icon vds-nle-icon--play" type="button" data-montage-play aria-label="Play"><svg viewBox="0 0 16 16" data-play-icon><path d="M4 2l9 6-9 6z"/></svg></button>
                        <button class="vds-nle-icon" type="button" data-montage-step="1" aria-label="Next frame"><svg viewBox="0 0 16 16"><path d="M4 2v12l8-6z"/></svg></button>
                        <span class="vds-nle-tc" data-montage-time>${timecode(0)}</span>
                        <span class="vds-nle-tc vds-nle-tc--end">/ ${timecode(total)}</span>
                    </div>
                    <input type="range" min="6" max="80" step="1" value="${state.pxs}" data-timeline-zoom aria-label="Timeline zoom">
                </footer>
            </section>

            <aside class="vds-nle-pane vds-nle-inspector">
                <h3>Montage</h3>
                <div class="vds-nle-kv"><span>Sequences</span><b>${scenes().length} · ${clockTime(total)} · ${escapeHtml(currentProject().aspectRatio || '9:16')}</b></div>
                ${music ? `
                <div class="vds-nle-kv"><span>Track A1</span><b>${escapeHtml(music.label || 'Music')}</b></div>
                <div class="vds-nle-two">
                    <label class="vds-nle-file"><span>${state.musicUploading ? 'Uploading…' : 'Replace'}</span><input type="file" accept="audio/*" data-music-file${state.musicUploading ? ' disabled' : ''}></label>
                    <button class="vds-nle-btn" type="button" data-music-clear>Remove</button>
                </div>
                <div class="vds-nle-line"><span>Level</span><output data-out-gain></output></div>
                <div class="vds-nle-line"><span>Fade in</span><output data-out-fade-in></output></div>
                <div class="vds-nle-line"><span>Fade out</span><output data-out-fade></output></div>
                <div class="vds-nle-line"><span>Music in</span><output data-out-offset></output></div>
                <p class="vds-nle-note">Level is the white line over the clip — drag it up or down. The diamonds at each end open the fades.</p>` : `
                <p class="vds-nle-note">Add a track on A1 and it plays across the whole montage, ending with the picture.</p>`}
                ${result?.previewUrl ? `<div class="vds-nle-line"><span>Result</span><output>${clockTime(result.durationSeconds || 0)} · ${Math.round((result.bytes || 0) / 1048576)} MB</output></div>` : ''}
                <button class="vds-nle-btn vds-nle-btn--primary" type="button" data-start-export${missing > 0 || pending ? ' disabled' : ''}>${
                    pending ? 'Building…' : result?.previewUrl ? 'Rebuild montage' : 'Join sequences'
                }</button>
                ${missing > 0 ? `<p class="vds-nle-note">Generate the ${missing === 1 ? 'remaining sequence' : `${missing} remaining sequences`} before joining them.</p>` : ''}
                ${result?.previewUrl ? `<div class="vds-nle-two">
                    <a class="vds-nle-btn" href="${escapeHtml(result.previewUrl)}&download=1">Download MP4</a>
                    <a class="vds-nle-btn" href="videos.php">Open Videos</a>
                </div>` : ''}
                ${failure}
            </aside>
        </div>`;

        buildTimeline();
        wireTimeline();
    }

    function buildTimeline() {
        const total = videoSeconds();
        const ruler = $('[data-timeline-ruler]');
        const row = $('[data-track-video]');
        if (!ruler || !row) return;

        [...ruler.querySelectorAll('b,i')].forEach(node => node.remove());
        const major = state.pxs > 40 ? 2 : state.pxs > 22 ? 5 : 10;
        for (let second = 0; second <= total + 0.001; second += major / 5) {
            const tick = document.createElement('i');
            tick.style.left = `${second * state.pxs}px`;
            if (Math.abs(second % major) < 0.001) {
                tick.className = 'is-major';
                const label = document.createElement('b');
                label.style.left = `${second * state.pxs}px`;
                label.textContent = timecode(second).slice(0, 8) + ':00';
                ruler.append(label);
            }
            ruler.append(tick);
        }

        row.innerHTML = '';
        let at = 0;
        scenes().forEach((scene, index) => {
            const seconds = sceneSeconds(scene);
            const frame = String(scene.active_generation?.thumbnailUrl || '');
            const clip = document.createElement('div');
            clip.className = 'vds-nle-clip vds-nle-clip--v';
            clip.style.left = `${at * state.pxs}px`;
            clip.style.width = `${seconds * state.pxs}px`;
            clip.innerHTML =
                `<div class="vds-nle-clip-label"><span>Sequence ${index + 1}</span><em>${seconds.toFixed(1)}s</em></div>` +
                (frame ? `<img src="${escapeHtml(frame)}" alt="" draggable="false">` : '');
            row.append(clip);
            at += seconds;
        });

        drawWaveform();
        layoutTimeline();
    }

    function drawWaveform() {
        const canvas = $('[data-wave-canvas]');
        const music = musicOf();
        if (!canvas || !music) return;
        const peaks = Array.isArray(music.peaks) ? music.peaks : [];
        const width = Math.max(1, Math.round(Number(music.durationSeconds || 0) * state.pxs));
        const height = LANE.audioHeight - LANE.labelHeight;
        canvas.width = width; canvas.height = height;
        canvas.style.width = `${width}px`;
        const g = canvas.getContext('2d');
        g.clearRect(0, 0, width, height);
        if (peaks.length === 0) return;
        g.fillStyle = '#7fc9e8';
        const step = width / peaks.length;
        const mid = height / 2;
        const amp = height * 0.45;
        for (let i = 0; i < peaks.length; i++) {
            const value = Math.max(0, Math.min(1, peaks[i])) * amp;
            g.fillRect(i * step, mid - value, Math.max(0.55, step * 0.82), value * 2);
        }
    }

    function drawRubber() {
        const svg = $('[data-rubber]');
        const music = musicOf();
        if (!svg || !music) return;
        const total = videoSeconds();
        const track = Number(music.durationSeconds || 0);
        const offset = Number(music.offsetSeconds || 0);
        const gain = Number(music.volume ?? 1);
        const fade = Number(music.fadeOutSeconds || 0);
        const fadeIn = Number(music.fadeInSeconds || 0);
        const width = Math.max(total, offset + track) * state.pxs;

        svg.setAttribute('width', width);
        svg.setAttribute('height', LANE.audioHeight);
        svg.setAttribute('viewBox', `0 0 ${width} ${LANE.audioHeight}`);
        svg.style.width = `${width}px`;
        svg.style.height = `${LANE.audioHeight}px`;

        const from = Math.max(offset, 0) * state.pxs;
        const to = Math.min(offset + track, total) * state.pxs;
        const y = gainToY(gain);
        const inX = Math.min(to, from + fadeIn * state.pxs);
        const outX = Math.max(inX, Math.min(to, (total - fade) * state.pxs));

        svg.innerHTML =
            `<line x1="${from}" y1="${gainToY(1)}" x2="${to}" y2="${gainToY(1)}" stroke="#4d7f99" stroke-width="1" stroke-dasharray="2 3"/>` +
            `<path d="M${from} ${fadeIn > 0.01 ? laneBottom() : y} L${inX} ${y} L${outX} ${y} L${to} ${laneBottom()}" fill="none" stroke="#e6e6e6" stroke-width="1.4"/>`;

        // The level band spans the whole held section so it can be grabbed anywhere.
        const gainGrip = $('[data-grip-gain]');
        if (gainGrip) {
            gainGrip.style.left = `${inX}px`;
            gainGrip.style.width = `${Math.max(24, outX - inX)}px`;
            gainGrip.style.top = `${y}px`;
        }
        const inGrip = $('[data-grip-fade-in]');
        if (inGrip) { inGrip.style.left = `${inX}px`; inGrip.style.top = `${y}px`; }
        const fadeGrip = $('[data-grip-fade]');
        if (fadeGrip) { fadeGrip.style.left = `${outX}px`; fadeGrip.style.top = `${y}px`; }

        const gainTip = $('[data-tip-gain]');
        if (gainTip) {
            gainTip.textContent = decibels(gain);
            gainTip.style.left = `${from + 5}px`;
            gainTip.style.top = `${Math.max(LANE.labelHeight + 1, y - 15)}px`;
        }
        const fadeTip = $('[data-tip-fade]');
        if (fadeTip) {
            fadeTip.textContent = `${fade.toFixed(1)} s`;
            fadeTip.style.left = `${Math.max(0, outX - 40)}px`;
            fadeTip.style.top = `${y + 6}px`;
        }
        const inTip = $('[data-tip-fade-in]');
        if (inTip) {
            inTip.textContent = fadeIn > 0.01 ? `${fadeIn.toFixed(1)} s` : '';
            inTip.style.left = `${inX + 6}px`;
            inTip.style.top = `${y + 6}px`;
        }

        const gainOut = $('[data-out-gain]'); if (gainOut) gainOut.textContent = decibels(gain);
        const fadeOut = $('[data-out-fade]'); if (fadeOut) fadeOut.textContent = `${fade.toFixed(1)} s`;
        const fadeInOut = $('[data-out-fade-in]'); if (fadeInOut) fadeInOut.textContent = `${fadeIn.toFixed(1)} s`;
        const offsetOut = $('[data-out-offset]'); if (offsetOut) offsetOut.textContent = timecode(Math.max(0, offset));
    }

    function layoutTimeline() {
        const lane = $('[data-timeline-lane]');
        if (!lane) return;
        const total = videoSeconds();
        const music = musicOf();
        const track = Number(music?.durationSeconds || 0);
        const offset = Number(music?.offsetSeconds || 0);
        const width = Math.max(total, offset + track) * state.pxs;
        lane.style.width = `${width}px`;

        const clip = $('[data-music-clip]');
        if (clip && music) {
            clip.style.left = `${offset * state.pxs}px`;
            clip.style.width = `${track * state.pxs}px`;
            const left = $('[data-out-left]');
            const right = $('[data-out-right]');
            if (left) left.style.cssText = `left:0;width:${Math.max(0, -offset) * state.pxs}px`;
            if (right) right.style.cssText = `right:0;width:${Math.max(0, (offset + track) - total) * state.pxs}px`;
        }

        const work = $('[data-timeline-work]');
        if (work) work.style.width = `${total * state.pxs}px`;

        const height = 24 + LANE.videoHeight + LANE.audioHeight;
        const end = $('[data-timeline-end]');
        if (end) { end.style.left = `${total * state.pxs}px`; end.style.height = `${height}px`; }
        const cti = $('[data-timeline-cti]');
        if (cti) { cti.style.left = `${state.playhead * state.pxs}px`; cti.style.height = `${height}px`; }

        const hint = $('[data-timeline-hint]');
        if (hint) {
            hint.textContent = !music
                ? 'Drop a track on A1 to score the montage.'
                : offset < 0 ? `The montage starts ${clockTime(-offset)} into the track.`
                : offset > 0 ? `Opens in silence; the music comes in at ${clockTime(offset)}.`
                : 'The music starts with the first frame.';
        }
        drawRubber();
    }

    function wireTimeline() {
        const scroll = $('[data-timeline-scroll]');
        const lane = $('[data-timeline-lane]');
        const video = $('[data-montage-video]');
        const audio = musicOf() ? ensureMusicAudio() : null;
        if (!lane) return;

        const seek = seconds => {
            state.playhead = Math.max(0, Math.min(videoSeconds(), seconds));
            if (video) video.currentTime = state.playhead;
            syncMusicAudio();
            layoutTimeline();
            const time = $('[data-montage-time]');
            if (time) time.textContent = timecode(state.playhead);
        };

        $('[data-timeline-ruler]')?.addEventListener('pointerdown', event => {
            const bounds = lane.getBoundingClientRect();
            seek((event.clientX - bounds.left) / laneScale(lane) / state.pxs);
        });

        $('[data-timeline-zoom]')?.addEventListener('input', event => {
            const anchor = scroll ? (scroll.scrollLeft + scroll.clientWidth / 2) / state.pxs : 0;
            state.pxs = Number(event.target.value);
            buildTimeline();
            if (scroll) scroll.scrollLeft = Math.max(0, anchor * state.pxs - scroll.clientWidth / 2);
        });

        const clip = $('[data-music-clip]');
        clip?.addEventListener('pointerdown', event => {
            if (event.button !== 0 || event.target.closest('.vds-nle-grip')) return;
            event.preventDefault();
            clip.setPointerCapture(event.pointerId);
            clip.classList.add('is-dragging');
            const music = musicOf();
            const track = Number(music.durationSeconds || 0);
            const originX = event.clientX;
            const originOffset = Number(music.offsetSeconds || 0);
            let offset = originOffset;
            const scale = laneScale(lane);
            const move = e => {
                offset = Math.max(-(track - 1), Math.min(originOffset + (e.clientX - originX) / scale / state.pxs, videoSeconds() - 1));
                music.offsetSeconds = offset;
                layoutTimeline();
            };
            const done = () => {
                clip.classList.remove('is-dragging');
                clip.removeEventListener('pointermove', move);
                clip.removeEventListener('pointerup', done);
                clip.removeEventListener('pointercancel', done);
                if (Math.abs(offset - originOffset) > 0.05) updateMusic({ offsetSeconds: Number(offset.toFixed(3)) });
            };
            clip.addEventListener('pointermove', move);
            clip.addEventListener('pointerup', done);
            clip.addEventListener('pointercancel', done);
        });

        wireGrip('[data-grip-gain]', (_x, y, music) => { music.volume = Math.round(yToGain(y) * 100) / 100; },
            music => ({ volume: music.volume }));
        wireGrip('[data-grip-fade]', (x, _y, music) => {
            music.fadeOutSeconds = Math.max(0, Math.min(4, Math.round((videoSeconds() - x / state.pxs) * 10) / 10));
        }, music => ({ fadeOutSeconds: music.fadeOutSeconds }));
        wireGrip('[data-grip-fade-in]', (x, _y, music) => {
            const entry = Math.max(0, Number(music.offsetSeconds || 0));
            music.fadeInSeconds = Math.max(0, Math.min(4, Math.round((x / state.pxs - entry) * 10) / 10));
        }, music => ({ fadeInSeconds: music.fadeInSeconds }));

        $('[data-grip-gain]')?.addEventListener('keydown', event => {
            const music = musicOf();
            if (!music) return;
            const step = event.shiftKey ? 0.25 : 0.05;
            if (event.key === 'ArrowUp') music.volume = Math.min(LANE.maxGain, Number(music.volume ?? 1) + step);
            else if (event.key === 'ArrowDown') music.volume = Math.max(0, Number(music.volume ?? 1) - step);
            else return;
            event.preventDefault();
            music.volume = Math.round(music.volume * 100) / 100;
            drawRubber();
            updateMusic({ volume: music.volume });
        });
        $('[data-grip-fade-in]')?.addEventListener('keydown', event => {
            const music = musicOf();
            if (!music) return;
            if (event.key === 'ArrowRight') music.fadeInSeconds = Math.min(4, Number(music.fadeInSeconds || 0) + 0.1);
            else if (event.key === 'ArrowLeft') music.fadeInSeconds = Math.max(0, Number(music.fadeInSeconds || 0) - 0.1);
            else return;
            event.preventDefault();
            music.fadeInSeconds = Math.round(music.fadeInSeconds * 10) / 10;
            drawRubber();
            updateMusic({ fadeInSeconds: music.fadeInSeconds });
        });
        $('[data-grip-fade]')?.addEventListener('keydown', event => {
            const music = musicOf();
            if (!music) return;
            if (event.key === 'ArrowLeft') music.fadeOutSeconds = Math.min(4, Number(music.fadeOutSeconds || 0) + 0.1);
            else if (event.key === 'ArrowRight') music.fadeOutSeconds = Math.max(0, Number(music.fadeOutSeconds || 0) - 0.1);
            else return;
            event.preventDefault();
            music.fadeOutSeconds = Math.round(music.fadeOutSeconds * 10) / 10;
            drawRubber();
            updateMusic({ fadeOutSeconds: music.fadeOutSeconds });
        });

        $('[data-montage-play]')?.addEventListener('click', () => {
            if (!video) return;
            if (video.paused) video.play().catch(() => undefined); else video.pause();
        });
        $$('[data-montage-step]').forEach(button => button.addEventListener('click', () => {
            seek(state.playhead + Number(button.dataset.montageStep) / 24);
        }));

        if (video) {
            video.addEventListener('timeupdate', () => {
                state.playhead = video.currentTime;
                syncMusicAudio();
                layoutTimeline();
                const time = $('[data-montage-time]');
                if (time) time.textContent = timecode(state.playhead);
                if (!scroll) return;
                const x = state.playhead * state.pxs;
                if (x < scroll.scrollLeft || x > scroll.scrollLeft + scroll.clientWidth - 40) {
                    scroll.scrollLeft = Math.max(0, x - scroll.clientWidth * 0.3);
                }
            });
            video.addEventListener('play', () => { setPlayIcon(true); audio?.play().catch(() => undefined); });
            ['pause','ended'].forEach(name => video.addEventListener(name, () => { setPlayIcon(false); audio?.pause(); }));
        }
    }

    function wireGrip(selector, apply, changes) {
        const grip = $(selector);
        if (!grip) return;
        grip.addEventListener('pointerdown', event => {
            const music = musicOf();
            if (!music) return;
            event.preventDefault();
            event.stopPropagation();
            grip.setPointerCapture(event.pointerId);
            const row = $('[data-track-audio]');
            const scale = laneScale(row);
            const move = e => {
                const bounds = row.getBoundingClientRect();
                apply((e.clientX - bounds.left) / scale, (e.clientY - bounds.top) / scale, music);
                drawRubber();
            };
            const done = () => {
                grip.removeEventListener('pointermove', move);
                grip.removeEventListener('pointerup', done);
                grip.removeEventListener('pointercancel', done);
                updateMusic(changes(music));
            };
            grip.addEventListener('pointermove', move);
            grip.addEventListener('pointerup', done);
            grip.addEventListener('pointercancel', done);
        });
    }

    function setPlayIcon(playing) {
        const icon = $('[data-play-icon]');
        if (icon) icon.innerHTML = playing ? '<path d="M4 2h3.2v12H4zM8.8 2H12v12H8.8z"/>' : '<path d="M4 2l9 6-9 6z"/>';
    }

    /** One detached audio element carries the preview mix, outside the re-render. */
    function ensureMusicAudio() {
        const music = musicOf();
        if (!music) { state.musicAudio?.pause(); state.musicAudio = null; return null; }
        if (!state.musicAudio || state.musicAudio.dataset.assetId !== String(music.assetId)) {
            state.musicAudio?.pause();
            state.musicAudio = new Audio(music.previewUrl);
            state.musicAudio.dataset.assetId = String(music.assetId);
            state.musicAudio.preload = 'metadata';
        }
        return state.musicAudio;
    }

    function syncMusicAudio() {
        const audio = state.musicAudio;
        const music = musicOf();
        if (!audio || !music) return;
        const offset = Number(music.offsetSeconds || 0);
        const track = Number(music.durationSeconds || 0);
        const fade = Number(music.fadeOutSeconds || 0);
        const total = videoSeconds();
        const into = state.playhead - offset;
        if (into < 0 || into > track) { audio.volume = 0; return; }
        const remaining = total - state.playhead;
        const entry = Math.max(0, offset);
        const fadeIn = Number(music.fadeInSeconds || 0);
        const sinceEntry = state.playhead - entry;
        // The element cannot amplify past 1, so a boost is only heard in the MP4.
        const gain = Math.min(1, Number(music.volume ?? 1));
        const rampIn = fadeIn > 0 && sinceEntry < fadeIn ? Math.max(0, sinceEntry / fadeIn) : 1;
        const rampOut = fade > 0 && remaining < fade ? Math.max(0, remaining / fade) : 1;
        audio.volume = gain * Math.min(rampIn, rampOut);
        if (Math.abs(audio.currentTime - into) > 0.25) audio.currentTime = into;
    }

    function updateExportPolling() {
        if (!exportPending() || document.hidden) {
            window.clearTimeout(state.exportTimer);
            state.exportTimer = null;
            return;
        }
        if (!state.exportTimer) state.exportTimer = window.setTimeout(pollExport, 6000);
    }

    async function pollExport() {
        state.exportTimer = null;
        if (!currentProject() || document.hidden) return updateExportPolling();
        try {
            const result = await request(state.endpoints.exportStatus || 'video_export_status.php', { projectId: currentProject().id });
            if (result.project && result.scenes) applyStudio(result);
        } catch (_) { /* keep the board usable during a transient polling failure */ }
        updateExportPolling();
    }

    async function uploadMusic(file) {
        if (!file || !currentProject()) return;
        const body = new FormData();
        body.append('csrf', state.csrf);
        body.append('projectId', String(currentProject().id));
        body.append('version', String(currentProject().version));
        body.append('music', file);
        state.musicUploading = true;
        renderExportPanel();
        try {
            const response = await fetch(state.endpoints.musicUpload || 'video_music_upload.php', { method: 'POST', credentials: 'same-origin', body });
            let data;
            try { data = await response.json(); } catch (_) { data = { ok: false, error: `The upload failed (${response.status}).` }; }
            if (!response.ok || !data.ok) throw new Error(data.error || `The upload failed (${response.status}).`);
            state.musicUploading = false;
            applyStudio(data);
            toast('Music added');
        } catch (error) {
            state.musicUploading = false;
            renderExportPanel();
            toast(error.message || 'The music could not be uploaded.', true);
        }
    }

    function updateMusic(changes, successMessage = '') {
        if (!currentProject() || !changes || Object.keys(changes).length === 0) return;
        queueMutation(() => request(state.endpoints.musicUpdate || 'video_music_update.php', {
            projectId: currentProject().id,
            version: currentProject().version,
            ...changes,
        }), successMessage);
    }

    function startExport() {
        if (ungeneratedScenes() > 0 || exportPending()) return;
        queueMutation(
            () => request(state.endpoints.exportStart || 'video_export_start.php', { projectId: currentProject().id, version: currentProject().version, kind: 'final' }),
            'Montage started'
        ).finally(updateExportPolling);
    }

    function destroySortables() {
        state.sortables.forEach(sortable => { try { sortable.destroy(); } catch (_) { /* already destroyed */ } });
        state.sortables = [];
    }

    function setupSortables() {
        destroySortables();
        if (typeof window.Sortable !== 'function') return;
        if (dom.catalogRail.querySelector('[data-catalog-card]')) {
            state.sortables.push(window.Sortable.create(dom.catalogRail, {
                group: { name: 'video-references', pull: 'clone', put: false, revertClone: true },
                sort: false,
                draggable: '[data-catalog-card]',
                filter: '[data-toggle-favorite]',
                preventOnFilter: false,
                animation: 150,
                delayOnTouchOnly: true,
                delay: 170,
                touchStartThreshold: 4,
                chosenClass: 'is-dragging',
                dragClass: 'vds-sortable-drag',
            }));
        }
        $$('[data-frame-drop],[data-reference-drop]').forEach(slot => {
            state.sortables.push(window.Sortable.create(slot, {
                group: { name: 'video-references', pull: false, put: ['video-references'] },
                sort: false,
                draggable: '[data-catalog-card]',
                animation: 120,
                onMove: event => {
                    const asset = assetByKey(String(event.dragged?.dataset?.assetKey || ''));
                    if (!asset) return false;
                    const role = String(slot.dataset.role || 'reference');
                    if (asset.mediaType === 'video') {
                        if (role === 'start_frame' && asset.type !== 'generation_job') return false;
                        if (!['start_frame','source_video'].includes(role)) return false;
                    } else if (role === 'source_video') return false;
                    if (asset.type === 'generation_job' && Number(asset.sceneId) === Number(slot.dataset.sceneId)) return false;
                    return true;
                },
                onAdd: event => {
                    const assetKey = String(event.item?.dataset?.assetKey || '');
                    event.item?.remove();
                    root.classList.remove('is-dragging-generated');
                    slot.classList.remove('is-drop-target');
                    if (assetKey) assignReference(Number(slot.dataset.sceneId), String(slot.dataset.role), assetKey);
                },
            }));
        });
        if (scenes().length > 1) {
            state.sortables.push(window.Sortable.create(dom.boardGrid, {
                animation: 160,
                draggable: '[data-sequence-board]',
                handle: '.vds-board-drag',
                ghostClass: 'vds-sortable-ghost',
                dragClass: 'vds-sortable-drag',
                delayOnTouchOnly: true,
                delay: 170,
                touchStartThreshold: 4,
                onEnd: () => {
                    const ids = $$('[data-sequence-board]', dom.boardGrid).map(board => Number(board.dataset.sceneId));
                    const unchanged = ids.every((id, index) => id === Number(scenes()[index]?.id));
                    if (!unchanged) queueMutation(() => api({ action: 'scene_reorder', projectId: currentProject().id, version: currentProject().version, sceneIds: ids }), 'Secuencias reordenadas');
                },
            }));
        }
    }

    function renderAll() {
        renderProjectControls();
        if (currentProject()) {
            renderCatalog();
            renderBoards();
            setupSortables();
        } else {
            destroySortables();
        }
        renderExportPanel();
        updateGenerationPolling();
        updateExportPolling();
    }

    async function assignReference(sceneId, role, assetKey) {
        let asset = assetByKey(assetKey);
        // A clip generated in this session (or in another tab) may not be in the
        // library yet. Pull it in once before giving up, instead of doing nothing.
        if (!asset && String(assetKey).startsWith('generation_job:')) {
            await refreshLibrary();
            asset = assetByKey(assetKey);
        }
        const scene = sceneById(sceneId);
        if (!scene) return;
        if (!asset) return toast('That result is not available as a reference yet. Try again in a moment.', true);
        if (asset.mediaType === 'video') {
            if (role === 'start_frame' && asset.type !== 'generation_job') {
                return toast('Uploaded videos are edited from the Videos section.', true);
            }
            if (!['start_frame','source_video'].includes(role)) {
                return toast('A generated video can only continue another sequence.', true);
            }
        } else if (role === 'source_video') {
            return toast('Source Video accepts one video only.', true);
        }
        if (asset.type === 'generation_job' && Number(asset.sceneId) === Number(sceneId)) {
            return toast('The result must continue in another sequence, not the same one.', true);
        }
        const alreadyAttached = (scene.references || []).some(reference => reference.role === role && reference.sourceType === asset.type && Number(reference.sourceId) === Number(asset.id));
        if (alreadyAttached) return toast('This reference is already attached to this block.');
        if (asset.mediaType === 'image') {
            const sceneIndex = scenes().findIndex(item => Number(item.id) === Number(sceneId));
            if (availableImagesForRole(scene, sceneIndex, role) < 1) {
                return toast('Omni accepts a maximum of 10 images per sequence.', true);
            }
        }
        const targetIndex = scenes().findIndex(item => Number(item.id) === Number(sceneId)) + 1;
        const sourceIndex = scenes().findIndex(item => Number(item.id) === Number(asset.sceneId)) + 1;
        const message = asset.type === 'generation_job'
            ? `Sequence ${targetIndex} linked${sourceIndex > 0 ? ` to the result of Sequence ${sourceIndex}` : ' to the selected video'}`
            : `${referenceRoleLabel(role)} actualizado`;
        queueMutation(() => api({
            action: 'reference_add',
            sceneId,
            version: currentProject().version,
            reference: { sourceType: asset.type, sourceId: asset.id, role },
        }), message);
    }

    async function uploadReferenceFiles(sceneId, role, fileList) {
        const files = Array.from(fileList || []);
        const scene = sceneById(sceneId);
        if (!scene || files.length === 0) return;
        const multiRole = role === 'reference';
        if (!multiRole && files.length > 1) return toast('This reference accepts one file only.', true);
        if (role === 'source_video') {
            if (files.some(file => !String(file.type || '').startsWith('video/'))) return toast('Source Video accepts one video only.', true);
        } else {
            if (files.some(file => !String(file.type || '').startsWith('image/'))) return toast('Visual references accept images only.', true);
            const sceneIndex = scenes().findIndex(item => Number(item.id) === Number(sceneId));
            if (files.length > availableImagesForRole(scene, sceneIndex, role)) {
                return toast('The selection exceeds Omni’s maximum of 10 images.', true);
            }
        }
        const slotKey = uploadSlotKey(sceneId, role);
        if (state.uploadingSlots.has(slotKey)) return;

        state.uploadingSlots.add(slotKey);
        renderBoards();
        setupSortables();
        try {
            await queueMutation(async () => {
                const form = new FormData();
                form.set('csrf', state.csrf);
                form.set('sceneId', String(sceneId));
                form.set('version', String(currentProject().version));
                form.set('role', role);
                files.forEach(file => form.append('references[]', file, file.name));
                const response = await fetch(state.endpoints.referenceUpload || 'video_reference_upload.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' },
                    body: form,
                });
                let result;
                try { result = await response.json(); }
                catch (_) { result = { ok: false, error: `The upload failed (${response.status}).` }; }
                if (!response.ok || !result.ok) {
                    const error = new Error(result.error || `The upload failed (${response.status}).`);
                    error.status = response.status;
                    throw error;
                }
                return result;
            }, `${referenceRoleLabel(role)} updated from your computer`);
        } finally {
            state.uploadingSlots.delete(slotKey);
            renderAll();
        }
    }

    function referenceRoleLabel(role) {
        return ({
            start_frame: 'Start image',
            end_frame: 'Target end image',
            artwork_fidelity: 'Artwork',
            character_identity: 'Personaje',
            wardrobe_identity: 'Vestuario',
            source_video: 'Source video',
            reference: 'Additional references',
        })[String(role)] || 'Reference';
    }

    function availableImagesForRole(scene, sceneIndex, role) {
        const maxImages = Number(state.capabilities.referenceLimits?.images || 10);
        let used = omniImageUsage(scene, sceneIndex);
        const current = referenceFor(scene, role);
        if (current?.mediaType === 'image' && ['start_frame','end_frame','artwork_fidelity','character_identity','wardrobe_identity','main'].includes(String(role))) used--;
        if (role === 'start_frame' && sceneIndex > 0 && !explicitStartReference(scene) && !sourceVideoReference(scene)) used--;
        return Math.max(0, maxImages - used);
    }

    function addSequence() {
        if (!currentProject()) return;
        const number = scenes().length + 1;
        queueMutation(() => api({
            action: 'scene_create',
            projectId: currentProject().id,
            version: currentProject().version,
            scene: { title: `Sequence ${number}`, generationMode: defaultGenerationMode(), durationSeconds: defaultGenerationDuration() },
        }), `Sequence ${number} added`);
    }

    async function ensureMinimumSequences() {
        if (state.seeding || !currentProject() || scenes().length >= 3) return;
        state.seeding = true;
        try {
            while (currentProject() && scenes().length < 3) {
                const number = scenes().length + 1;
                await queueMutation(() => api({
                    action: 'scene_create', projectId: currentProject().id, version: currentProject().version,
                    scene: { title: `Sequence ${number}`, generationMode: defaultGenerationMode(), durationSeconds: defaultGenerationDuration() },
                }));
            }
        } catch (_) { /* the mutation already reported the problem */ }
        finally { state.seeding = false; }
    }

    function showGenerationModal(sceneId) {
        const scene = sceneById(sceneId);
        if (!scene) return;
        const index = scenes().findIndex(item => Number(item.id) === Number(scene.id));
        const previousScene = index > 0 ? scenes()[index - 1] : null;
        const startReference = explicitStartReference(scene);
        const baseVideo = sourceVideoReference(scene);
        const continuityLabel = baseVideo
            ? `Edit video: ${baseVideo.label}`
            : startReference
            ? (startReference.mediaType === 'video' ? `Final frame of ${startReference.label}` : `Start Frame: ${startReference.label}`)
            : (index === 0 ? 'First sequence' : (previousScene?.active_generation ? `Automatic from Sequence ${index}` : 'No previous result yet'));
        const referenceCount = Array.isArray(scene.references) ? scene.references.length : 0;
        state.pendingGenerationSceneId = scene.id;
        dom.generationSummary.innerHTML = `<div class="vds-generation-facts">
            <div><span>Sequence</span><strong>${index + 1}</strong></div>
            <div><span>Attached references</span><strong>${referenceCount}</strong></div>
            <div><span>Continuidad</span><strong>${escapeHtml(continuityLabel)}</strong></div>
            <div><span>Duration</span><strong>${baseVideo ? 'Preserved from the source video' : `${Number(scene.durationSeconds)} seconds`}</strong></div>
            <div><span>Modelo</span><strong>${escapeHtml(state.capabilities.generationModel || 'Gemini Omni Flash')}</strong></div>
        </div>`;
        dom.generationModal.hidden = false;
    }

    async function toggleFavorite(assetKey, button) {
        const asset = assetByKey(assetKey);
        const assetId = Number(asset?.id || 0);
        if (!asset || button.disabled) return;
        button.disabled = true;
        try {
            const form = new FormData();
            form.set('mockup_id', String(assetId));
            const response = await fetch('toggle_mockup_favorite.php', { method: 'POST', credentials: 'same-origin', body: form });
            const result = await response.json();
            if (!response.ok || !result.ok) throw new Error(result.error || 'The favorite could not be updated.');
            asset.favorite = Boolean(result.favorite);
            asset.favoriteRank = asset.favorite ? -1 : Number.MAX_SAFE_INTEGER;
            renderCatalog();
            setupSortables();
        } catch (error) {
            toast(error.message, true);
            button.disabled = false;
        }
    }

    function hasPendingGeneration() {
        return scenes().some(scene => ['queued','submitting','polling','processing'].includes(String(scene.generation?.status || '')));
    }

    function updateGenerationPolling() {
        if (!hasPendingGeneration() || document.hidden) {
            window.clearTimeout(state.generationTimer);
            state.generationTimer = null;
            return;
        }
        if (!state.generationTimer) state.generationTimer = window.setTimeout(pollGeneration, 7000);
    }

    function generationSignature() {
        return scenes().map(scene => `${scene.id}:${Number(scene.active_generation?.id || 0)}`).join(',');
    }

    async function pollGeneration() {
        state.generationTimer = null;
        if (!currentProject() || document.hidden) return updateGenerationPolling();
        try {
            const before = generationSignature();
            const result = await request(state.endpoints.generationStatus || 'video_generation_status.php', { projectId: currentProject().id });
            if (result.project && result.scenes) applyStudio(result);
            // A finished clip only becomes assignable once it reaches the library,
            // so pull it in before the board offers it as another sequence's start.
            if (generationSignature() !== before) await refreshLibrary();
        } catch (_) { /* keep the board usable during a transient polling failure */ }
        updateGenerationPolling();
    }

    root.addEventListener('click', event => {
        const aspectButton = event.target.closest('[data-project-aspect-ratio]');
        if (aspectButton) {
            const project = currentProject();
            const aspectRatio = String(aspectButton.dataset.projectAspectRatio || '');
            if (!project || !aspectRatio || aspectRatio === String(project.aspectRatio || '')) return;
            queueMutation(() => {
                const latestProject = currentProject();
                return api({
                    action: 'project_update',
                    projectId: latestProject.id,
                    version: latestProject.version,
                    changes: { aspectRatio },
                });
            }, 'Format updated');
            return;
        }

        const favorite = event.target.closest('[data-toggle-favorite]');
        if (favorite) {
            event.stopPropagation();
            const card = favorite.closest('[data-catalog-card]');
            if (card) toggleFavorite(String(card.dataset.assetKey || ''), favorite);
            return;
        }

        const newProject = event.target.closest('[data-new-project]');
        if (newProject) {
            if (newProject.disabled) return;
            newProject.disabled = true;
            createProjectNow().finally(() => { newProject.disabled = false; });
            return;
        }
        if (event.target.closest('[data-save-project]')) {
            dom.projectTitle?.blur();
            state.mutation.then(() => {
                setSaveState('Saved');
                toast('Project saved');
            });
            return;
        }
        if (event.target.closest('[data-delete-project]')) {
            const project = currentProject();
            if (!project) return;
            const clipCount = Number(project.generatedClipCount || 0);
            const detail = clipCount > 0
                ? ` Its ${clipCount} generated video${clipCount === 1 ? '' : 's'} will remain in Videos.`
                : '';
            if (!window.confirm(`Remove “${project.title}” from the workspace?${detail}`)) return;
            queueMutation(() => api({ action: 'project_delete', projectId: project.id, version: project.version }))
                .then(async result => {
                    state.projects = Array.isArray(result.projects) ? result.projects : [];
                    const nextProject = state.projects[0] || null;
                    if (nextProject) {
                        const next = await api({ action: 'project_read', projectId: nextProject.id });
                        applyStudio(next, true);
                        window.history.replaceState({}, '', `video.php?project=${nextProject.id}`);
                    } else {
                        await createProjectNow();
                    }
                    toast('Project removed from workspace');
                });
            return;
        }
        if (event.target.closest('[data-add-sequence]')) { addSequence(); return; }

        const uploadReference = event.target.closest('[data-upload-reference]');
        if (uploadReference) {
            event.stopPropagation();
            const sceneId = Number(uploadReference.dataset.uploadReference);
            const role = String(uploadReference.dataset.role || 'start_frame');
            const input = $(`[data-reference-file-input][data-scene-id="${sceneId}"][data-role="${role}"]`);
            if (input && !uploadReference.disabled) input.click();
            return;
        }

        const scroll = event.target.closest('[data-scroll-catalog]');
        if (scroll) {
            dom.catalogRail.scrollBy({ left: Number(scroll.dataset.scrollCatalog) * Math.max(320, dom.catalogRail.clientWidth * .72), behavior: 'smooth' });
            return;
        }

        const catalogCard = event.target.closest('[data-catalog-card]');
        if (catalogCard) {
            state.selectedAssetKey = String(catalogCard.dataset.assetKey || '');
            renderCatalog();
            setupSortables();
            return;
        }

        const remove = event.target.closest('[data-remove-reference]');
        if (remove) {
            event.stopPropagation();
            queueMutation(() => api({ action: 'reference_remove', referenceId: Number(remove.dataset.removeReference), version: currentProject().version }), 'Reference removed');
            return;
        }

        const referenceTarget = event.target.closest('[data-frame-drop],[data-reference-drop]');
        if (referenceTarget && !event.target.closest('[data-remove-reference]') && !event.target.closest('video') && !event.target.closest('input')) {
            const sceneId = Number(referenceTarget.dataset.sceneId);
            const role = String(referenceTarget.dataset.role);
            if (state.selectedAssetKey) assignReference(sceneId, role, state.selectedAssetKey);
            else if (!referenceTarget.classList.contains('has-media')) referenceTarget.querySelector('[data-reference-file-input]')?.click();
            return;
        }

        const context = event.target.closest('[data-toggle-context]');
        if (context) {
            const id = Number(context.dataset.toggleContext);
            if (state.openContexts.has(id)) state.openContexts.delete(id); else state.openContexts.add(id);
            renderBoards();
            setupSortables();
            return;
        }

        const duplicateScene = event.target.closest('[data-duplicate-sequence]');
        if (duplicateScene) {
            const id = Number(duplicateScene.dataset.duplicateSequence);
            queueMutation(() => api({ action: 'scene_duplicate', sceneId: id, version: currentProject().version }), 'Sequence duplicated');
            return;
        }

        const removeScene = event.target.closest('[data-delete-sequence]');
        if (removeScene) {
            const id = Number(removeScene.dataset.deleteSequence);
            const index = scenes().findIndex(scene => Number(scene.id) === id) + 1;
            if (scenes().length <= 1) return toast('The project must keep at least one sequence.', true);
            if (window.confirm(`Delete Sequence ${index}?`)) {
                state.openContexts.delete(id);
                queueMutation(() => api({ action: 'scene_delete', sceneId: id, version: currentProject().version }), 'Sequence deleted');
            }
            return;
        }

        const useClipNext = event.target.closest('[data-use-clip-next]');
        if (useClipNext) {
            assignReference(Number(useClipNext.dataset.useClipNext), 'start_frame', String(useClipNext.dataset.assetKey || ''));
            return;
        }

        if (event.target.closest('[data-start-export]')) { startExport(); return; }
        if (event.target.closest('[data-music-clear]')) { updateMusic({ clear: true }, 'Music removed'); return; }

        const generate = event.target.closest('[data-generate-sequence]');
        if (generate) { showGenerationModal(Number(generate.dataset.generateSequence)); return; }
        if (event.target.closest('[data-cancel-generation]')) { dom.generationModal.hidden = true; state.pendingGenerationSceneId = null; return; }
        if (event.target.closest('[data-confirm-generation]')) {
            const sceneId = Number(state.pendingGenerationSceneId || 0);
            if (!sceneId) return;
            event.target.disabled = true;
            queueMutation(() => request(state.endpoints.generationStart || 'video_generation_start.php', { sceneId, version: currentProject().version }), 'Generation started')
                .finally(() => {
                    event.target.disabled = false;
                    dom.generationModal.hidden = true;
                    state.pendingGenerationSceneId = null;
                    updateGenerationPolling();
                });
        }
    });

    root.addEventListener('change', event => {
        if (event.target.matches('[data-music-file]')) {
            const file = event.target.files?.[0];
            event.target.value = '';
            uploadMusic(file);
            return;
        }
        const instruction = event.target.closest('[data-reference-instruction]');
        if (instruction) {
            queueMutation(() => api({
                action: 'reference_update',
                referenceId: Number(instruction.dataset.referenceInstruction),
                version: currentProject().version,
                instruction: String(instruction.value || '').trim(),
            }));
            return;
        }
        const referenceInput = event.target.closest('[data-reference-file-input]');
        if (referenceInput) {
            const sceneId = Number(referenceInput.dataset.sceneId);
            const role = String(referenceInput.dataset.role || 'start_frame');
            const files = Array.from(referenceInput.files || []);
            referenceInput.value = '';
            uploadReferenceFiles(sceneId, role, files).catch(() => undefined);
            return;
        }
        if (event.target === dom.projectTitle) {
            const project = currentProject();
            const title = String(event.target.value || '').trim();
            if (!project) return;
            if (!title) {
                event.target.value = String(project.title || '');
                toast('The project name cannot be empty.', true);
                return;
            }
            if (title === String(project.title || '')) return;
            queueMutation(() => api({ action: 'project_update', projectId: project.id, version: project.version, changes: { title } }), 'Nombre actualizado');
            return;
        }
        if (event.target === dom.artworkFilter) {
            state.artworkFilter = String(event.target.value || '');
            state.selectedAssetKey = null;
            renderCatalog();
            setupSortables();
            const selectedArtwork = artworkMap().get(state.artworkFilter);
            if (currentProject()) queueMutation(() => api({ action: 'project_update', projectId: currentProject().id, version: currentProject().version, changes: { artworkId: selectedArtwork?.canonicalArtworkId || null } }));
            return;
        }
        if (event.target === dom.seriesFilter) {
            state.seriesFilter = String(event.target.value || '');
            state.selectedAssetKey = null;
            renderCatalog();
            setupSortables();
            return;
        }
        const field = event.target.closest('[data-scene-field]');
        if (field) {
            const sceneId = Number(field.dataset.sceneId);
            const key = String(field.dataset.sceneField);
            const value = ['durationSeconds','transitionDurationSeconds'].includes(key) ? Number(field.value) : field.value;
            queueMutation(() => api({ action: 'scene_update', sceneId, version: currentProject().version, changes: { [key]: value } }));
        }
    });

    root.addEventListener('keydown', event => {
        if (event.target === dom.projectTitle) {
            if (event.key === 'Enter') {
                event.preventDefault();
                event.target.blur();
            } else if (event.key === 'Escape') {
                event.target.value = String(currentProject()?.title || '');
                event.target.blur();
            }
            return;
        }
        if (!['Enter', ' '].includes(event.key)) return;
        const card = event.target.closest('[data-catalog-card]');
        const frame = event.target.closest('[data-frame-drop],[data-reference-drop]');
        if (!card && !frame) return;
        event.preventDefault();
        if (card) {
            state.selectedAssetKey = String(card.dataset.assetKey || '');
            renderCatalog();
            setupSortables();
        } else if (state.selectedAssetKey) {
            assignReference(Number(frame.dataset.sceneId), String(frame.dataset.role), state.selectedAssetKey);
        } else if (!frame.classList.contains('has-media')) {
            frame.querySelector('[data-reference-file-input]')?.click();
        }
    });

    root.addEventListener('dragover', event => {
        const frame = event.target.closest('[data-frame-drop],[data-reference-drop]');
        if (!frame) return;
        const types = Array.from(event.dataTransfer?.types || []);
        const generatedVideo = types.includes('application/x-artwork-generated-video');
        const uploadedFiles = types.includes('Files');
        if (!generatedVideo && !uploadedFiles) return;
        if (generatedVideo && !['start_frame','source_video'].includes(String(frame.dataset.role))) return;
        event.preventDefault();
        event.dataTransfer.dropEffect = 'copy';
        frame.classList.add('is-drop-target');
    });
    root.addEventListener('dragleave', event => {
        const frame = event.target.closest('[data-frame-drop],[data-reference-drop]');
        if (frame && !frame.contains(event.relatedTarget)) frame.classList.remove('is-drop-target');
    });
    root.addEventListener('drop', event => {
        const frame = event.target.closest('[data-frame-drop],[data-reference-drop]');
        if (!frame) return;
        const assetKey = String(event.dataTransfer?.getData('application/x-artwork-generated-video') || '');
        if (assetKey) {
            event.preventDefault();
            frame.classList.remove('is-drop-target');
            root.classList.remove('is-dragging-generated');
            assignReference(Number(frame.dataset.sceneId), String(frame.dataset.role), assetKey);
            return;
        }
        const files = event.dataTransfer?.files;
        if (!files?.length) return;
        event.preventDefault();
        frame.classList.remove('is-drop-target');
        uploadReferenceFiles(Number(frame.dataset.sceneId), String(frame.dataset.role), files).catch(() => undefined);
    });
    root.addEventListener('dragstart', event => {
        const handle = event.target.closest('[data-generated-drag]');
        if (!handle || !event.dataTransfer) return;
        const assetKey = String(handle.dataset.assetKey || '');
        if (!assetKey) return;
        event.dataTransfer.effectAllowed = 'copy';
        event.dataTransfer.setData('application/x-artwork-generated-video', assetKey);
        event.dataTransfer.setData('text/plain', assetKey);
        root.classList.add('is-dragging-generated');
        handle.closest('[data-generated-clip]')?.classList.add('is-dragging');
    });
    root.addEventListener('dragend', event => {
        const handle = event.target.closest('[data-generated-drag]');
        if (!handle) return;
        root.classList.remove('is-dragging-generated');
        handle.closest('[data-generated-clip]')?.classList.remove('is-dragging');
        $$('[data-frame-drop].is-drop-target,[data-reference-drop].is-drop-target').forEach(frame => frame.classList.remove('is-drop-target'));
    });

    [dom.generationModal].forEach(modal => modal.addEventListener('click', event => {
        if (event.target !== modal) return;
        modal.hidden = true;
        if (modal === dom.generationModal) state.pendingGenerationSceneId = null;
    }));
    document.addEventListener('keydown', event => {
        if (event.key !== 'Escape') return;
        dom.generationModal.hidden = true;
        state.pendingGenerationSceneId = null;
    });
    document.addEventListener('visibilitychange', () => {
        updateGenerationPolling();
        updateExportPolling();
        if (!document.hidden) refreshLibrary();
    });
    window.addEventListener('focus', refreshLibrary);
    window.addEventListener('beforeunload', event => {
        if (!state.saving) return;
        event.preventDefault();
        event.returnValue = '';
    });

    renderAll();
    ensureMinimumSequences();
})();

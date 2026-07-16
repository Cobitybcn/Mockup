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
        artworkFilter: String(initial.studio?.project?.artworkId || ''),
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
    };

    const $ = (selector, context = root) => context.querySelector(selector);
    const $$ = (selector, context = root) => Array.from(context.querySelectorAll(selector));
    const dom = {
        editor: $('[data-editor]'),
        empty: $('[data-empty-project]'),
        projectPicker: $('[data-project-picker]'),
        projectTitle: $('[data-project-title]'),
        saveState: $('[data-save-state]'),
        artworkFilter: $('[data-artwork-filter]'),
        seriesFilter: $('[data-series-filter]'),
        catalogRail: $('[data-catalog-rail]'),
        catalogHelp: $('[data-catalog-help]'),
        boardGrid: $('[data-sequence-boards]'),
        projectModal: $('[data-project-modal]'),
        projectForm: $('[data-create-project-form]'),
        generationModal: $('[data-generation-modal]'),
        generationSummary: $('[data-generation-summary]'),
        toast: $('[data-video-toast]'),
    };

    const labels = {
        queued: 'En cola',
        submitting: 'Enviando',
        polling: 'Generando',
        processing: 'Generando',
        succeeded: 'Video listo',
        failed: 'Error',
        ready: 'Lista para generar',
        draft: 'Sin preparar',
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
        try { data = await response.json(); } catch (_) { data = { ok: false, error: `La solicitud falló (${response.status}).` }; }
        if (!response.ok || !data.ok) {
            const error = new Error(data.error || `La solicitud falló (${response.status}).`);
            error.status = response.status;
            throw error;
        }
        return data;
    }

    function api(payload) { return request(state.endpoints.api || 'video_api.php', payload); }

    function queueMutation(work, successMessage = '') {
        const operation = state.mutation.catch(() => undefined).then(async () => {
            state.saving = true;
            setSaveState('Guardando…', 'saving');
            try {
                const result = await work();
                if (result?.project && result?.scenes) applyStudio(result);
                setSaveState('Guardado');
                if (successMessage) toast(successMessage);
                return result;
            } catch (error) {
                setSaveState('Error al guardar', 'error');
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
            state.artworkFilter = String(payload.project.artworkId || '');
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

    function artworkMap() {
        const values = new Map();
        [...(state.assets.rootArtworks || []), ...(state.assets.mockups || [])].forEach(asset => {
            const id = Number(asset.artworkId || 0);
            if (id <= 0) return;
            const title = String(asset.artworkTitle || '').trim() || `Artwork #${id}`;
            if (!values.has(id) || values.get(id).startsWith('Artwork #')) values.set(id, title);
        });
        return new Map([...values.entries()].sort((left, right) => left[1].localeCompare(right[1], 'es', { sensitivity: 'base' })));
    }

    function seriesMap() {
        const values = new Map();
        (state.assets.mockups || []).forEach(asset => {
            const id = Number(asset.seriesId || 0);
            const title = String(asset.seriesTitle || '').trim();
            if (id > 0) values.set(id, title || `Serie #${id}`);
        });
        return new Map([...values.entries()].sort((left, right) => left[1].localeCompare(right[1], 'es', { sensitivity: 'base' })));
    }

    function renderProjectControls() {
        const project = currentProject();
        dom.empty.hidden = Boolean(project);
        dom.editor.hidden = !project;
        dom.projectPicker.innerHTML = state.projects.length
            ? state.projects.map(item => `<option value="${item.id}"${Number(item.id) === Number(project?.id) ? ' selected' : ''}>${escapeHtml(item.title)}</option>`).join('')
            : '<option value="">Sin proyectos</option>';
        if (dom.projectTitle && document.activeElement !== dom.projectTitle) {
            dom.projectTitle.value = String(project?.title || '');
        }

        const artworks = artworkMap();
        dom.artworkFilter.innerHTML = '<option value="">Filtrar por obra</option>' + [...artworks.entries()].map(([id, title]) =>
            `<option value="${id}"${String(id) === state.artworkFilter ? ' selected' : ''}>${escapeHtml(title)}</option>`
        ).join('');
        const artworkSelect = dom.projectForm.elements.artworkId;
        artworkSelect.innerHTML = '<option value="">Sin obra por ahora</option>' + [...artworks.entries()].map(([id, title]) =>
            `<option value="${id}">${escapeHtml(title)}</option>`
        ).join('');

        const series = seriesMap();
        dom.seriesFilter.innerHTML = '<option value="">Filtrar por serie</option><option value="none">Sin serie</option>' + [...series.entries()].map(([id, title]) =>
            `<option value="${id}"${String(id) === state.seriesFilter ? ' selected' : ''}>${escapeHtml(title)}</option>`
        ).join('');
    }

    function visibleReferenceAssets() {
        return referenceAssets()
            .filter(asset => asset.type === 'reference_asset' || !state.artworkFilter || Number(asset.artworkId) === Number(state.artworkFilter))
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
                ${asset.type === 'mockup' ? `<button class="vds-favorite" type="button" data-toggle-favorite aria-pressed="${asset.favorite ? 'true' : 'false'}" aria-label="${asset.favorite ? 'Quitar de favoritos' : 'Agregar a favoritos'}">${asset.favorite ? '♥' : '♡'}</button>` : ''}
                <div class="vds-catalog-card-copy"><strong>${escapeHtml(asset.contextTitle || asset.label)}</strong><span>${escapeHtml(asset.type === 'reference_asset' ? 'Desde tu ordenador' : (asset.mediaType === 'video' ? (asset.projectTitle || 'Video generado') : (asset.artworkTitle || 'Imagen de referencia')))}</span></div>
            </article>`).join('') : '<div class="vds-catalog-empty">No hay referencias para esta selección.</div>';
        dom.catalogHelp.textContent = state.selectedAssetKey
            ? 'Referencia seleccionada. Haz clic en el destino donde quieras utilizarla, o arrástrala.'
            : 'Arrastra imágenes a sus referencias y videos a Video base. También puedes cargar desde tu ordenador.';
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
                ? `<video src="${escapeHtml(reference.previewUrl)}" muted preload="metadata" playsinline></video><span class="vds-frame-play" aria-hidden="true">▶</span>`
                : `<img src="${escapeHtml(reference.thumbnailUrl || reference.previewUrl)}" alt="${escapeHtml(reference.label)}">`)
                + `<button class="vds-remove-frame" type="button" data-remove-reference="${reference.id}" aria-label="Quitar ${escapeHtml(label)}">×</button><span class="vds-frame-caption">${escapeHtml(reference.label)}</span>`
            : `<div class="vds-frame-placeholder"><span class="vds-frame-plus">${uploading ? '◌' : '＋'}</span><strong>${uploading ? 'Subiendo archivo…' : 'Arrastra aquí'}</strong><button class="vds-frame-upload-button" type="button" data-upload-reference="${scene.id}" data-role="${role}"${uploading ? ' disabled' : ''}>Desde ordenador</button><span>o selecciona una referencia del catálogo</span></div>`;
        return `<div class="vds-frame-column">
            <span class="vds-frame-label">${escapeHtml(label)}</span>
            <div class="vds-frame-slot${reference ? ' has-media' : ''}${uploading ? ' is-uploading' : ''}" data-frame-drop data-scene-id="${scene.id}" data-role="${role}" tabindex="0">
                ${media}
                <input type="file" data-reference-file-input data-scene-id="${scene.id}" data-role="${role}" accept="image/jpeg,image/png,image/webp,image/gif" hidden>
            </div>
        </div>`;
    }

    function referenceInstruction(reference, label) {
        if (!reference) return '';
        const instruction = String(reference.metadata?.instruction || '');
        return `<label class="vds-reference-instruction"><span>${escapeHtml(label)}</span><input type="text" data-reference-instruction="${reference.id}" value="${escapeHtml(instruction)}" placeholder="Cómo debe utilizar Omni esta imagen"></label>`;
    }

    function compactReferenceSlot(scene, role, label, description) {
        const reference = referenceFor(scene, role);
        const uploading = state.uploadingSlots.has(uploadSlotKey(scene.id, role));
        const body = reference
            ? `<div class="vds-compact-reference-media"><img src="${escapeHtml(reference.thumbnailUrl || reference.previewUrl)}" alt="${escapeHtml(reference.label)}"><button type="button" data-remove-reference="${reference.id}" aria-label="Quitar ${escapeHtml(label)}">×</button></div>${referenceInstruction(reference, 'Propósito')}`
            : `<div class="vds-compact-reference-empty"><span>${uploading ? '◌' : '＋'}</span><strong>${uploading ? 'Subiendo…' : 'Añadir imagen'}</strong><small>${escapeHtml(description)}</small></div>`;
        return `<div class="vds-priority-reference${reference ? ' has-media' : ''}" data-reference-drop data-scene-id="${scene.id}" data-role="${role}" tabindex="0">
            <header><strong>${escapeHtml(label)}</strong></header>
            ${body}
            <input type="file" data-reference-file-input data-scene-id="${scene.id}" data-role="${role}" accept="image/jpeg,image/png,image/webp,image/gif" hidden>
        </div>`;
    }

    function additionalReferences(scene) {
        const excluded = ['start_frame','end_frame','artwork_fidelity','character_identity','wardrobe_identity','source_video'];
        return (scene.references || []).filter(reference => reference.mediaType === 'image' && !excluded.includes(String(reference.role)));
    }

    function additionalReferenceCard(reference) {
        return `<article class="vds-additional-reference">
            <div><img src="${escapeHtml(reference.thumbnailUrl || reference.previewUrl)}" alt="${escapeHtml(reference.label)}"><button type="button" data-remove-reference="${reference.id}" aria-label="Quitar referencia">×</button></div>
            ${referenceInstruction(reference, 'Propósito')}
        </article>`;
    }

    function referenceManager(scene, index) {
        const usedImages = omniImageUsage(scene, index);
        const maxImages = Number(state.capabilities.referenceLimits?.images || 10);
        const available = Math.max(0, maxImages - usedImages);
        const extras = additionalReferences(scene);
        const sourceVideo = sourceVideoReference(scene);
        const uploadingReferences = state.uploadingSlots.has(uploadSlotKey(scene.id, 'reference'));
        const uploadingVideo = state.uploadingSlots.has(uploadSlotKey(scene.id, 'source_video'));
        const framePurpose = [
            referenceFor(scene, 'start_frame')?.mediaType === 'image' ? referenceInstruction(referenceFor(scene, 'start_frame'), 'Imagen inicial') : '',
            referenceFor(scene, 'end_frame')?.mediaType === 'image' ? referenceInstruction(referenceFor(scene, 'end_frame'), 'Imagen final objetivo') : '',
        ].filter(Boolean).join('');

        return `<section class="vds-reference-manager">
            <div class="vds-reference-section-head"><div><strong>Referencias visuales</strong><small>Prioridad: obra, personaje y vestuario.</small></div><span>${usedImages}/${maxImages}</span></div>
            ${framePurpose ? `<div class="vds-frame-purpose-list">${framePurpose}</div>` : ''}
            <div class="vds-priority-grid">
                ${compactReferenceSlot(scene, 'artwork_fidelity', '1 · Obra de arte', 'Identidad y fidelidad de la obra')}
                ${compactReferenceSlot(scene, 'character_identity', '2 · Personaje', 'Rostro, cuerpo y rasgos')}
                ${compactReferenceSlot(scene, 'wardrobe_identity', '3 · Vestuario', 'Prendas, colores y accesorios')}
            </div>
            <div class="vds-additional-drop${available <= 0 ? ' is-full' : ''}" data-reference-drop data-scene-id="${scene.id}" data-role="reference" tabindex="0">
                <div><span>${uploadingReferences ? '◌' : '＋'}</span><strong>${available > 0 ? `Añadir referencias adicionales · ${available} disponibles` : 'Límite de imágenes alcanzado'}</strong><small>Arrastra varias imágenes o selecciónalas desde tu ordenador.</small></div>
                <button class="vds-frame-upload-button" type="button" data-upload-reference="${scene.id}" data-role="reference"${available <= 0 || uploadingReferences ? ' disabled' : ''}>Desde ordenador</button>
                <input type="file" data-reference-file-input data-scene-id="${scene.id}" data-role="reference" accept="image/jpeg,image/png,image/webp,image/gif" multiple hidden>
            </div>
            ${extras.length ? `<div class="vds-additional-reference-list">${extras.map(additionalReferenceCard).join('')}</div>` : ''}
        </section>
        <section class="vds-video-reference-section">
            <div class="vds-reference-section-head"><div><strong>Video base para editar</strong><small>Un video de hasta 10 segundos. No se mezcla con las imágenes.</small></div><span>${sourceVideo ? '1/1' : '0/1'}</span></div>
            <div class="vds-source-video${sourceVideo ? ' has-media' : ''}" data-reference-drop data-scene-id="${scene.id}" data-role="source_video" tabindex="0">
                ${sourceVideo
                    ? `<video src="${escapeHtml(sourceVideo.previewUrl)}" muted controls preload="metadata" playsinline></video><div><strong>${escapeHtml(sourceVideo.label)}</strong><small>Omni editará este video; no lo extenderá.</small></div><button type="button" data-remove-reference="${sourceVideo.id}" aria-label="Quitar video base">×</button>`
                    : `<div><span>${uploadingVideo ? '◌' : '▶'}</span><strong>${uploadingVideo ? 'Subiendo video…' : 'Añadir video base'}</strong><small>MP4, MOV o WebM · máximo 10 segundos</small></div><button class="vds-frame-upload-button" type="button" data-upload-reference="${scene.id}" data-role="source_video"${uploadingVideo ? ' disabled' : ''}>Desde ordenador</button>`}
                <input type="file" data-reference-file-input data-scene-id="${scene.id}" data-role="source_video" accept="video/mp4,video/quicktime,video/webm" hidden>
            </div>
        </section>`;
    }

    function resultPreview(scene, index) {
        const result = scene.active_generation;
        if (result?.previewUrl) {
            const nextScene = scenes()[index + 1] || null;
            const assetKey = `generation_job:${Number(result.id)}`;
            const nextAction = nextScene
                ? `<button class="vds-use-next" type="button" data-use-clip-next="${nextScene.id}" data-asset-key="${escapeHtml(assetKey)}">Usar al inicio de Secuencia ${index + 2}</button>`
                : '';
            return `<div class="vds-generated-clip" data-generated-clip data-asset-key="${escapeHtml(assetKey)}" data-asset-type="generation_job" data-media-type="video">
                <video class="vds-result-video" src="${escapeHtml(result.previewUrl)}"${result.thumbnailUrl ? ` poster="${escapeHtml(result.thumbnailUrl)}"` : ''} controls preload="metadata" playsinline></video>
                <div class="vds-generated-continuation">
                    <button class="vds-generated-drag" type="button" draggable="true" data-generated-drag data-asset-key="${escapeHtml(assetKey)}" aria-label="Arrastrar este resultado al inicio de otra secuencia">
                        <span class="vds-generated-grip" aria-hidden="true">⋮⋮</span>
                        <span><strong>Arrastra para continuar</strong><small>Su último fotograma será la imagen inicial de otra secuencia.</small></span>
                    </button>
                    ${nextAction}
                </div>
                <details class="vds-adjust-result" data-adjust-panel="${scene.id}">
                    <summary>Ajustar este resultado con Omni</summary>
                    <div><textarea data-adjust-prompt="${scene.id}" placeholder="Describe solo el cambio. Omni conservará lo demás."></textarea><button type="button" data-adjust-result="${scene.id}">Generar ajuste</button></div>
                </details>
            </div>`;
        }
        const pending = ['queued','submitting','polling','processing'].includes(String(scene.generation?.status || ''));
        return `<div class="vds-result-placeholder"><span aria-hidden="true">${pending ? '◌' : '▶'}</span><strong>${pending ? 'Generando resultado' : 'Sin resultado generado'}</strong><small>${pending ? 'La vista previa aparecerá al finalizar.' : 'Genera esta secuencia para verla aquí.'}</small></div>`;
    }

    function generationState(scene, previousScene = null) {
        const jobStatus = String(scene.generation?.status || '');
        if (jobStatus) return { id: jobStatus, label: labels[jobStatus] || jobStatus };
        if (String(scene.prompt || '').trim() || scene.references?.length || previousScene?.active_generation) return { id: 'ready', label: labels.ready };
        return { id: 'draft', label: labels.draft };
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
                ? `<a class="vds-download-clip" href="${escapeHtml(scene.active_generation.previewUrl)}&download=1">Descargar MP4</a>` : '';
            const resultActions = scene.active_generation?.previewUrl
                ? `<button class="vds-secondary" type="button" data-open-adjust-result="${scene.id}"${pending ? ' disabled' : ''}>Editar resultado</button>`
                : '';
            const generateLabel = scene.active_generation?.previewUrl
                ? 'Regenerar'
                : (baseVideo ? 'Editar video' : 'Generar');
            const generationError = status.id === 'failed' && String(scene.generation?.error || '').trim()
                ? `<p class="vds-generation-error" role="alert"><strong>No se pudo generar el video.</strong><span>${escapeHtml(String(scene.generation.error).trim())}</span></p>`
                : '';
            const continuityText = baseVideo
                ? `Omni editará ${baseVideo.label}; las imágenes se utilizarán como referencias visuales.`
                : index === 0
                ? (chosenContinuation
                    ? `Continuación elegida desde ${chosenContinuation.label}; se usará su último fotograma.`
                    : 'Añade una imagen o un video desde el catálogo o desde tu ordenador.')
                : chosenContinuation
                    ? `Continuación elegida desde ${chosenContinuation.label}; reemplaza la continuidad automática.`
                : previousReady
                    ? `Continuidad automática desde el último fotograma de la Secuencia ${index}.`
                    : `Genera primero la Secuencia ${index} para enlazar la continuidad.`;
            return `<article class="vds-sequence-board" data-sequence-board data-scene-id="${scene.id}" data-accent="${(index % 4) + 1}">
                <header class="vds-board-head">
                    <div class="vds-board-title"><span class="vds-sequence-number">${index + 1}</span><h3>Secuencia ${index + 1}</h3></div>
                    <div class="vds-board-actions">
                        <button class="vds-board-drag" type="button" aria-label="Reordenar secuencia">⋮⋮</button>
                        <button class="vds-board-menu" type="button" data-duplicate-sequence="${scene.id}" aria-label="Duplicar secuencia">⧉</button>
                        <button class="vds-board-menu" type="button" data-delete-sequence="${scene.id}" aria-label="Eliminar secuencia">×</button>
                    </div>
                </header>
                <p class="vds-board-subtitle">${escapeHtml(continuityText)}</p>
                <div class="vds-frame-flow">${frameSlot(scene, 'start_frame', 'Imagen inicial')}<span class="vds-frame-arrow" aria-hidden="true">→</span>${frameSlot(scene, 'end_frame', 'Imagen final objetivo')}</div>
                <button class="vds-context-toggle" type="button" data-toggle-context="${scene.id}" aria-expanded="${expanded ? 'true' : 'false'}"><span>＋ Prompt, referencias y duración · ${usedImages}/${maxImages}</span><span>${expanded ? '−' : '+'}</span></button>
                <div class="vds-context-panel" data-context-panel${expanded ? '' : ' hidden'}>
                    <label><span>Prompt</span><textarea data-scene-field="prompt" data-scene-id="${scene.id}" placeholder="Describe aquí cámara, movimiento, ritmo, luz, ambiente, acción y transición.">${escapeHtml(scene.prompt || '')}</textarea></label>
                    ${referenceManager(scene, index)}
                    <div class="vds-context-grid">
                        <label><span>${baseVideo ? 'Duración · la conserva el video base' : 'Duración'}</span><select data-scene-field="durationSeconds" data-scene-id="${scene.id}"${baseVideo ? ' disabled title="Omni conserva la duración real del video base al editarlo."' : ''}>${durationValues.map(value => `<option value="${value}"${Number(scene.durationSeconds) === Number(value) ? ' selected' : ''}>${value} segundos</option>`).join('')}</select></label>
                    </div>
                </div>
                ${scene.active_generation?.previewUrl ? `<div class="vds-inline-result"><span>Resultado generado</span>${resultPreview(scene, index)}</div>` : ''}
                <footer class="vds-board-footer">
                    <span class="vds-generation-state is-${escapeHtml(status.id)}">${escapeHtml(status.label)}</span>
                    <div class="vds-board-footer-actions">${download}${resultActions}<button type="button" data-generate-sequence="${scene.id}"${pending ? ' disabled' : ''}>${generateLabel}</button></div>
                </footer>
                ${generationError}
            </article>`;
        }).join('');
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
        updateGenerationPolling();
    }

    function assignReference(sceneId, role, assetKey) {
        const asset = assetByKey(assetKey);
        const scene = sceneById(sceneId);
        if (!asset || !scene) return;
        if (asset.mediaType === 'video') {
            if (role === 'start_frame' && asset.type !== 'generation_job') {
                return toast('Los videos subidos deben colocarse en Video base para editar.', true);
            }
            if (!['start_frame','source_video'].includes(role)) {
                return toast('Los videos deben colocarse en Video base para editar.', true);
            }
        } else if (role === 'source_video') {
            return toast('Video base admite únicamente un video.', true);
        }
        if (asset.type === 'generation_job' && Number(asset.sceneId) === Number(sceneId)) {
            return toast('El resultado debe continuar en otra secuencia, no en la misma.', true);
        }
        const alreadyAttached = (scene.references || []).some(reference => reference.role === role && reference.sourceType === asset.type && Number(reference.sourceId) === Number(asset.id));
        if (alreadyAttached) return toast('Esta referencia ya está añadida en este bloque.');
        if (asset.mediaType === 'image') {
            const sceneIndex = scenes().findIndex(item => Number(item.id) === Number(sceneId));
            if (availableImagesForRole(scene, sceneIndex, role) < 1) {
                return toast('Omni admite un máximo de 10 imágenes por secuencia.', true);
            }
        }
        const targetIndex = scenes().findIndex(item => Number(item.id) === Number(sceneId)) + 1;
        const sourceIndex = scenes().findIndex(item => Number(item.id) === Number(asset.sceneId)) + 1;
        const message = asset.type === 'generation_job'
            ? `Secuencia ${targetIndex} enlazada${sourceIndex > 0 ? ` al resultado de Secuencia ${sourceIndex}` : ' al video elegido'}`
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
        if (!multiRole && files.length > 1) return toast('Esta referencia admite un solo archivo.', true);
        if (role === 'source_video') {
            if (files.some(file => !String(file.type || '').startsWith('video/'))) return toast('Video base admite únicamente un video.', true);
        } else {
            if (files.some(file => !String(file.type || '').startsWith('image/'))) return toast('Las referencias visuales admiten únicamente imágenes.', true);
            const sceneIndex = scenes().findIndex(item => Number(item.id) === Number(sceneId));
            if (files.length > availableImagesForRole(scene, sceneIndex, role)) {
                return toast('La selección supera el máximo de 10 imágenes de Omni.', true);
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
                catch (_) { result = { ok: false, error: `La carga falló (${response.status}).` }; }
                if (!response.ok || !result.ok) {
                    const error = new Error(result.error || `La carga falló (${response.status}).`);
                    error.status = response.status;
                    throw error;
                }
                return result;
            }, `${referenceRoleLabel(role)} actualizado desde el ordenador`);
        } finally {
            state.uploadingSlots.delete(slotKey);
            renderAll();
        }
    }

    function referenceRoleLabel(role) {
        return ({
            start_frame: 'Imagen inicial',
            end_frame: 'Imagen final objetivo',
            artwork_fidelity: 'Obra de arte',
            character_identity: 'Personaje',
            wardrobe_identity: 'Vestuario',
            source_video: 'Video base',
            reference: 'Referencias adicionales',
        })[String(role)] || 'Referencia';
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
        }), `Secuencia ${number} agregada`);
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
            ? `Editar video: ${baseVideo.label}`
            : startReference
            ? (startReference.mediaType === 'video' ? `Último fotograma de ${startReference.label}` : `Start Frame: ${startReference.label}`)
            : (index === 0 ? 'Primera secuencia' : (previousScene?.active_generation ? `Automática desde Secuencia ${index}` : 'Aún sin resultado anterior'));
        const referenceCount = Array.isArray(scene.references) ? scene.references.length : 0;
        state.pendingGenerationSceneId = scene.id;
        dom.generationSummary.innerHTML = `<div class="vds-generation-facts">
            <div><span>Secuencia</span><strong>${index + 1}</strong></div>
            <div><span>Referencias adjuntas</span><strong>${referenceCount}</strong></div>
            <div><span>Continuidad</span><strong>${escapeHtml(continuityLabel)}</strong></div>
            <div><span>Duración</span><strong>${baseVideo ? 'La conserva el video base' : `${Number(scene.durationSeconds)} segundos`}</strong></div>
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
            if (!response.ok || !result.ok) throw new Error(result.error || 'No se pudo actualizar el favorito.');
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

    async function pollGeneration() {
        state.generationTimer = null;
        if (!currentProject() || document.hidden) return updateGenerationPolling();
        try {
            const result = await request(state.endpoints.generationStatus || 'video_generation_status.php', { projectId: currentProject().id });
            if (result.project && result.scenes) applyStudio(result);
        } catch (_) { /* keep the board usable during a transient polling failure */ }
        updateGenerationPolling();
    }

    root.addEventListener('click', event => {
        const favorite = event.target.closest('[data-toggle-favorite]');
        if (favorite) {
            event.stopPropagation();
            const card = favorite.closest('[data-catalog-card]');
            if (card) toggleFavorite(String(card.dataset.assetKey || ''), favorite);
            return;
        }

        const newProject = event.target.closest('[data-new-project]');
        if (newProject) {
            dom.projectForm.reset();
            dom.projectModal.hidden = false;
            dom.projectForm.elements.title.focus();
            return;
        }
        if (event.target.closest('[data-save-project]')) {
            dom.projectTitle?.blur();
            state.mutation.then(() => {
                setSaveState('Guardado');
                toast('Proyecto guardado');
            });
            return;
        }
        if (event.target.closest('[data-delete-project]')) {
            const project = currentProject();
            if (!project) return;
            const clipCount = Number(project.generatedClipCount || 0);
            const detail = clipCount > 0
                ? ` Sus ${clipCount} video${clipCount === 1 ? '' : 's'} generado${clipCount === 1 ? '' : 's'} permanecerán en Videos.`
                : '';
            if (!window.confirm(`¿Eliminar “${project.title}” del espacio de trabajo?${detail}`)) return;
            queueMutation(() => api({ action: 'project_delete', projectId: project.id, version: project.version }))
                .then(async result => {
                    state.projects = Array.isArray(result.projects) ? result.projects : [];
                    const nextProject = state.projects[0] || null;
                    if (nextProject) {
                        const next = await api({ action: 'project_read', projectId: nextProject.id });
                        applyStudio(next, true);
                        window.history.replaceState({}, '', `video.php?project=${nextProject.id}`);
                    } else {
                        state.studio = null;
                        root.dataset.projectId = '';
                        renderAll();
                        window.history.replaceState({}, '', 'video.php');
                    }
                    toast('Proyecto eliminado del espacio de trabajo');
                });
            return;
        }
        if (event.target.closest('[data-close-project-modal]')) { dom.projectModal.hidden = true; return; }
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
            queueMutation(() => api({ action: 'reference_remove', referenceId: Number(remove.dataset.removeReference), version: currentProject().version }), 'Referencia eliminada');
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
            queueMutation(() => api({ action: 'scene_duplicate', sceneId: id, version: currentProject().version }), 'Secuencia duplicada');
            return;
        }

        const removeScene = event.target.closest('[data-delete-sequence]');
        if (removeScene) {
            const id = Number(removeScene.dataset.deleteSequence);
            const index = scenes().findIndex(scene => Number(scene.id) === id) + 1;
            if (scenes().length <= 1) return toast('El proyecto debe conservar al menos una secuencia.', true);
            if (window.confirm(`¿Eliminar la Secuencia ${index}?`)) {
                state.openContexts.delete(id);
                queueMutation(() => api({ action: 'scene_delete', sceneId: id, version: currentProject().version }), 'Secuencia eliminada');
            }
            return;
        }

        const useClipNext = event.target.closest('[data-use-clip-next]');
        if (useClipNext) {
            assignReference(Number(useClipNext.dataset.useClipNext), 'start_frame', String(useClipNext.dataset.assetKey || ''));
            return;
        }

        const adjustResult = event.target.closest('[data-adjust-result]');
        if (adjustResult) {
            const sceneId = Number(adjustResult.dataset.adjustResult);
            const prompt = String($(`[data-adjust-prompt="${sceneId}"]`)?.value || '').trim();
            if (!prompt) return toast('Describe el ajuste que quieres aplicar.', true);
            adjustResult.disabled = true;
            queueMutation(() => request(state.endpoints.generationStart || 'video_generation_start.php', {
                sceneId,
                version: currentProject().version,
                intent: 'adjust',
                adjustPrompt: prompt,
            }), 'Ajuste iniciado').finally(() => {
                adjustResult.disabled = false;
                updateGenerationPolling();
            });
            return;
        }

        const openAdjustResult = event.target.closest('[data-open-adjust-result]');
        if (openAdjustResult) {
            const sceneId = Number(openAdjustResult.dataset.openAdjustResult);
            const panel = $(`[data-adjust-panel="${sceneId}"]`);
            if (!panel) return;
            panel.open = true;
            panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            requestAnimationFrame(() => $(`[data-adjust-prompt="${sceneId}"]`)?.focus());
            return;
        }

        const generate = event.target.closest('[data-generate-sequence]');
        if (generate) { showGenerationModal(Number(generate.dataset.generateSequence)); return; }
        if (event.target.closest('[data-cancel-generation]')) { dom.generationModal.hidden = true; state.pendingGenerationSceneId = null; return; }
        if (event.target.closest('[data-confirm-generation]')) {
            const sceneId = Number(state.pendingGenerationSceneId || 0);
            if (!sceneId) return;
            event.target.disabled = true;
            queueMutation(() => request(state.endpoints.generationStart || 'video_generation_start.php', { sceneId, version: currentProject().version }), 'Generación iniciada')
                .finally(() => {
                    event.target.disabled = false;
                    dom.generationModal.hidden = true;
                    state.pendingGenerationSceneId = null;
                    updateGenerationPolling();
                });
        }
    });

    root.addEventListener('change', event => {
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
                toast('El nombre del video no puede quedar vacío.', true);
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
            if (currentProject()) queueMutation(() => api({ action: 'project_update', projectId: currentProject().id, version: currentProject().version, changes: { artworkId: state.artworkFilter || null } }));
            return;
        }
        if (event.target === dom.seriesFilter) {
            state.seriesFilter = String(event.target.value || '');
            state.selectedAssetKey = null;
            renderCatalog();
            setupSortables();
            return;
        }
        if (event.target === dom.projectPicker) {
            const projectId = Number(event.target.value || 0);
            if (!projectId || projectId === Number(currentProject()?.id)) return;
            setSaveState('Cargando…', 'saving');
            api({ action: 'project_read', projectId }).then(result => {
                applyStudio(result, true);
                window.history.replaceState({}, '', `video.php?project=${projectId}`);
                setSaveState('Guardado');
                ensureMinimumSequences();
            }).catch(error => { setSaveState('Error', 'error'); toast(error.message, true); });
            return;
        }
        const field = event.target.closest('[data-scene-field]');
        if (field) {
            const sceneId = Number(field.dataset.sceneId);
            const key = String(field.dataset.sceneField);
            const value = key === 'durationSeconds' ? Number(field.value) : field.value;
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

    dom.projectForm.addEventListener('submit', event => {
        event.preventDefault();
        const fields = dom.projectForm.elements;
        const artworkId = Number(fields.artworkId.value || 0);
        fields.artworkId.disabled = true;
        queueMutation(() => api({
            action: 'project_create',
            project: {
                title: String(fields.title.value || '').trim(),
                artworkId: artworkId || null,
                aspectRatio: fields.aspectRatio.value,
                targetDurationSeconds: 24,
                projectType: 'social_clip',
            },
        }), 'Proyecto creado').then(result => {
            applyStudio(result, true);
            dom.projectModal.hidden = true;
            window.history.replaceState({}, '', `video.php?project=${result.project.id}`);
            dom.projectForm.reset();
        }).finally(() => { fields.artworkId.disabled = false; });
    });

    [dom.projectModal, dom.generationModal].forEach(modal => modal.addEventListener('click', event => {
        if (event.target !== modal) return;
        modal.hidden = true;
        if (modal === dom.generationModal) state.pendingGenerationSceneId = null;
    }));
    document.addEventListener('keydown', event => {
        if (event.key !== 'Escape') return;
        dom.projectModal.hidden = true;
        dom.generationModal.hidden = true;
        state.pendingGenerationSceneId = null;
    });
    document.addEventListener('visibilitychange', updateGenerationPolling);
    window.addEventListener('beforeunload', event => {
        if (!state.saving) return;
        event.preventDefault();
        event.returnValue = '';
    });

    renderAll();
    ensureMinimumSequences();
})();

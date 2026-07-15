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
        assets: initial.assets || { mockups: [], rootArtworks: [] },
        capabilities: initial.capabilities || {},
        endpoints: initial.endpoints || {},
        artworkFilter: String(initial.studio?.project?.artworkId || ''),
        seriesFilter: '',
        selectedAssetId: null,
        openContexts: new Set(),
        pendingGenerationSceneId: null,
        mutation: Promise.resolve(),
        saving: false,
        seeding: false,
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
        static: 'Cámara estática',
        slow_push_in: 'Acercamiento lento',
        slow_pull_back: 'Alejamiento lento',
        pan_left: 'Paneo a la izquierda',
        pan_right: 'Paneo a la derecha',
        tilt_up: 'Inclinación hacia arriba',
        tilt_down: 'Inclinación hacia abajo',
        orbit_left: 'Órbita a la izquierda',
        orbit_right: 'Órbita a la derecha',
        handheld_subtle: 'Cámara en mano sutil',
        custom: 'Movimiento personalizado',
        very_low: 'Muy baja',
        low: 'Baja',
        medium: 'Media',
        high: 'Alta',
        queued: 'En cola',
        submitting: 'Enviando',
        polling: 'Generando',
        processing: 'Generando',
        succeeded: 'Video listo',
        failed: 'Error',
        ready: 'Lista para generar',
        incomplete: 'Faltan fotogramas',
        draft: 'Sin preparar',
    };

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>'"]/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' })[char]);
    }

    function currentProject() { return state.studio?.project || null; }
    function scenes() { return Array.isArray(state.studio?.scenes) ? state.studio.scenes : []; }
    function sceneById(id) { return scenes().find(scene => Number(scene.id) === Number(id)) || null; }
    function mockupById(id) { return (state.assets.mockups || []).find(asset => Number(asset.id) === Number(id)) || null; }
    function referenceFor(scene, role) { return scene?.references?.find(reference => reference.role === role) || null; }

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
            state.selectedAssetId = null;
            state.openContexts.clear();
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

    function visibleMockups() {
        return [...(state.assets.mockups || [])]
            .filter(asset => !state.artworkFilter || Number(asset.artworkId) === Number(state.artworkFilter))
            .filter(asset => !state.seriesFilter
                || (state.seriesFilter === 'none' ? Number(asset.seriesId || 0) === 0 : Number(asset.seriesId) === Number(state.seriesFilter)))
            .sort((left, right) => {
                if (Boolean(left.favorite) !== Boolean(right.favorite)) return left.favorite ? -1 : 1;
                if (left.favorite && right.favorite) return Number(left.favoriteRank || 0) - Number(right.favoriteRank || 0);
                return String(right.createdAt || '').localeCompare(String(left.createdAt || '')) || Number(right.id) - Number(left.id);
            });
    }

    function renderCatalog() {
        const assets = visibleMockups();
        dom.catalogRail.innerHTML = assets.length ? assets.map(asset => `
            <article class="vds-catalog-card${asset.favorite ? ' is-favorite' : ''}${Number(asset.id) === Number(state.selectedAssetId) ? ' is-selected' : ''}"
                data-catalog-card data-asset-id="${asset.id}" data-asset-type="mockup" tabindex="0" aria-label="${escapeHtml(asset.label)}">
                <img src="${escapeHtml(asset.thumbnailUrl || asset.previewUrl)}" alt="${escapeHtml(asset.artworkTitle)}" loading="lazy" draggable="false">
                <button class="vds-favorite" type="button" data-toggle-favorite aria-pressed="${asset.favorite ? 'true' : 'false'}" aria-label="${asset.favorite ? 'Quitar de favoritos' : 'Agregar a favoritos'}">${asset.favorite ? '♥' : '♡'}</button>
                <div class="vds-catalog-card-copy"><strong>${escapeHtml(asset.contextTitle || asset.label)}</strong><span>${escapeHtml(asset.artworkTitle)}</span></div>
            </article>`).join('') : '<div class="vds-catalog-empty">No hay mockups para esta selección.</div>';
        dom.catalogHelp.textContent = state.selectedAssetId
            ? 'Mockup seleccionado. Haz clic en un Start Frame o End Frame para colocarlo, o arrástralo.'
            : 'Arrastra un mockup hacia el inicio o el final de una secuencia.';
    }

    function optionMarkup(values, selected) {
        return (values || []).map(value => `<option value="${escapeHtml(value)}"${String(value) === String(selected) ? ' selected' : ''}>${escapeHtml(labels[value] || String(value).replaceAll('_', ' '))}</option>`).join('');
    }

    function frameSlot(scene, role, label) {
        const reference = referenceFor(scene, role);
        const media = reference ? `
            <img src="${escapeHtml(reference.thumbnailUrl || reference.previewUrl)}" alt="${escapeHtml(reference.label)}">
            <button class="vds-remove-frame" type="button" data-remove-reference="${reference.id}" aria-label="Quitar ${escapeHtml(label)}">×</button>
            <span class="vds-frame-caption">${escapeHtml(reference.label)}</span>` : `
            <div class="vds-frame-placeholder"><span class="vds-frame-plus">＋</span><strong>Arrastra aquí</strong><span>o selecciona un mockup del catálogo</span></div>`;
        return `<div class="vds-frame-column">
            <span class="vds-frame-label">${escapeHtml(label)}</span>
            <div class="vds-frame-slot${reference ? ' has-media' : ''}" data-frame-drop data-scene-id="${scene.id}" data-role="${role}" tabindex="0">${media}</div>
        </div>`;
    }

    function generationState(scene) {
        const jobStatus = String(scene.generation?.status || '');
        if (jobStatus) return { id: jobStatus, label: labels[jobStatus] || jobStatus };
        const start = referenceFor(scene, 'start_frame');
        const end = referenceFor(scene, 'end_frame');
        if (start && end) return { id: 'ready', label: labels.ready };
        if (start || end) return { id: 'incomplete', label: labels.incomplete };
        return { id: 'draft', label: labels.draft };
    }

    function renderBoards() {
        const cameraValues = state.capabilities.cameraMovements || ['static','slow_push_in','slow_pull_back','pan_left','pan_right'];
        const intensityValues = state.capabilities.motionIntensities || ['very_low','low','medium','high'];
        dom.boardGrid.innerHTML = scenes().map((scene, index) => {
            const start = referenceFor(scene, 'start_frame');
            const end = referenceFor(scene, 'end_frame');
            const status = generationState(scene);
            const pending = ['queued','submitting','polling','processing'].includes(status.id);
            const expanded = state.openContexts.has(Number(scene.id));
            const download = scene.active_generation?.previewUrl
                ? `<a class="vds-download-clip" href="${escapeHtml(scene.active_generation.previewUrl)}&download=1">Descargar MP4</a>` : '';
            return `<article class="vds-sequence-board" data-sequence-board data-scene-id="${scene.id}" data-accent="${(index % 4) + 1}">
                <header class="vds-board-head">
                    <div class="vds-board-title"><span class="vds-sequence-number">${index + 1}</span><h3>Secuencia ${index + 1}</h3></div>
                    <div class="vds-board-actions">
                        <button class="vds-board-drag" type="button" aria-label="Reordenar secuencia">⋮⋮</button>
                        <button class="vds-board-menu" type="button" data-delete-sequence="${scene.id}" aria-label="Eliminar secuencia">×</button>
                    </div>
                </header>
                <p class="vds-board-subtitle">Define el primer y el último fotograma del clip.</p>
                <div class="vds-frame-flow">${frameSlot(scene, 'start_frame', 'Start Frame')}<span class="vds-frame-arrow" aria-hidden="true">→</span>${frameSlot(scene, 'end_frame', 'End Frame')}</div>
                <button class="vds-context-toggle" type="button" data-toggle-context="${scene.id}" aria-expanded="${expanded ? 'true' : 'false'}"><span>＋ Añadir data para la generación</span><span>${expanded ? '−' : '+'}</span></button>
                <div class="vds-context-panel" data-context-panel${expanded ? '' : ' hidden'}>
                    <label><span>Instrucciones y contexto</span><textarea data-scene-field="prompt" data-scene-id="${scene.id}" placeholder="Describe acción, ambiente, luz, cámara, sonido o cualquier dato útil para esta secuencia.">${escapeHtml(scene.prompt || '')}</textarea></label>
                    <div class="vds-context-grid">
                        <label><span>Duración</span><select data-scene-field="durationSeconds" data-scene-id="${scene.id}">${[4,6,8].map(value => `<option value="${value}"${Number(scene.durationSeconds) === value ? ' selected' : ''}>${value} segundos</option>`).join('')}</select></label>
                        <label><span>Movimiento</span><select data-scene-field="cameraMovement" data-scene-id="${scene.id}">${optionMarkup(cameraValues, scene.cameraMovement)}</select></label>
                        <label><span>Intensidad</span><select data-scene-field="motionIntensity" data-scene-id="${scene.id}">${optionMarkup(intensityValues, scene.motionIntensity)}</select></label>
                    </div>
                </div>
                <footer class="vds-board-footer">
                    <span class="vds-generation-state is-${escapeHtml(status.id)}">${escapeHtml(status.label)}</span>
                    ${download || `<button type="button" data-generate-sequence="${scene.id}"${!start || !end || pending ? ' disabled' : ''}>${scene.generation ? 'Regenerar' : 'Generar'}</button>`}
                </footer>
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
                group: { name: 'video-mockups', pull: 'clone', put: false, revertClone: true },
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
        $$('[data-frame-drop]').forEach(slot => {
            state.sortables.push(window.Sortable.create(slot, {
                group: { name: 'video-mockups', pull: false, put: ['video-mockups'] },
                sort: false,
                draggable: '[data-catalog-card]',
                animation: 120,
                onMove: event => Boolean(event.dragged?.dataset?.assetId),
                onAdd: event => {
                    const assetId = Number(event.item?.dataset?.assetId || 0);
                    event.item?.remove();
                    slot.classList.remove('is-drop-target');
                    if (assetId > 0) assignReference(Number(slot.dataset.sceneId), String(slot.dataset.role), assetId);
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

    function assignReference(sceneId, role, assetId) {
        const asset = mockupById(assetId);
        const scene = sceneById(sceneId);
        if (!asset || !scene) return;
        queueMutation(() => api({
            action: 'reference_add',
            sceneId,
            version: currentProject().version,
            reference: { sourceType: 'mockup', sourceId: asset.id, role },
        }), `${role === 'start_frame' ? 'Start Frame' : 'End Frame'} actualizado`);
    }

    function addSequence() {
        if (!currentProject()) return;
        const number = scenes().length + 1;
        queueMutation(() => api({
            action: 'scene_create',
            projectId: currentProject().id,
            version: currentProject().version,
            scene: { title: `Sequence ${number}`, generationMode: 'first_last_frame', durationSeconds: 8, cameraMovement: 'static', motionIntensity: 'low' },
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
                    scene: { title: `Sequence ${number}`, generationMode: 'first_last_frame', durationSeconds: 8 },
                }));
            }
        } catch (_) { /* the mutation already reported the problem */ }
        finally { state.seeding = false; }
    }

    function showGenerationModal(sceneId) {
        const scene = sceneById(sceneId);
        if (!scene) return;
        const start = referenceFor(scene, 'start_frame');
        const end = referenceFor(scene, 'end_frame');
        if (!start || !end) return toast('Completa Start Frame y End Frame antes de generar.', true);
        const index = scenes().findIndex(item => Number(item.id) === Number(scene.id));
        state.pendingGenerationSceneId = scene.id;
        dom.generationSummary.innerHTML = `<div class="vds-generation-facts">
            <div><span>Secuencia</span><strong>${index + 1}</strong></div>
            <div><span>Start Frame</span><strong>${escapeHtml(start.label)}</strong></div>
            <div><span>End Frame</span><strong>${escapeHtml(end.label)}</strong></div>
            <div><span>Duración</span><strong>${Number(scene.durationSeconds)} segundos</strong></div>
            <div><span>Modelo</span><strong>${escapeHtml(state.capabilities.generationModel || 'Veo 3.1')}</strong></div>
        </div>`;
        dom.generationModal.hidden = false;
    }

    async function toggleFavorite(assetId, button) {
        const asset = mockupById(assetId);
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
            if (card) toggleFavorite(Number(card.dataset.assetId), favorite);
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

        const scroll = event.target.closest('[data-scroll-catalog]');
        if (scroll) {
            dom.catalogRail.scrollBy({ left: Number(scroll.dataset.scrollCatalog) * Math.max(320, dom.catalogRail.clientWidth * .72), behavior: 'smooth' });
            return;
        }

        const catalogCard = event.target.closest('[data-catalog-card]');
        if (catalogCard) {
            state.selectedAssetId = Number(catalogCard.dataset.assetId);
            renderCatalog();
            setupSortables();
            return;
        }

        const remove = event.target.closest('[data-remove-reference]');
        if (remove) {
            event.stopPropagation();
            queueMutation(() => api({ action: 'reference_remove', referenceId: Number(remove.dataset.removeReference), version: currentProject().version }), 'Fotograma eliminado');
            return;
        }

        const frame = event.target.closest('[data-frame-drop]');
        if (frame) {
            if (!state.selectedAssetId) return toast('Selecciona primero un mockup del catálogo o arrástralo hasta aquí.');
            assignReference(Number(frame.dataset.sceneId), String(frame.dataset.role), Number(state.selectedAssetId));
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
            state.selectedAssetId = null;
            renderCatalog();
            setupSortables();
            if (currentProject()) queueMutation(() => api({ action: 'project_update', projectId: currentProject().id, version: currentProject().version, changes: { artworkId: state.artworkFilter || null } }));
            return;
        }
        if (event.target === dom.seriesFilter) {
            state.seriesFilter = String(event.target.value || '');
            state.selectedAssetId = null;
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
        const frame = event.target.closest('[data-frame-drop]');
        if (!card && !frame) return;
        event.preventDefault();
        if (card) {
            state.selectedAssetId = Number(card.dataset.assetId);
            renderCatalog();
            setupSortables();
        } else if (state.selectedAssetId) {
            assignReference(Number(frame.dataset.sceneId), String(frame.dataset.role), Number(state.selectedAssetId));
        }
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

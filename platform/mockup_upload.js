(() => {
    'use strict';

    const configNode = document.getElementById('external-mockup-upload-config');
    if (!configNode) return;

    const config = JSON.parse(configNode.textContent || '{}');
    const artworkCards = Array.from(document.querySelectorAll('[data-artwork-card]'));
    const artworkRail = document.querySelector('[data-artwork-rail]');
    const artworkSearch = document.querySelector('[data-artwork-search]');
    const board = document.querySelector('[data-upload-board]');
    const boardEmpty = document.querySelector('[data-board-empty]');
    const boardContent = document.querySelector('[data-board-content]');
    const boardCount = document.querySelector('[data-board-count]');
    const selectedImage = document.querySelector('[data-selected-artwork-image]');
    const selectedTitle = document.querySelector('[data-selected-artwork-title]');
    const selectedMeta = document.querySelector('[data-selected-artwork-meta]');
    const dropzone = document.querySelector('[data-dropzone]');
    const fileInput = document.querySelector('[data-file-input]');
    const folderInput = document.querySelector('[data-folder-input]');
    const uploadGrid = document.querySelector('[data-upload-grid]');
    const uploadNotice = document.querySelector('[data-upload-notice]');
    const actions = document.querySelector('[data-upload-actions]');
    const clearButton = document.querySelector('[data-clear-files]');
    const uploadButton = document.querySelector('[data-upload-files]');
    const uploadLabel = document.querySelector('[data-upload-label]');
    const summary = document.querySelector('[data-upload-summary]');
    const detail = document.querySelector('[data-upload-detail]');
    const successPanel = document.querySelector('[data-upload-success]');
    const successTitle = document.querySelector('[data-success-title]');
    const successCopy = document.querySelector('[data-success-copy]');
    const viewArtwork = document.querySelector('[data-view-artwork]');

    const state = {
        artworkId: '',
        artworkTitle: '',
        artworkMeta: '',
        artworkImage: '',
        files: [],
        uploading: false,
        batchId: '',
    };
    let sortable = null;
    let dragDepth = 0;

    const makeId = () => globalThis.crypto?.randomUUID?.()
        || `${Date.now()}-${Math.random().toString(16).slice(2)}`;

    const formatBytes = (bytes) => {
        if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`;
        return `${(bytes / (1024 * 1024)).toFixed(bytes > 10 * 1024 * 1024 ? 0 : 1)} MB`;
    };

    const plural = (count, singular, pluralText) => count === 1 ? singular : pluralText;

    const statusLabel = (entry) => {
        if (entry.status === 'uploading') return 'Subiendo';
        if (entry.status === 'success') return 'Saved';
        if (entry.status === 'error') return 'Error';
        return 'Ready';
    };

    const setNotice = (message = '', isError = false) => {
        if (!uploadNotice) return;
        uploadNotice.textContent = message;
        uploadNotice.classList.toggle('is-error', isError);
    };

    const destroyPreview = (entry) => {
        if (entry.previewUrl) URL.revokeObjectURL(entry.previewUrl);
    };

    const clearStagedFiles = () => {
        state.files.forEach(destroyPreview);
        state.files = [];
        state.batchId = '';
        setNotice();
        render();
    };

    const selectedCard = () => artworkCards.find((card) => card.dataset.artworkId === state.artworkId);

    const selectArtwork = (card, force = false) => {
        if (!card || state.uploading) return;
        const nextId = String(card.dataset.artworkId || '');
        if (!nextId || nextId === state.artworkId) return;

        const hasUnstoredFiles = state.files.some((entry) => entry.status !== 'success');
        if (!force && hasUnstoredFiles
            && !window.confirm('Changing the artwork will remove files that have not been saved from this board. Continue?')) {
            return;
        }
        if (state.files.length) clearStagedFiles();

        state.artworkId = nextId;
        state.artworkTitle = card.dataset.artworkTitle || 'Artwork';
        state.artworkMeta = card.dataset.artworkMeta || '';
        state.artworkImage = card.dataset.artworkImage || '';

        artworkCards.forEach((item) => {
            const active = item === card;
            item.classList.toggle('is-selected', active);
            item.setAttribute('aria-pressed', active ? 'true' : 'false');
        });

        board?.classList.add('has-artwork');
        if (boardEmpty) boardEmpty.hidden = true;
        if (boardContent) boardContent.hidden = false;
        if (actions) actions.hidden = false;
        if (selectedImage) selectedImage.src = state.artworkImage;
        if (selectedTitle) selectedTitle.textContent = state.artworkTitle;
        if (selectedMeta) selectedMeta.textContent = state.artworkMeta;
        if (viewArtwork) viewArtwork.href = `artwork.php?id=${encodeURIComponent(state.artworkId)}`;

        const url = new URL(window.location.href);
        url.searchParams.set('id', state.artworkId);
        window.history.replaceState({}, '', url);
        render();

        if (!force) board?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    const createUploadCard = (entry) => {
        const card = document.createElement('article');
        card.className = `emu-upload-card is-${entry.status}`;
        card.dataset.uploadId = entry.id;

        const handle = document.createElement('span');
        handle.className = 'emu-card-handle';
        handle.dataset.dragHandle = '';
        handle.setAttribute('aria-hidden', 'true');
        handle.textContent = '⋮⋮';

        const image = document.createElement('img');
        image.src = entry.previewUrl;
        image.alt = '';
        image.draggable = false;

        const badge = document.createElement('span');
        badge.className = 'emu-card-status';
        badge.textContent = statusLabel(entry);

        const remove = document.createElement('button');
        remove.className = 'emu-remove-file';
        remove.type = 'button';
        remove.dataset.removeFile = entry.id;
        remove.setAttribute('aria-label', `Remove ${entry.file.name}`);
        remove.title = 'Remove from board';
        remove.textContent = '×';
        remove.disabled = state.uploading || entry.status === 'success';

        const copy = document.createElement('div');
        copy.className = 'emu-card-copy';
        const name = document.createElement('strong');
        name.title = entry.relativePath;
        name.textContent = entry.file.name;
        const meta = document.createElement('span');
        meta.textContent = entry.status === 'error'
            ? (entry.error || 'Could not save')
            : `${formatBytes(entry.file.size)} · ${statusLabel(entry)}`;
        copy.append(name, meta);

        card.append(handle, image, badge, remove, copy);
        return card;
    };

    const initializeSortable = () => {
        if (!uploadGrid || typeof window.Sortable !== 'function') return;
        sortable?.destroy();
        sortable = window.Sortable.create(uploadGrid, {
            animation: 140,
            easing: 'cubic-bezier(.2,.7,.2,1)',
            handle: '[data-drag-handle]',
            draggable: '.emu-upload-card',
            ghostClass: 'emu-sortable-ghost',
            chosenClass: 'emu-sortable-chosen',
            delay: 140,
            delayOnTouchOnly: true,
            onEnd: () => {
                const order = Array.from(uploadGrid.children).map((item) => item.dataset.uploadId);
                const positions = new Map(order.map((id, index) => [id, index]));
                state.files.sort((left, right) => (positions.get(left.id) ?? 0) - (positions.get(right.id) ?? 0));
            },
        });
    };

    const render = () => {
        if (uploadGrid) {
            uploadGrid.replaceChildren(...state.files.map(createUploadCard));
            initializeSortable();
        }

        const total = state.files.length;
        const ready = state.files.filter((entry) => ['ready', 'error'].includes(entry.status)).length;
        const uploading = state.files.filter((entry) => entry.status === 'uploading').length;
        const success = state.files.filter((entry) => entry.status === 'success').length;
        const failed = state.files.filter((entry) => entry.status === 'error').length;
        const totalBytes = state.files.reduce((sum, entry) => sum + entry.file.size, 0);

        if (boardCount) boardCount.textContent = `${total} ${plural(total, 'file', 'files')}`;
        if (summary) {
            summary.textContent = state.uploading
                ? `Guardando ${success + uploading} de ${total}`
                : total
                    ? `${total} ${plural(total, 'mockup ready', 'mockups ready')} for ${state.artworkTitle}`
                    : 'Add mockups for this artwork';
        }
        if (detail) {
            if (!total) detail.textContent = 'No files selected';
            else if (state.uploading) detail.textContent = `${success} guardados · ${uploading} subiendo · ${ready - failed} en espera`;
            else detail.textContent = `${formatBytes(totalBytes)} en total${failed ? ` · ${failed} con error` : ''}`;
        }

        if (clearButton) clearButton.disabled = state.uploading || total === 0;
        if (uploadButton) uploadButton.disabled = state.uploading || ready === 0 || !state.artworkId;
        if (uploadLabel) {
            if (state.uploading) uploadLabel.textContent = `Guardando ${success} de ${total}`;
            else if (failed) uploadLabel.textContent = `Retry ${failed}`;
            else if (success === total && total > 0) uploadLabel.textContent = 'Mockups guardados';
            else uploadLabel.textContent = `Save ${ready || ''} to artwork`.replace('  ', ' ');
        }

        if (successPanel) {
            successPanel.hidden = success === 0;
            if (successTitle) successTitle.textContent = `${success} ${plural(success, 'mockup saved', 'mockups saved')}`;
            if (successCopy) {
                successCopy.textContent = failed
                    ? `${failed} ${plural(failed, 'file needs', 'files need')} another attempt.`
                    : `They are already linked to ${state.artworkTitle}.`;
            }
        }
    };

    const normalizeFileItem = (item) => {
        const file = item instanceof File ? item : item.file;
        const relativePath = item instanceof File
            ? (item.webkitRelativePath || item.name)
            : (item.relativePath || file.webkitRelativePath || file.name);
        return { file, relativePath };
    };

    const isSupportedFile = (file) => {
        const supportedType = ['image/jpeg', 'image/png', 'image/webp'].includes(String(file.type || '').toLowerCase());
        const supportedExtension = /\.(jpe?g|png|webp)$/i.test(file.name || '');
        return supportedType || supportedExtension;
    };

    const addFiles = (items) => {
        if (state.uploading || !state.artworkId) return;
        const normalized = Array.from(items || []).map(normalizeFileItem);
        let unsupported = 0;
        let oversized = 0;
        let duplicated = 0;
        let capped = 0;
        const known = new Set(state.files.map((entry) => entry.key));

        for (const item of normalized) {
            const { file, relativePath } = item;
            if (!isSupportedFile(file)) {
                unsupported++;
                continue;
            }
            if (file.size > Number(config.maxBytes || 20 * 1024 * 1024)) {
                oversized++;
                continue;
            }
            const key = `${relativePath}:${file.size}:${file.lastModified}`;
            if (known.has(key)) {
                duplicated++;
                continue;
            }
            if (state.files.length >= Number(config.maxFiles || 80)) {
                capped++;
                continue;
            }
            known.add(key);
            state.files.push({
                id: makeId(),
                key,
                file,
                relativePath,
                previewUrl: URL.createObjectURL(file),
                status: 'ready',
                error: '',
                mockup: null,
            });
        }

        const rejected = [];
        if (unsupported) rejected.push(`${unsupported} con formato no compatible`);
        if (oversized) rejected.push(`${oversized} over 20 MB`);
        if (duplicated) rejected.push(`${duplicated} repetidos`);
        if (capped) rejected.push(`limit of ${config.maxFiles || 80} reached`);
        setNotice(rejected.length ? `No se agregaron: ${rejected.join(', ')}.` : 'Puedes arrastrar las miniaturas para ordenar el lote.');
        render();
    };

    const readDirectoryEntries = (reader) => new Promise((resolve, reject) => {
        const entries = [];
        const readBatch = () => reader.readEntries((batch) => {
            if (!batch.length) {
                resolve(entries);
                return;
            }
            entries.push(...batch);
            readBatch();
        }, reject);
        readBatch();
    });

    const filesFromEntry = async (entry, prefix = '') => {
        if (entry.isFile) {
            const file = await new Promise((resolve, reject) => entry.file(resolve, reject));
            return [{ file, relativePath: `${prefix}${file.name}` }];
        }
        if (!entry.isDirectory) return [];

        const children = await readDirectoryEntries(entry.createReader());
        const nested = await Promise.all(children.map((child) => filesFromEntry(child, `${prefix}${entry.name}/`)));
        return nested.flat();
    };

    const filesFromDrop = async (dataTransfer) => {
        const entries = Array.from(dataTransfer.items || [])
            .map((item) => item.webkitGetAsEntry?.())
            .filter(Boolean);
        if (!entries.length) return Array.from(dataTransfer.files || []);
        const nested = await Promise.all(entries.map((entry) => filesFromEntry(entry)));
        return nested.flat();
    };

    const parseJsonResponse = async (response) => {
        const text = await response.text();
        let payload = null;
        try {
            payload = JSON.parse(text);
        } catch (error) {
            if (response.status === 413) throw new Error('The file exceeds the server upload limit.');
            throw new Error('El servidor no pudo procesar la carga.');
        }
        if (!response.ok || !payload.ok) throw new Error(payload.error || 'The mockup could not be saved.');
        return payload;
    };

    const uploadEntry = async (entry, order) => {
        entry.status = 'uploading';
        entry.error = '';
        render();

        const form = new FormData();
        form.append('csrf', config.csrf || '');
        form.append('artwork_id', state.artworkId);
        form.append('batch_id', state.batchId);
        form.append('sort_order', String(order));
        form.append('relative_path', entry.relativePath);
        form.append('mockup', entry.file, entry.file.name);

        try {
            const response = await fetch(config.endpoint || 'upload_external_mockup.php', {
                method: 'POST',
                body: form,
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            const payload = await parseJsonResponse(response);
            entry.status = 'success';
            entry.mockup = payload.mockup || null;
        } catch (error) {
            entry.status = 'error';
            entry.error = error.message || 'Could not save';
        }
        render();
    };

    const uploadFiles = async () => {
        if (state.uploading || !state.artworkId) return;
        const queue = state.files.filter((entry) => ['ready', 'error'].includes(entry.status));
        if (!queue.length) return;

        state.uploading = true;
        state.batchId ||= makeId();
        setNotice('Guardando el lote. Puedes seguir viendo el progreso en cada miniatura.');
        render();

        let cursor = 0;
        const worker = async () => {
            while (cursor < queue.length) {
                const current = cursor++;
                const entry = queue[current];
                await uploadEntry(entry, state.files.indexOf(entry));
            }
        };
        const workerCount = Math.max(1, Math.min(Number(config.concurrency || 3), queue.length));
        await Promise.all(Array.from({ length: workerCount }, worker));

        state.uploading = false;
        const failures = state.files.filter((entry) => entry.status === 'error');
        if (failures.length) {
            setNotice(`${failures.length} ${plural(failures.length, 'file could not', 'files could not')} be saved. Review the cards and try again.`, true);
        } else {
            setNotice('All mockups were linked successfully.');
        }
        render();
    };

    artworkCards.forEach((card) => card.addEventListener('click', () => selectArtwork(card)));

    artworkSearch?.addEventListener('input', () => {
        const query = artworkSearch.value.trim().toLocaleLowerCase();
        artworkCards.forEach((card) => {
            card.hidden = query !== '' && !(card.dataset.artworkSearchValue || '').includes(query);
        });
    });

    document.querySelectorAll('[data-scroll-artworks]').forEach((button) => {
        button.addEventListener('click', () => artworkRail?.scrollBy({
            left: Number(button.dataset.scrollArtworks || 0) * Math.max(480, artworkRail.clientWidth * .72),
            behavior: 'smooth',
        }));
    });

    document.querySelector('[data-change-artwork]')?.addEventListener('click', () => {
        document.querySelector('.emu-catalog')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        window.setTimeout(() => artworkSearch?.focus(), 350);
    });

    document.querySelector('[data-pick-files]')?.addEventListener('click', (event) => {
        event.stopPropagation();
        fileInput?.click();
    });
    document.querySelector('[data-pick-folder]')?.addEventListener('click', (event) => {
        event.stopPropagation();
        folderInput?.click();
    });
    dropzone?.addEventListener('click', (event) => {
        if (!event.target.closest('button')) fileInput?.click();
    });
    dropzone?.addEventListener('keydown', (event) => {
        if (['Enter', ' '].includes(event.key)) {
            event.preventDefault();
            fileInput?.click();
        }
    });
    fileInput?.addEventListener('change', () => {
        addFiles(fileInput.files || []);
        fileInput.value = '';
    });
    folderInput?.addEventListener('change', () => {
        addFiles(folderInput.files || []);
        folderInput.value = '';
    });

    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach((eventName) => {
        board?.addEventListener(eventName, (event) => {
            event.preventDefault();
            event.stopPropagation();
        });
    });
    board?.addEventListener('dragenter', () => {
        dragDepth++;
        board.classList.add('is-drop-target');
    });
    board?.addEventListener('dragleave', () => {
        dragDepth = Math.max(0, dragDepth - 1);
        if (dragDepth === 0) board.classList.remove('is-drop-target');
    });
    board?.addEventListener('drop', async (event) => {
        dragDepth = 0;
        board.classList.remove('is-drop-target');
        if (!state.artworkId || state.uploading) return;
        try {
            addFiles(await filesFromDrop(event.dataTransfer));
        } catch (error) {
            setNotice('That folder could not be read. Try the “Choose folder” button.', true);
        }
    });

    uploadGrid?.addEventListener('click', (event) => {
        const remove = event.target.closest('[data-remove-file]');
        if (!remove || state.uploading) return;
        const index = state.files.findIndex((entry) => entry.id === remove.dataset.removeFile && entry.status !== 'success');
        if (index < 0) return;
        destroyPreview(state.files[index]);
        state.files.splice(index, 1);
        setNotice(state.files.length ? 'File removed from board.' : '');
        render();
    });

    clearButton?.addEventListener('click', () => {
        if (!state.files.length || state.uploading) return;
        const hasSaved = state.files.some((entry) => entry.status === 'success');
        const prompt = hasSaved
            ? 'Clear the board? Saved mockups will remain linked to the artwork.'
            : 'Remove all thumbnails from this board?';
        if (window.confirm(prompt)) clearStagedFiles();
    });
    uploadButton?.addEventListener('click', uploadFiles);

    window.addEventListener('beforeunload', (event) => {
        if (!state.uploading && !state.files.some((entry) => entry.status === 'ready')) return;
        event.preventDefault();
        event.returnValue = '';
    });

    const initialId = String(config.selectedArtworkId || '');
    if (initialId) selectArtwork(artworkCards.find((card) => card.dataset.artworkId === initialId), true);
    else render();
})();

(() => {
    'use strict';

    const payloadNode = document.getElementById('camera-board-cameras');
    const configNode = document.getElementById('camera-board-config');
    if (!payloadNode || !configNode) return;

    let cameraPayload = {};
    let config = {};
    try {
        cameraPayload = JSON.parse(payloadNode.textContent || '{}');
        config = JSON.parse(configNode.textContent || '{}');
    } catch (error) {
        cameraPayload = {};
        config = {};
    }

    const cameras = new Map(Object.entries(cameraPayload));
    const maxPerBoard = Math.max(1, Number(config.maxPerBoard || 12));
    const page = document.querySelector('[data-camera-page]');
    const library = document.querySelector('[data-camera-library]');
    const boards = document.querySelector('[data-camera-boards]');
    const boardLists = Array.from(document.querySelectorAll('[data-board-list]'));
    const boardForm = document.querySelector('[data-board-form]');
    const editorForm = document.querySelector('[data-camera-editor-form]');
    const inspectorBackdrop = document.querySelector('[data-camera-inspector-backdrop]');
    const toast = document.querySelector('[data-camera-toast]');
    let dirty = false;
    let toastTimer = 0;
    let dragActive = false;
    const sortables = [];

    const directCards = (container) => container
        ? Array.from(container.querySelectorAll(':scope > [data-camera-card]'))
        : [];

    const showToast = (message) => {
        if (!toast) return;
        toast.textContent = message;
        toast.classList.add('is-visible');
        window.clearTimeout(toastTimer);
        toastTimer = window.setTimeout(() => toast.classList.remove('is-visible'), 2200);
    };

    const emptyCopyFor = (container) => container === library
        ? 'Todas las cámaras están asignadas. Arrastrá una desde un tablero para dejarla en espera.'
        : 'Arrastrá cámaras aquí para activar este tablero.';

    const updateEmptyState = (container) => {
        if (!container) return;
        const cards = directCards(container);
        const current = container.querySelector(':scope > [data-empty-state]');
        if (cards.length > 0) {
            current?.remove();
            return;
        }
        if (current) return;
        const empty = document.createElement('div');
        empty.className = container === library ? 'cmb-empty cmb-empty--library' : 'cmb-empty';
        empty.setAttribute('data-empty-state', '');
        empty.textContent = emptyCopyFor(container);
        container.appendChild(empty);
    };

    const appendBoardInputs = (container) => {
        if (!container) return;
        container.replaceChildren();
        boardLists.forEach((list) => {
            const boardNumber = list.dataset.boardNumber || '1';
            const ids = directCards(list).map((card) => card.dataset.cameraId || '').filter(Boolean);
            const values = ids.length ? ids : [''];
            values.forEach((id) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = `board_slots_by_board[${boardNumber}][]`;
                input.value = id;
                container.appendChild(input);
            });
        });
    };

    const syncBoardInputs = () => {
        appendBoardInputs(boardForm?.querySelector('[data-board-inputs]'));
    };

    const updateBoardState = (markDirty = false) => {
        updateEmptyState(library);
        boardLists.forEach(updateEmptyState);

        const libraryCount = directCards(library).length;
        let assignedCount = 0;
        boardLists.forEach((list) => {
            const count = directCards(list).length;
            assignedCount += count;
            const board = list.closest('[data-camera-board]');
            const counter = board?.querySelector('[data-board-count]');
            if (counter) counter.textContent = `${count}/${maxPerBoard}`;
        });

        const libraryCounter = document.querySelector('[data-library-count]');
        if (libraryCounter) libraryCounter.textContent = `${libraryCount} en espera`;
        const summary = document.querySelector('[data-save-summary]');
        if (summary) summary.textContent = `${assignedCount} cámaras activas · ${libraryCount} en espera`;

        if (markDirty) dirty = true;
        const saveBar = document.querySelector('.cmb-save-bar');
        saveBar?.classList.toggle('is-dirty', dirty);
        const saveTitle = document.querySelector('[data-save-title]');
        if (saveTitle) saveTitle.textContent = dirty ? 'Cambios sin guardar' : 'Organización actual';
        syncBoardInputs();
    };

    const canMove = (event) => {
        const target = event.to;
        if (!target?.matches('[data-board-list]') || event.from === target) return true;
        if (directCards(target).length < maxPerBoard) return true;
        showToast(`Este tablero ya tiene ${maxPerBoard} cámaras.`);
        return false;
    };

    const sortableOptions = () => ({
        group: { name: 'camera-board-slots', pull: true, put: true },
        draggable: '.cmb-sortable-item',
        handle: '[data-drag-handle]',
        animation: 140,
        easing: 'cubic-bezier(.2,.7,.2,1)',
        forceFallback: false,
        fallbackTolerance: 4,
        swapThreshold: .62,
        invertSwap: true,
        invertedSwapThreshold: .48,
        emptyInsertThreshold: 48,
        scroll: true,
        scrollSensitivity: 72,
        scrollSpeed: 12,
        delay: 160,
        delayOnTouchOnly: true,
        touchStartThreshold: 4,
        ghostClass: 'cmb-sortable-placeholder',
        chosenClass: 'cmb-sortable-chosen',
        dragClass: 'cmb-sortable-drag',
        fallbackClass: 'cmb-sortable-mirror',
        filter: '[data-edit-camera]',
        preventOnFilter: false,
        onMove: canMove,
        onStart: (event) => {
            dragActive = true;
            document.body.classList.add('cmb-is-sorting');
            event.from?.closest('[data-camera-board]')?.classList.add('is-drop-target');
        },
        onEnd: (event) => {
            dragActive = false;
            document.body.classList.remove('cmb-is-sorting');
            document.querySelectorAll('.is-drop-target').forEach((item) => item.classList.remove('is-drop-target'));
            updateBoardState(true);
            event.item?.focus();
        },
        onUnchoose: () => {
            dragActive = false;
            document.body.classList.remove('cmb-is-sorting');
        },
    });

    const initializeSortables = () => {
        if (typeof window.Sortable !== 'function') {
            showToast('No se pudo iniciar el sistema de arrastre.');
            return;
        }
        if (library) {
            sortables.push(window.Sortable.create(library, {
                ...sortableOptions(),
                direction: 'horizontal',
            }));
        }
        boardLists.forEach((list) => {
            sortables.push(window.Sortable.create(list, {
                ...sortableOptions(),
                direction: 'horizontal',
            }));
        });
    };

    const setFocusedBoard = (boardNumber = '') => {
        const normalized = ['1', '2', '3'].includes(String(boardNumber)) ? String(boardNumber) : '';
        page?.toggleAttribute('data-focused-board', normalized !== '');
        if (page && normalized) page.dataset.focusedBoard = normalized;
        if (page && !normalized) delete page.dataset.focusedBoard;
        boards?.classList.toggle('is-focused', normalized !== '');
        document.querySelectorAll('[data-camera-board]').forEach((board) => {
            board.classList.toggle('is-focused', normalized !== '' && board.dataset.boardNumber === normalized);
        });
    };

    const markSelectedCamera = (id = '') => {
        document.querySelectorAll('[data-camera-card]').forEach((card) => {
            card.classList.toggle('is-selected', id !== '' && card.dataset.cameraId === id);
        });
        const selectedInput = boardForm?.querySelector('[name="selected_slot_id"]');
        if (selectedInput) selectedInput.value = id;
    };

    const openEditor = (cameraId = '') => {
        if (!inspectorBackdrop || !editorForm) return;
        const camera = cameraId ? cameras.get(String(cameraId)) : null;
        if (cameraId && !camera) {
            showToast('No se encontró esa cámara.');
            return;
        }

        const isNew = !camera;
        const idInput = editorForm.querySelector('[data-editor-id]');
        const nameInput = editorForm.querySelector('[data-editor-name]');
        const promptInput = editorForm.querySelector('[data-editor-prompt]');
        const actionInput = editorForm.querySelector('[data-editor-action]');
        const title = inspectorBackdrop.querySelector('[data-editor-title]');
        const kicker = inspectorBackdrop.querySelector('[data-editor-kicker]');
        const origin = inspectorBackdrop.querySelector('[data-editor-origin]');
        const deleteButton = inspectorBackdrop.querySelector('[data-delete-camera]');

        if (idInput) {
            idInput.value = camera?.id || '';
            idInput.readOnly = !isNew;
        }
        if (nameInput) nameInput.value = camera?.name || '';
        if (promptInput) promptInput.value = camera?.prompt || '';
        if (actionInput) actionInput.value = isNew ? 'save_slot' : 'save_scene_quick';
        if (title) title.textContent = isNew ? 'Nueva cámara' : String(camera.name || camera.id);
        if (kicker) kicker.textContent = isNew ? 'Crear cámara' : 'Editor de cámara';
        if (origin) origin.textContent = isNew ? 'Nueva' : String(camera.origin || 'Base');
        if (deleteButton) deleteButton.hidden = isNew;

        markSelectedCamera(camera?.id || '');
        inspectorBackdrop.hidden = false;
        document.body.classList.add('cmb-inspector-open');
        window.setTimeout(() => (isNew ? idInput : nameInput)?.focus(), 0);
    };

    const closeEditor = () => {
        if (!inspectorBackdrop) return;
        inspectorBackdrop.hidden = true;
        document.body.classList.remove('cmb-inspector-open');
        markSelectedCamera('');
    };

    document.addEventListener('click', (event) => {
        const scrollButton = event.target.closest('[data-scroll-library]');
        if (scrollButton && library) {
            const direction = Number(scrollButton.dataset.scrollLibrary || 1);
            library.scrollBy({ left: direction * Math.max(220, library.clientWidth * .78), behavior: 'smooth' });
            return;
        }

        const focusButton = event.target.closest('[data-focus-board]');
        if (focusButton) {
            setFocusedBoard(focusButton.dataset.focusBoard || '');
            return;
        }

        if (event.target.closest('[data-exit-board-focus]')) {
            setFocusedBoard('');
            return;
        }

        if (event.target.closest('[data-new-camera]')) {
            openEditor('');
            return;
        }

        const backdrop = event.target.closest('[data-camera-inspector-backdrop]');
        if (event.target.closest('[data-close-camera-inspector]') || (backdrop && event.target === backdrop)) {
            closeEditor();
            return;
        }

        const editButton = event.target.closest('[data-edit-camera]');
        if (editButton) {
            const card = editButton.closest('[data-camera-card]');
            if (card) openEditor(card.dataset.cameraId || '');
            return;
        }

        const card = event.target.closest('[data-camera-card]');
        if (card && !dragActive && !event.target.closest('[data-drag-handle]')) {
            openEditor(card.dataset.cameraId || '');
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            if (inspectorBackdrop && !inspectorBackdrop.hidden) closeEditor();
            else setFocusedBoard('');
            return;
        }
        if (!['Enter', ' '].includes(event.key)) return;
        const card = event.target.closest('[data-camera-card]');
        if (!card || event.target.closest('button, input, textarea, select, a')) return;
        event.preventDefault();
        openEditor(card.dataset.cameraId || '');
    });

    boardLists.forEach((list) => {
        list.addEventListener('dragenter', () => list.closest('[data-camera-board]')?.classList.add('is-drop-target'));
        list.addEventListener('dragleave', (event) => {
            if (!list.contains(event.relatedTarget)) list.closest('[data-camera-board]')?.classList.remove('is-drop-target');
        });
    });

    boardForm?.addEventListener('submit', () => syncBoardInputs());
    editorForm?.addEventListener('submit', () => {
        appendBoardInputs(editorForm.querySelector('[data-editor-board-inputs]'));
    });

    updateBoardState(false);
    initializeSortables();

    if (config.mode === 'new') openEditor('');
    else if (config.selectedCameraId && cameras.has(String(config.selectedCameraId))) {
        openEditor(String(config.selectedCameraId));
    }
})();

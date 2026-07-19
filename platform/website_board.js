(() => {
    'use strict';

    const root = document.querySelector('[data-website-board]');
    const dataNode = document.getElementById('website-board-data');
    if (!root || !dataNode) return;

    let payload;
    try { payload = JSON.parse(dataNode.textContent || '{}'); } catch (_) { return; }

    const config = payload.config || {};
    const state = {
        sources: Array.isArray(payload.sources) ? payload.sources : [],
        catalog: Array.isArray(payload.catalog) ? payload.catalog : [],
        notes: Array.isArray(payload.notes) ? payload.notes : [],
    };
    const validBoards = ['catalog', 'notes'];
    const validTypes = ['artwork', 'series', 'mockup'];
    const hasCatalogBoard = () => Boolean(document.querySelector('[data-board="catalog"]'));
    let focusedBoard = validBoards.includes(config.initialFocus) ? config.initialFocus : '';
    if (focusedBoard === 'catalog' && !hasCatalogBoard()) focusedBoard = '';
    let activeDraftBoard = '';
    let activeDraftId = 0;
    let sourceFilter = validTypes.includes(config.defaultSourceType) ? config.defaultSourceType : (hasCatalogBoard() ? 'artwork' : 'mockup');
    let noteFilter = sourceFilter === 'artwork' && hasCatalogBoard() ? 'mockup' : sourceFilter;
    let toastTimer = null;
    let catalogSortable = null;
    let noteDropSortable = null;
    let mediaSortables = [];
    const editors = new Map();

    const escapeHtml = (value) => String(value ?? '')
        .replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;').replaceAll("'", '&#039;');

    const mediaUrl = (file, width = 900) => `media.php?file=${encodeURIComponent(String(file || '').split(/[\\/]/).pop())}&thumb=1&w=${width}`;

    const hydrateSource = (source) => ({
        ...(source || {}),
        image: source?.image || mediaUrl(source?.file),
    });

    const hydrateCatalog = (entry) => ({
        ...(entry || {}),
        media: (entry?.media || []).map((item) => ({ ...item, image: item.image || mediaUrl(item.file) })),
    });

    const hydrateNote = (note) => ({
        ...(note || {}),
        source: note?.source ? hydrateSource(note.source) : null,
        media: (note?.media || []).map(hydrateSource),
    });

    state.sources = state.sources.map(hydrateSource);
    state.catalog = state.catalog.map(hydrateCatalog);
    state.notes = state.notes.map(hydrateNote);

    const sortCatalogLikeArtworks = () => {
        const sourceOrder = new Map(
            state.sources
                .filter((source) => source.type === 'artwork')
                .map((source, index) => [String(source.key), index])
        );
        state.catalog.sort((left, right) => {
            const leftOrder = sourceOrder.get(`artwork:${Number(left.artworkId || 0)}`) ?? Number.MAX_SAFE_INTEGER;
            const rightOrder = sourceOrder.get(`artwork:${Number(right.artworkId || 0)}`) ?? Number.MAX_SAFE_INTEGER;
            return leftOrder - rightOrder;
        });
    };
    sortCatalogLikeArtworks();

    const preferredNotesFilter = () => 'mockup';

    const showToast = (message, error = false) => {
        const toast = document.querySelector('[data-website-toast]');
        if (!toast) return;
        window.clearTimeout(toastTimer);
        toast.textContent = message;
        toast.classList.toggle('is-error', error);
        toast.classList.add('is-visible');
        toastTimer = window.setTimeout(() => toast.classList.remove('is-visible'), 3400);
    };

    const post = async (action, values = {}) => {
        const response = await fetch('website_board_action.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ csrf: config.csrf, action, ...values }),
        });
        const result = await response.json().catch(() => ({}));
        if (!response.ok || !result.ok) throw new Error(result.error || 'The action could not be completed.');
        return result.result;
    };

    const statusLabel = (status, visibility = '') => {
        if (status !== 'published') return 'Draft';
        if (visibility === 'unlisted') return 'Hidden';
        return 'Published';
    };

    const emptyCover = '<div class="wbb-card-cover"></div>';

    const catalogMarkup = (entry) => {
        const cover = entry.media?.[0]?.image || '';
        const actionButtons = entry.status === 'published'
            ? `${entry.visibility === 'unlisted'
                ? '<button type="button" data-catalog-action="show">Show</button>'
                : '<button type="button" data-catalog-action="hide">Hide</button>'}
               <button type="button" data-catalog-action="unpublish">Unpublish</button>`
            : '<button type="button" class="wbb-primary" data-catalog-action="publish">Publish</button>';
        return `<article class="wbb-card wbb-catalog-card" data-catalog-card data-id="${escapeHtml(entry.id)}">
            ${cover ? `<div class="wbb-card-cover"><img src="${escapeHtml(cover)}" alt="${escapeHtml(entry.title)}"><span class="wbb-card-status">${escapeHtml(statusLabel(entry.status, entry.visibility))}</span></div>` : emptyCover}
            <div class="wbb-card-body">
                <h3>${escapeHtml(entry.title || 'Untitled artwork')}</h3>
                <p>${escapeHtml(entry.shortDescription || entry.description || entry.seriesTitle || 'Artwork page prepared for the website.')}</p>
            </div>
            <div class="wbb-edit-fields">
                <label class="wbb-field"><span>Title</span><input data-catalog-field="title" value="${escapeHtml(entry.title)}"></label>
                <label class="wbb-field"><span>Short description</span><textarea data-catalog-field="shortDescription">${escapeHtml(entry.shortDescription)}</textarea></label>
                <label class="wbb-field"><span>Full description</span><textarea data-catalog-field="description">${escapeHtml(entry.description)}</textarea></label>
                <label class="wbb-field"><span>Link label</span><input data-catalog-field="ctaLabel" value="${escapeHtml(entry.ctaLabel)}" placeholder="View this artwork"></label>
                <label class="wbb-field"><span>Secondary link</span><input data-catalog-field="ctaUrl" value="${escapeHtml(entry.ctaUrl)}" placeholder="https://..."></label>
                <div class="wbb-actions">
                    <button type="button" data-catalog-save>Save</button>
                    ${actionButtons}
                    <button type="button" class="wbb-danger" data-catalog-action="delete">Remove</button>
                </div>
            </div>
        </article>`;
    };

    const noteMediaMarkup = (note) => {
        const sourceKey = String(note.source?.key || '');
        const canRemoveMedia = (note.media || []).length > 1;
        return (note.media || []).map((item) => `<div class="wbb-note-media-item${String(item.key) === sourceKey ? ' is-source' : ''}" data-note-media-item data-source-key="${escapeHtml(item.key)}">
            <img src="${escapeHtml(item.image || mediaUrl(item.file))}" alt="${escapeHtml(item.label)}">
            <span>${String(item.key) === sourceKey ? 'Source' : escapeHtml(item.type)}</span>
            ${canRemoveMedia ? '<button type="button" data-remove-note-media aria-label="Remove image" title="Remove image">×</button>' : ''}
        </div>`).join('');
    };

    const noteEditorialGuideMarkup = (note) => {
        const source = note.source || {};
        const guide = source.editorialGuide || {};
        if (source.type !== 'mockup' || !Object.values(guide).some((value) => Array.isArray(value) ? value.length : String(value || '').trim())) return '';
        const atmosphere = Array.isArray(guide.atmosphere) ? guide.atmosphere.filter(Boolean) : [];
        const keywords = Array.isArray(guide.keywords) ? guide.keywords.filter(Boolean) : [];
        const contextRows = [
            ['Artwork', source.artworkTitle],
            ['Series', source.seriesTitle],
            ['Scene', guide.spaceType || source.contextTitle],
        ].filter(([, value]) => String(value || '').trim());
        const textSection = (label, value) => String(value || '').trim()
            ? `<section><h5>${escapeHtml(label)}</h5><p>${escapeHtml(value)}</p></section>`
            : '';
        const chips = (label, values) => values.length
            ? `<section><h5>${escapeHtml(label)}</h5><div class="wbb-guide-terms">${values.map((value) => `<span>${escapeHtml(value)}</span>`).join('')}</div></section>`
            : '';

        return `<aside class="wbb-source-guide" aria-label="Mockup editorial guide">
            <div class="wbb-guide-heading">
                <span>Editorial guide</span>
                <small>Mockup reference · not published automatically</small>
            </div>
            <h4>${escapeHtml(guide.title || source.label || 'Mockup metadata')}</h4>
            ${contextRows.length ? `<dl>${contextRows.map(([label, value]) => `<div><dt>${escapeHtml(label)}</dt><dd>${escapeHtml(value)}</dd></div>`).join('')}</dl>` : ''}
            ${textSection('Scene reading', guide.description)}
            ${textSection('Artwork and space relationship', guide.artworkRelationship)}
            ${chips('Atmosphere', atmosphere)}
            ${chips('Key ideas', keywords)}
        </aside>`;
    };

    const noteMarkup = (note) => {
        const cover = note.source?.image || note.media?.[0]?.image || '';
        const typeLabel = { artwork: 'Artwork', series: 'Series', mockup: 'Mockup' }[note.source?.type] || 'Image';
        return `<article class="wbb-card wbb-note-card" data-note-card data-id="${escapeHtml(note.id)}">
            <div class="wbb-note-reference">
                ${cover ? `<div class="wbb-card-cover"><img src="${escapeHtml(cover)}" alt="${escapeHtml(note.title)}"><span class="wbb-card-status">${escapeHtml(statusLabel(note.status))}</span></div>` : emptyCover}
                ${noteEditorialGuideMarkup(note)}
            </div>
            <button type="button" class="wbb-card-remove" data-note-action="delete" aria-label="Delete this note">×</button>
            <div class="wbb-note-workspace">
                <div class="wbb-card-body">
                    <h3>${escapeHtml(note.title || 'Untitled note')}</h3>
                    <div class="wbb-note-source"><span>${escapeHtml(typeLabel)}:</span><strong>${escapeHtml(note.sourceLabel || note.source?.label || '')}</strong></div>
                    <p>${escapeHtml(String(note.objective || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim() || 'Start developing this Studio Note.')}</p>
                </div>
                <div class="wbb-note-media" data-note-media-list>${noteMediaMarkup(note)}</div>
                <div class="wbb-edit-fields">
                    <label class="wbb-field"><span>Title</span><input data-note-title value="${escapeHtml(note.title)}"></label>
                    <div class="wbb-field wbb-note-editor-field" data-note-editor-drop><span>Essay or note · drop images here</span><div class="wbb-note-editor" data-note-editor></div></div>
                    <div class="wbb-actions">
                        <button type="button" data-note-save>Save draft</button>
                        ${note.status === 'published'
                            ? '<button type="button" data-note-action="unpublish">Unpublish</button>'
                            : '<button type="button" class="wbb-primary" data-note-action="publish">Publish</button>'}
                    </div>
                </div>
            </div>
        </article>`;
    };

    const initializeEditors = () => {
        editors.clear();
        if (focusedBoard !== 'notes') return;
        document.querySelectorAll('[data-note-card]').forEach((card) => {
            const id = Number(card.dataset.id || 0);
            const note = state.notes.find((item) => Number(item.id) === id);
            const node = card.querySelector('[data-note-editor]');
            if (!node || !note) return;
            if (typeof window.Quill === 'function') {
                const quill = new window.Quill(node, {
                    theme: 'snow',
                    modules: { toolbar: [[{ header: [2, 3, false] }], ['bold', 'italic', 'underline'], [{ list: 'ordered' }, { list: 'bullet' }], ['blockquote', 'link'], ['clean']] },
                });
                quill.root.innerHTML = String(note.objective || '');
                editors.set(id, { quill, node, html: () => quill.root.innerHTML });
            } else {
                node.classList.add('wbb-note-editor-fallback');
                node.contentEditable = 'true';
                node.innerHTML = String(note.objective || '');
                editors.set(id, { node, html: () => node.innerHTML });
            }
        });
    };

    const renderBoards = () => {
        const catalogList = document.querySelector('[data-catalog-list]');
        const notesList = document.querySelector('[data-notes-list]');
        const catalogItems = focusedBoard === 'catalog' && activeDraftBoard === 'catalog' && activeDraftId
            ? state.catalog.filter((entry) => Number(entry.id) === activeDraftId)
            : state.catalog;
        const noteItems = focusedBoard === 'notes' && activeDraftBoard === 'notes' && activeDraftId
            ? state.notes.filter((note) => Number(note.id) === activeDraftId)
            : state.notes;
        if (catalogList) catalogList.innerHTML = catalogItems.length ? catalogItems.map(catalogMarkup).join('') : '<div class="wbb-empty">Drag an artwork here to begin.</div>';
        if (notesList) notesList.innerHTML = noteItems.length ? noteItems.map(noteMarkup).join('') : '<div class="wbb-empty">Drag an image here to begin a note.</div>';
        const catalogCount = document.querySelector('[data-board-count="catalog"]');
        const notesCount = document.querySelector('[data-board-count="notes"]');
        if (catalogCount) catalogCount.textContent = `${state.catalog.length} ${state.catalog.length === 1 ? 'artwork' : 'artworks'}`;
        if (notesCount) notesCount.textContent = `${state.notes.length} ${state.notes.length === 1 ? 'note' : 'notes'}`;
        initializeEditors();
        initializeDropSortables();
    };

    const applySourceFilter = () => {
        if (focusedBoard === 'catalog') sourceFilter = 'artwork';
        if (focusedBoard === 'notes' && !validTypes.includes(sourceFilter)) sourceFilter = noteFilter;
        document.querySelectorAll('[data-source-card]').forEach((card) => {
            const matchesCategory = card.dataset.sourceType === sourceFilter;
            card.hidden = !matchesCategory;
        });
        const typeSelect = document.querySelector('[data-source-type-select]');
        if (typeSelect) {
            typeSelect.value = sourceFilter;
            Array.from(typeSelect.options).forEach((option) => {
                const unavailable = focusedBoard === 'catalog' && option.value !== 'artwork';
                option.disabled = unavailable;
                option.hidden = unavailable;
            });
        }
    };

    const applyFocus = () => {
        root.dataset.focusedBoard = focusedBoard || '';
        if (!focusedBoard) root.removeAttribute('data-focused-board');
        const boards = document.querySelector('[data-website-boards]');
        boards?.classList.toggle('is-focused', Boolean(focusedBoard));
        boards?.classList.toggle('has-active-draft', Boolean(activeDraftId));
        document.querySelectorAll('[data-board]').forEach((board) => board.classList.toggle('is-focused', board.dataset.board === focusedBoard));
        if (focusedBoard === 'catalog') sourceFilter = 'artwork';
        if (focusedBoard === 'notes') sourceFilter = noteFilter;
        applySourceFilter();
        renderBoards();
    };

    const enterFocus = (board, draftId = 0) => {
        if (!validBoards.includes(board)) return;
        const items = board === 'catalog' ? state.catalog : state.notes;
        const requestedId = Number(draftId || 0);
        const selectedId = items.some((item) => Number(item.id) === requestedId)
            ? requestedId
            : Number(items[0]?.id || 0);
        if (board === focusedBoard && activeDraftBoard === board && activeDraftId === selectedId) return;
        focusedBoard = board;
        activeDraftBoard = selectedId ? board : '';
        activeDraftId = selectedId;
        history.pushState({ ...(history.state || {}), websiteBoardFocus: board, websiteDraftBoard: activeDraftBoard, websiteDraftId: activeDraftId }, '', 'website_board.php');
        applyFocus();
    };

    const exitFocus = () => {
        if (validBoards.includes(history.state?.websiteBoardFocus)) history.back();
        else {
            focusedBoard = '';
            activeDraftBoard = '';
            activeDraftId = 0;
            history.replaceState({ ...(history.state || {}), websiteBoardFocus: '', websiteDraftBoard: '', websiteDraftId: 0 }, '', 'website_board.php');
            applyFocus();
        }
    };

    const updateCatalogEntry = (entry) => {
        const hydrated = hydrateCatalog(entry);
        const index = state.catalog.findIndex((item) => Number(item.id) === Number(hydrated.id));
        if (index >= 0) state.catalog[index] = hydrated; else state.catalog.unshift(hydrated);
        sortCatalogLikeArtworks();
        renderBoards();
    };

    const updateNote = (note) => {
        const hydrated = hydrateNote(note);
        const index = state.notes.findIndex((item) => Number(item.id) === Number(hydrated.id));
        if (index >= 0) state.notes[index] = hydrated; else state.notes.unshift(hydrated);
        renderBoards();
    };

    const sourceKeyFromItem = (item) => item?.dataset?.sourceKey || item?.querySelector?.('[data-source-key]')?.dataset?.sourceKey || '';

    const sourceForNote = (noteId, sourceKey) => {
        const note = state.notes.find((item) => Number(item.id) === Number(noteId));
        return state.sources.find((item) => String(item.key) === String(sourceKey))
            || note?.media?.find((item) => String(item.key) === String(sourceKey))
            || null;
    };

    const caretRangeAtPoint = (x, y) => {
        if (!Number.isFinite(x) || !Number.isFinite(y)) return null;
        if (typeof document.caretRangeFromPoint === 'function') return document.caretRangeFromPoint(x, y);
        if (typeof document.caretPositionFromPoint === 'function') {
            const position = document.caretPositionFromPoint(x, y);
            if (!position) return null;
            const range = document.createRange();
            range.setStart(position.offsetNode, position.offset);
            range.collapse(true);
            return range;
        }
        return null;
    };

    const insertImageIntoEditor = (noteId, source, point = null) => {
        const editor = editors.get(Number(noteId));
        if (!editor || !source) return false;
        const image = source.image || mediaUrl(source.file, 1200);
        const label = source.label || source.artworkTitle || 'Note image';
        if (editor.quill) {
            const root = editor.quill.root;
            const domRange = caretRangeAtPoint(Number(point?.x), Number(point?.y));
            if (domRange && root.contains(domRange.startContainer)) {
                const selection = window.getSelection();
                selection?.removeAllRanges();
                selection?.addRange(domRange);
                root.focus({ preventScroll: true });
            }
            const selection = editor.quill.getSelection();
            const index = Math.max(0, selection?.index ?? editor.quill.getLength() - 1);
            editor.quill.insertEmbed(index, 'image', image, 'user');
            const [leaf] = editor.quill.getLeaf(index);
            if (leaf?.domNode) {
                leaf.domNode.setAttribute('alt', label);
                leaf.domNode.setAttribute('data-source-key', source.key || '');
            }
            editor.quill.insertText(index + 1, '\n', 'user');
            editor.quill.setSelection(index + 2, 0, 'silent');
            return true;
        }

        const img = document.createElement('img');
        img.src = image;
        img.alt = label;
        img.dataset.sourceKey = source.key || '';
        const paragraph = document.createElement('p');
        paragraph.append(img);
        const domRange = caretRangeAtPoint(Number(point?.x), Number(point?.y));
        if (domRange && editor.node.contains(domRange.startContainer)) domRange.insertNode(paragraph);
        else editor.node.append(paragraph);
        editor.node.focus({ preventScroll: true });
        return true;
    };

    const handleCatalogDrop = async (event) => {
        const key = sourceKeyFromItem(event.item);
        const alreadyPublished = event.item?.dataset?.sourcePublished === '1';
        event.item?.remove();
        if (!key) return;
        if (alreadyPublished) {
            showToast('This artwork is already published; you can use it in Studio Notes.', true);
            renderBoards();
            return;
        }
        try {
            const entry = await post('catalog_add', { sourceKey: key });
            updateCatalogEntry(entry);
            showToast('The artwork was added to the Catalog.');
        } catch (error) { showToast(error.message, true); renderBoards(); }
    };

    const handleNewNoteDrop = async (event) => {
        const key = sourceKeyFromItem(event.item);
        event.item?.remove();
        if (!key) return;
        try {
            const note = await post('note_create', { sourceKey: key });
            updateNote(note);
            showToast('A Studio Note was created from the selected image.');
        } catch (error) { showToast(error.message, true); renderBoards(); }
    };

    const initializeDropSortables = () => {
        catalogSortable?.destroy(); catalogSortable = null;
        noteDropSortable?.destroy(); noteDropSortable = null;
        mediaSortables.forEach((sortable) => sortable.destroy()); mediaSortables = [];
        if (typeof window.Sortable !== 'function') return;

        const base = {
            group: { name: 'website-assets', pull: false, put: true },
            animation: 190,
            easing: 'cubic-bezier(.2,.7,.2,1)',
            ghostClass: 'wbb-sortable-ghost',
            chosenClass: 'wbb-sortable-chosen',
            dragClass: 'wbb-sortable-drag',
            forceFallback: false,
            fallbackTolerance: 4,
            swapThreshold: .58,
        };
        const catalogDrop = document.querySelector('[data-board="catalog"]');
        if (catalogDrop) catalogSortable = window.Sortable.create(catalogDrop, {
            ...base,
            sort: false,
            draggable: '[data-source-card]',
            onAdd: handleCatalogDrop,
        });
        const noteDrop = document.querySelector('[data-board="notes"]');
        if (noteDrop) noteDropSortable = window.Sortable.create(noteDrop, {
            ...base,
            sort: false,
            draggable: '[data-source-card]',
            onAdd: handleNewNoteDrop,
        });

        if (focusedBoard === 'notes') {
            document.querySelectorAll('[data-note-card]').forEach((card) => {
                const noteId = Number(card.dataset.id || 0);
                const mediaList = card.querySelector('[data-note-media-list]');
                const editorDrop = card.querySelector('[data-note-editor-drop]');
                let editorDropPoint = null;
                const add = async (event, insertInEditor = false) => {
                    const key = sourceKeyFromItem(event.item);
                    event.item?.remove();
                    if (!key) return;
                    try {
                        const attached = hydrateNote(await post('note_add_media', { id: noteId, sourceKey: key }));
                        if (insertInEditor) {
                            const source = attached.media?.find((item) => String(item.key) === String(key)) || sourceForNote(noteId, key);
                            const original = event.originalEvent;
                            const point = original && Number.isFinite(original.clientX)
                                ? { x: original.clientX, y: original.clientY }
                                : editorDropPoint;
                            if (!insertImageIntoEditor(noteId, source, point)) throw new Error('No se pudo insertar la imagen en el editor.');
                        }
                        const saved = await post('note_save', noteDraft(card));
                        updateNote(saved);
                        showToast(insertInEditor ? 'Image inserted into the essay.' : 'Image added to the note.');
                    }
                    catch (error) { showToast(error.message, true); renderBoards(); }
                    finally { editorDrop?.classList.remove('is-drag-over'); editorDropPoint = null; }
                };
                if (editorDrop) mediaSortables.push(window.Sortable.create(editorDrop, {
                    ...base,
                    sort: false,
                    draggable: '[data-source-card], [data-note-media-item]',
                    onMove: (event, originalEvent) => {
                        if (originalEvent && Number.isFinite(originalEvent.clientX)) editorDropPoint = { x: originalEvent.clientX, y: originalEvent.clientY };
                        editorDrop.classList.add('is-drag-over');
                        return true;
                    },
                    onAdd: (event) => add(event, true),
                    onEnd: () => { editorDrop.classList.remove('is-drag-over'); editorDropPoint = null; },
                }));
                if (mediaList) mediaSortables.push(window.Sortable.create(mediaList, {
                    ...base,
                    group: { name: 'website-assets', pull: 'clone', put: true },
                    draggable: '[data-note-media-item]',
                    onAdd: (event) => add(event, false),
                    onUpdate: async () => {
                        const keys = Array.from(mediaList.querySelectorAll('[data-note-media-item]')).map((item) => item.dataset.sourceKey || '');
                        try {
                            await post('note_save', noteDraft(card));
                            updateNote(await post('note_reorder_media', { id: noteId, keys }));
                        }
                        catch (error) { showToast(error.message, true); renderBoards(); }
                    },
                }));
            });
        }
    };

    const initializeSourceSortable = () => {
        if (typeof window.Sortable !== 'function') return;
        const rail = document.querySelector('[data-source-rail]');
        if (!rail) return;
        window.Sortable.create(rail, {
            group: { name: 'website-assets', pull: 'clone', put: false },
            sort: false,
            animation: 190,
            draggable: '[data-source-card]:not([hidden])',
            ghostClass: 'wbb-sortable-ghost',
            chosenClass: 'wbb-sortable-chosen',
            dragClass: 'wbb-sortable-drag',
            forceFallback: false,
            fallbackTolerance: 4,
            fallbackClass: 'wbb-drag-clone',
            removeCloneOnHide: true,
        });
    };

    const catalogFields = (card) => {
        const fields = {};
        card.querySelectorAll('[data-catalog-field]').forEach((field) => { fields[field.dataset.catalogField] = field.value; });
        return fields;
    };

    const saveCatalogCard = async (card) => {
        const id = Number(card.dataset.id || 0);
        const entry = await post('catalog_save', { id, fields: catalogFields(card) });
        updateCatalogEntry(entry);
        return entry;
    };

    const noteDraft = (card) => {
        const id = Number(card.dataset.id || 0);
        return {
            id,
            title: card.querySelector('[data-note-title]')?.value || '',
            objective: editors.get(id)?.html() || '',
        };
    };

    document.addEventListener('click', async (event) => {
        const focus = event.target.closest('[data-focus-board]');
        if (focus) { enterFocus(focus.dataset.focusBoard || ''); return; }
        if (event.target.closest('[data-exit-board-focus]')) { exitFocus(); return; }
        const scroll = event.target.closest('[data-scroll-source]');
        if (scroll) {
            document.querySelector('[data-source-rail]')?.scrollBy({ left: Number(scroll.dataset.scrollSource || 1) * 560, behavior: 'smooth' });
            return;
        }

        const catalogCard = event.target.closest('[data-catalog-card]');
        if (catalogCard && event.target.closest('[data-catalog-save]')) {
            try { await saveCatalogCard(catalogCard); showToast('Page saved.'); } catch (error) { showToast(error.message, true); }
            return;
        }
        const catalogAction = event.target.closest('[data-catalog-action]');
        if (catalogCard && catalogAction) {
            const action = catalogAction.dataset.catalogAction || '';
            if (action === 'delete' && !window.confirm('Remove this artwork from the board and website catalog?')) return;
            try {
                if (action === 'publish') await saveCatalogCard(catalogCard);
                const result = await post(`catalog_${action}`, { id: Number(catalogCard.dataset.id || 0) });
                if (result && result.status !== 'published') updateCatalogEntry(result);
                else if (result) {
                    state.catalog = state.catalog.filter((entry) => Number(entry.id) !== Number(catalogCard.dataset.id));
                    const sourceCard = document.querySelector(`[data-source-key="artwork:${Number(result.artworkId || 0)}"]`);
                    if (sourceCard) {
                        sourceCard.dataset.sourcePublished = '1';
                        sourceCard.classList.remove('is-catalog-eligible');
                        sourceCard.querySelector('.wbb-source-state')?.remove();
                    }
                    renderBoards();
                    const hasUnpublishedArtwork = Array.from(document.querySelectorAll('[data-source-card][data-source-type="artwork"]'))
                        .some((card) => card.dataset.sourcePublished !== '1');
                    if (!state.catalog.length && !hasUnpublishedArtwork) {
                        document.querySelector('[data-board="catalog"]')?.remove();
                        document.querySelector('[data-website-boards]')?.classList.add('has-single-board');
                        focusedBoard = focusedBoard === 'catalog' ? '' : focusedBoard;
                        sourceFilter = preferredNotesFilter();
                        noteFilter = sourceFilter;
                        applyFocus();
                    }
                }
                else { state.catalog = state.catalog.filter((entry) => Number(entry.id) !== Number(catalogCard.dataset.id)); renderBoards(); }
                showToast(action === 'publish' ? 'Artwork published on the website.' : 'Catalog updated.');
            } catch (error) { showToast(error.message, true); }
            return;
        }

        const noteCard = event.target.closest('[data-note-card]');
        if (noteCard && event.target.closest('[data-note-save]')) {
            try { const draft = noteDraft(noteCard); updateNote(await post('note_save', draft)); showToast('Draft saved.'); }
            catch (error) { showToast(error.message, true); }
            return;
        }
        const noteAction = event.target.closest('[data-note-action]');
        if (noteCard && noteAction) {
            const action = noteAction.dataset.noteAction || '';
            if (action === 'delete' && !window.confirm('Delete this Studio Note?')) return;
            try {
                const id = Number(noteCard.dataset.id || 0);
                if (action === 'publish') updateNote(await post('note_save', noteDraft(noteCard)));
                const result = await post(`note_${action}`, { id });
                if (result && result.status !== 'published') updateNote(result);
                else if (result) { state.notes = state.notes.filter((note) => Number(note.id) !== id); renderBoards(); }
                else { state.notes = state.notes.filter((note) => Number(note.id) !== id); renderBoards(); }
                showToast(action === 'publish' ? 'Note published on the website.' : 'Studio Notes updated.');
            } catch (error) { showToast(error.message, true); }
            return;
        }
        const removeMedia = event.target.closest('[data-remove-note-media]');
        if (noteCard && removeMedia) {
            const item = removeMedia.closest('[data-note-media-item]');
            try {
                await post('note_save', noteDraft(noteCard));
                updateNote(await post('note_remove_media', { id: Number(noteCard.dataset.id || 0), sourceKey: item?.dataset.sourceKey || '' }));
                showToast('Image removed from the note.');
            }
            catch (error) { showToast(error.message, true); }
            return;
        }

        if (!event.target.closest('button, input, textarea, select, a, [contenteditable="true"], .ql-toolbar')) {
            if (catalogCard) { enterFocus('catalog', Number(catalogCard.dataset.id || 0)); return; }
            if (noteCard) { enterFocus('notes', Number(noteCard.dataset.id || 0)); }
        }
    });

    document.querySelector('[data-source-type-select]')?.addEventListener('change', (event) => {
        const type = event.target.value || '';
        if (!validTypes.includes(type) || (focusedBoard === 'catalog' && type !== 'artwork')) return;
        sourceFilter = type;
        if (focusedBoard === 'notes' || !hasCatalogBoard()) noteFilter = type;
        applySourceFilter();
    });

    window.addEventListener('popstate', (event) => {
        focusedBoard = validBoards.includes(event.state?.websiteBoardFocus) ? event.state.websiteBoardFocus : '';
        activeDraftBoard = validBoards.includes(event.state?.websiteDraftBoard) ? event.state.websiteDraftBoard : '';
        activeDraftId = Number(event.state?.websiteDraftId || 0);
        applyFocus();
    });

    if (focusedBoard) {
        const initialItems = focusedBoard === 'catalog' ? state.catalog : state.notes;
        activeDraftBoard = initialItems.length ? focusedBoard : '';
        activeDraftId = Number(initialItems[0]?.id || 0);
    }
    history.replaceState({ ...(history.state || {}), websiteBoardFocus: focusedBoard, websiteDraftBoard: activeDraftBoard, websiteDraftId: activeDraftId }, '', location.href);
    applyFocus();
    initializeSourceSortable();
})();

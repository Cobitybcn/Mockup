(() => {
    'use strict';

    const root = document.querySelector('[data-studio-references-lab]');
    if (!root) return;

    const library = root.querySelector('[data-reference-library]');
    const categoryFilter = root.querySelector('[data-category-filter]');
    const libraryCount = root.querySelector('[data-library-count]');
    const boardTotal = root.querySelector('[data-board-total]');
    const decisionBlocks = Array.from(root.querySelectorAll('[data-category-decision]'));
    const dropZones = Array.from(root.querySelectorAll('[data-drop-zone]'));
    const savedSets = root.querySelector('[data-saved-sets]');
    const saveSetButton = root.querySelector('[data-save-reference-set]');
    const setName = root.querySelector('[data-reference-set-name]');
    const setDescription = root.querySelector('[data-reference-set-description]');
    const setColor = root.querySelector('[data-reference-set-color]');
    const toast = root.querySelector('[data-lab-toast]');
    const uploadDropSurface = root.querySelector('[data-reference-external-drop]');
    const uploadFile = root.querySelector('[data-reference-upload-file]');
    const chooseReferenceButton = root.querySelector('[data-choose-reference]');
    const externalDropCue = root.querySelector('[data-external-drop-cue]');
    const externalDropLabel = root.querySelector('[data-external-drop-label]');
    const libraryEmpty = root.querySelector('[data-library-empty]');
    const generationArtwork = root.querySelector('[data-generation-artwork]');
    const generationSet = root.querySelector('[data-generation-set]');
    const generateButton = root.querySelector('[data-generate-visual-dna]');
    const generationState = root.querySelector('[data-generation-state]');
    const generationImage = root.querySelector('[data-generation-image]');
    const generationPlaceholder = root.querySelector('[data-generation-placeholder]');
    const saveEndpoint = root.dataset.saveEndpoint || 'reference_set_save.php';
    const uploadEndpoint = root.dataset.uploadEndpoint || 'visual_dna_reference_upload.php';
    const importEndpoint = root.dataset.importEndpoint || 'visual_dna_reference_import.php';
    const generateEndpoint = root.dataset.generateEndpoint || 'visual_dna_generate.php';
    const statusEndpoint = root.dataset.statusEndpoint || 'visual_dna_generation_status.php';
    const csrf = root.dataset.csrf || '';

    let selectedLibraryCard = null;
    let draggedCard = null;
    let draggedFromLibrary = false;
    let toastTimer = 0;
    let instanceCounter = 1;
    let externalDragDepth = 0;
    let referenceImportInProgress = false;

    const allowedImageTypes = ['image/jpeg', 'image/png', 'image/webp'];

    const referenceCards = (scope = root) => Array.from(scope.querySelectorAll('[data-reference-card]'));

    const announce = (message) => {
        if (!toast) return;
        window.clearTimeout(toastTimer);
        toast.textContent = message;
        toast.classList.add('is-visible');
        toastTimer = window.setTimeout(() => toast.classList.remove('is-visible'), 2600);
    };

    const setSelectedCard = (card) => {
        if (selectedLibraryCard) selectedLibraryCard.classList.remove('is-selected');
        selectedLibraryCard = card;
        if (!selectedLibraryCard) return;
        selectedLibraryCard.classList.add('is-selected');
        announce(`${selectedLibraryCard.dataset.title || 'Reference'} selected. Focus a board zone and press Enter to assign it.`);
    };

    const updateLibraryCount = () => {
        if (!libraryCount || !library) return;
        const visible = referenceCards(library).filter((card) => !card.hidden).length;
        libraryCount.textContent = `${visible} ${visible === 1 ? 'reference' : 'references'}`;
        if (libraryEmpty) libraryEmpty.hidden = referenceCards(library).length > 0;
    };

    const updateBoardCounts = () => {
        let total = 0;
        dropZones.forEach((zone) => {
            const cards = referenceCards(zone);
            const counter = zone.querySelector('[data-zone-count]');
            const empty = zone.querySelector('[data-empty-state]');
            if (counter) counter.textContent = String(cards.length);
            if (empty) empty.hidden = cards.length > 0;
            total += cards.length;
        });
        if (boardTotal) boardTotal.textContent = `${total} assigned`;
    };

    const updateCardIdentity = (card, title, id) => {
        card.dataset.title = title;
        card.dataset.referenceId = id;
        card.setAttribute('aria-label', `Select ${title} for assignment`);
        const titleNode = card.querySelector('.srl-card-copy strong');
        const image = card.querySelector('img');
        if (titleNode) titleNode.textContent = title;
        if (image) image.alt = `Visual reference: ${title}`;
        card.querySelectorAll('[data-card-action]').forEach((button) => {
            const action = button.dataset.cardAction || 'use';
            button.setAttribute('aria-label', `${action.charAt(0).toUpperCase()}${action.slice(1)} ${title}`);
        });
    };

    const cloneCard = (source, context) => {
        const clone = source.cloneNode(true);
        const title = source.dataset.title || 'Visual Reference';
        const id = `${source.dataset.referenceId || 'reference'}-${context}-${instanceCounter++}`;
        clone.classList.remove('is-selected', 'is-dragging');
        clone.removeAttribute('hidden');
        clone.dataset.cardContext = context;
        updateCardIdentity(clone, title, id);
        if (context === 'board' && !clone.querySelector('[data-card-action="delete"]')) {
            let cluster = clone.querySelector('.media-thumb-action-cluster');
            if (!cluster) {
                cluster = document.createElement('span');
                cluster.className = 'media-thumb-action-cluster';
                clone.querySelector('.srl-card-image')?.append(cluster);
            }
            const remove = document.createElement('button');
            remove.className = 'media-icon-button is-danger srl-image-action';
            remove.type = 'button';
            remove.dataset.cardAction = 'delete';
            remove.title = 'Remove from board';
            remove.setAttribute('aria-label', `Remove ${title} from board`);
            remove.innerHTML = '<svg class="media-action-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 7h14M9 7V4h6v3M8 10v7M12 10v7M16 10v7M7 7l1 14h8l1-14"/></svg>';
            cluster.append(remove);
        }
        return clone;
    };

    const duplicateCard = (card) => {
        const title = `${card.dataset.title || 'Demo Reference'} Copy`;
        const duplicate = cloneCard(card, card.closest('[data-reference-library]') ? 'library' : 'board');
        updateCardIdentity(duplicate, title, duplicate.dataset.referenceId || `reference-${instanceCounter++}`);
        const favorite = duplicate.querySelector('[data-card-action="favorite"]');
        if (favorite) {
            favorite.classList.remove('is-favorite');
            favorite.setAttribute('aria-pressed', 'false');
        }
        card.insertAdjacentElement('afterend', duplicate);
        updateLibraryCount();
        updateBoardCounts();
        announce(`${title} created for this demo session.`);
    };

    const deleteCard = (card) => {
        const title = card.dataset.title || 'Reference';
        if (selectedLibraryCard === card) selectedLibraryCard = null;
        card.remove();
        updateLibraryCount();
        updateBoardCounts();
        announce(`${title} removed from the current board.`);
    };

    const applyCategory = (category) => {
        if (!library) return;
        referenceCards(library).forEach((card) => {
            card.hidden = category !== 'all' && card.dataset.category !== category;
        });
        decisionBlocks.forEach((block) => {
            block.setAttribute('aria-pressed', block.dataset.categoryDecision === category ? 'true' : 'false');
        });
        if (categoryFilter && categoryFilter.value !== category) categoryFilter.value = category;
        updateLibraryCount();
        if (category !== 'all') announce(`${category} references are active.`);
    };

    const dropPosition = (container, pointerX) => {
        const candidates = referenceCards(container).filter((card) => card !== draggedCard);
        return candidates.find((card) => {
            const bounds = card.getBoundingClientRect();
            return pointerX < bounds.left + bounds.width / 2;
        }) || null;
    };

    const assignToZone = (source, zone, before = null) => {
        const content = zone.querySelector('[data-zone-content]');
        if (!content) return;
        const card = source.closest('[data-reference-library]') ? cloneCard(source, 'board') : source;
        content.insertBefore(card, before);
        updateBoardCounts();
        announce(`${card.dataset.title || 'Reference'} assigned to ${zone.querySelector('h3')?.textContent || 'the board'}.`);
    };

    root.addEventListener('click', (event) => {
        const action = event.target.closest('[data-card-action]');
        if (action) {
            event.preventDefault();
            event.stopPropagation();
            const card = action.closest('[data-reference-card]');
            if (!card) return;
            if (action.dataset.cardAction === 'favorite') {
                const active = !action.classList.contains('is-favorite');
                action.classList.toggle('is-favorite', active);
                action.setAttribute('aria-pressed', active ? 'true' : 'false');
                announce(`${card.dataset.title || 'Reference'} ${active ? 'favorited' : 'removed from favorites'}.`);
            } else if (action.dataset.cardAction === 'duplicate') {
                duplicateCard(card);
            } else if (action.dataset.cardAction === 'delete') {
                deleteCard(card);
            }
            return;
        }

        const card = event.target.closest('[data-reference-card]');
        if (card && card.closest('[data-reference-library]')) {
            setSelectedCard(card);
            return;
        }

        const decision = event.target.closest('[data-category-decision]');
        if (decision) {
            applyCategory(decision.dataset.categoryDecision || 'all');
            return;
        }

        const openSet = event.target.closest('[data-open-saved-set]');
        if (openSet) {
            root.querySelectorAll('[data-open-saved-set]').forEach((button) => button.removeAttribute('aria-pressed'));
            openSet.setAttribute('aria-pressed', 'true');
            const setCard = openSet.closest('[data-reference-set-id]');
            const setId = setCard?.dataset.referenceSetId || '';
            if (setCard?.dataset.hasRealReferences !== '1') {
                announce(`${openSet.textContent.trim()} is an example only. Upload real references and save a new Visual DNA to generate.`);
                return;
            }
            if (generationSet && Array.from(generationSet.options).some((option) => option.value === setId)) {
                generationSet.value = setId;
            }
            announce(`${openSet.textContent.trim()} selected as the working Visual DNA.`);
        }
    });

    root.addEventListener('keydown', (event) => {
        const card = event.target.closest('[data-reference-card]');
        if (card && card.closest('[data-reference-library]') && (event.key === 'Enter' || event.key === ' ')) {
            event.preventDefault();
            setSelectedCard(card);
            return;
        }

        const zone = event.target.closest('[data-drop-zone]');
        if (zone && event.target === zone && (event.key === 'Enter' || event.key === ' ')) {
            event.preventDefault();
            if (!selectedLibraryCard || !document.body.contains(selectedLibraryCard)) {
                announce('Select a reference from the library first.');
                return;
            }
            assignToZone(selectedLibraryCard, zone);
        }
    });

    root.addEventListener('dragstart', (event) => {
        const card = event.target.closest('[data-reference-card]');
        if (!card || event.target.closest('button')) {
            event.preventDefault();
            return;
        }
        draggedCard = card;
        draggedFromLibrary = Boolean(card.closest('[data-reference-library]'));
        card.classList.add('is-dragging');
        if (event.dataTransfer) {
            event.dataTransfer.effectAllowed = draggedFromLibrary ? 'copy' : 'move';
            event.dataTransfer.setData('text/plain', card.dataset.referenceId || 'demo-reference');
        }
    });

    root.addEventListener('dragend', () => {
        if (draggedCard) draggedCard.classList.remove('is-dragging');
        draggedCard = null;
        draggedFromLibrary = false;
        dropZones.forEach((zone) => zone.classList.remove('is-drop-target'));
    });

    dropZones.forEach((zone) => {
        zone.addEventListener('dragenter', (event) => {
            if (!draggedCard && !hasExternalPayload(event.dataTransfer)) return;
            event.preventDefault();
            zone.classList.add('is-drop-target');
        });

        zone.addEventListener('dragover', (event) => {
            const acceptsExternalImage = hasExternalPayload(event.dataTransfer);
            if (!draggedCard && !acceptsExternalImage) return;
            event.preventDefault();
            zone.classList.add('is-drop-target');
            if (event.dataTransfer) event.dataTransfer.dropEffect = draggedCard && !draggedFromLibrary && !acceptsExternalImage ? 'move' : 'copy';
        });

        zone.addEventListener('dragleave', (event) => {
            if (!zone.contains(event.relatedTarget)) zone.classList.remove('is-drop-target');
        });

        zone.addEventListener('drop', (event) => {
            event.preventDefault();
            if (draggedCard) {
                zone.classList.remove('is-drop-target');
                const content = zone.querySelector('[data-zone-content]');
                const before = content ? dropPosition(content, event.clientX) : null;
                assignToZone(draggedCard, zone, before);
                return;
            }
            if (hasExternalPayload(event.dataTransfer)) {
                void importExternalTransfer(event.dataTransfer, zone);
                return;
            }
            zone.classList.remove('is-drop-target');
        });
    });

    categoryFilter?.addEventListener('change', () => applyCategory(categoryFilter.value || 'all'));

    root.querySelectorAll('[data-carousel-direction]').forEach((button) => {
        button.addEventListener('click', () => {
            if (!library) return;
            const direction = Number(button.dataset.carouselDirection || 0);
            library.scrollBy({ left: direction * Math.max(300, library.clientWidth * .72), behavior: 'smooth' });
        });
    });

    const renderSavedSet = (set) => {
        if (!savedSets || !set) return null;
        const article = document.createElement('article');
        article.className = 'srl-saved-set';
        article.dataset.savedSet = '';
        article.dataset.referenceSetId = String(set.id || '');
        article.dataset.hasRealReferences = Array.isArray(set.items) && set.items.some((item) => Number(item.reference_asset_id || 0) > 0) ? '1' : '0';

        const decision = document.createElement('button');
        decision.type = 'button';
        decision.className = `srl-set-decision srl-tone--${set.identifier_color || 'rose'}`;
        decision.dataset.openSavedSet = '';
        decision.setAttribute('aria-label', `Open ${set.name || 'Reference Set'}`);
        const label = document.createElement('span');
        label.textContent = String(set.name || 'Reference Set').toUpperCase();
        decision.append(label);

        const preview = document.createElement('div');
        preview.className = 'srl-set-preview';
        preview.setAttribute('aria-hidden', 'true');
        (Array.isArray(set.items) ? set.items : []).slice(0, 3).forEach((item) => {
            const image = document.createElement('img');
            image.src = item.thumbnail || '';
            image.alt = '';
            image.draggable = false;
            preview.append(image);
        });

        const count = document.createElement('span');
        count.className = 'srl-counter';
        const itemCount = Array.isArray(set.items) ? set.items.length : 0;
        count.textContent = `${itemCount} ${itemCount === 1 ? 'reference' : 'references'}`;

        article.append(decision, preview, count);
        savedSets.prepend(article);
        if (generationSet && Array.isArray(set.items) && set.items.some((item) => Number(item.reference_asset_id || 0) > 0)) {
            const option = document.createElement('option');
            option.value = String(set.id || '');
            option.textContent = String(set.name || 'Visual DNA');
            generationSet.append(option);
            generationSet.value = option.value;
            if (generateButton && generationArtwork?.options.length > 1) generateButton.disabled = false;
        }
        return article;
    };

    const renderReferenceCard = (reference) => {
        if (!library || !reference) return null;
        const title = String(reference.title || 'Visual reference');
        const category = String(reference.category || 'Other');
        const article = document.createElement('article');
        article.className = 'srl-reference-card';
        article.draggable = true;
        article.tabIndex = 0;
        article.setAttribute('role', 'button');
        article.setAttribute('aria-label', `Select ${title} for assignment`);
        article.dataset.referenceCard = '';
        article.dataset.referenceId = String(reference.reference_key || `asset:${reference.id}`);
        article.dataset.sourceReferenceId = String(reference.reference_key || `asset:${reference.id}`);
        article.dataset.referenceAssetId = String(reference.id || '');
        article.dataset.persisted = '1';
        article.dataset.title = title;
        article.dataset.category = category;

        const imageWrap = document.createElement('div');
        imageWrap.className = 'srl-card-image';
        const image = document.createElement('img');
        image.src = String(reference.image || '');
        image.alt = `Visual reference: ${title}`;
        image.draggable = false;
        const favorite = document.createElement('button');
        favorite.className = 'media-icon-button media-thumb-action media-thumb-action--left srl-image-action';
        favorite.type = 'button';
        favorite.dataset.cardAction = 'favorite';
        favorite.setAttribute('aria-label', `Favorite ${title}`);
        favorite.setAttribute('aria-pressed', 'false');
        favorite.title = 'Favorite';
        favorite.innerHTML = '<svg class="media-action-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10Z"/></svg>';
        imageWrap.append(image, favorite);

        const copy = document.createElement('div');
        copy.className = 'srl-card-copy';
        const text = document.createElement('span');
        const strong = document.createElement('strong');
        strong.textContent = title;
        const small = document.createElement('small');
        small.textContent = category;
        text.append(strong, small);
        const badge = document.createElement('em');
        badge.textContent = 'YOUR REFERENCE';
        copy.append(text, badge);
        article.append(imageWrap, copy);
        library.append(article);
        return article;
    };

    const suggestedTitle = (name) => String(name || '')
        .split(/[?#]/)[0]
        .split('/')
        .pop()
        .replace(/\.(jpe?g|png|webp)$/i, '')
        .replace(/[-_]+/g, ' ')
        .trim();

    const isAcceptedImageFile = (file) => {
        if (!(file instanceof File)) return false;
        if (allowedImageTypes.includes(String(file.type || '').toLowerCase())) return true;
        return /\.(jpe?g|png|webp)$/i.test(file.name || '');
    };

    const activeImportCategory = (targetZone = null) => {
        const zoneCategory = targetZone?.dataset.importCategory || '';
        if (zoneCategory) return zoneCategory;
        const filteredCategory = categoryFilter?.value || 'all';
        return filteredCategory !== 'all' ? filteredCategory : 'Other';
    };

    const setExternalDropState = (visible, label = 'DROP IMAGE TO ADD REFERENCE') => {
        uploadDropSurface?.classList.toggle('is-external-drop-target', visible);
        if (externalDropLabel) externalDropLabel.textContent = label;
        if (externalDropCue) externalDropCue.hidden = !visible;
    };

    const finishReferenceImport = (reference, targetZone = null) => {
        const card = renderReferenceCard(reference);
        applyCategory('all');
        updateLibraryCount();
        if (targetZone && card) {
            assignToZone(card, targetZone);
        } else {
            card?.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
            announce(`${reference.title} is ready to use in Visual DNA.`);
        }
    };

    const uploadReferenceFile = async (file, targetZone = null) => {
        if (!isAcceptedImageFile(file)) {
            externalDragDepth = 0;
            setExternalDropState(false);
            targetZone?.classList.remove('is-drop-target');
            announce('Use a JPG, PNG, or WebP image.');
            return;
        }
        if (file.size > 20 * 1024 * 1024) {
            externalDragDepth = 0;
            setExternalDropState(false);
            targetZone?.classList.remove('is-drop-target');
            announce('Reference images must be smaller than 20 MB.');
            return;
        }
        if (referenceImportInProgress) {
            announce('Wait for the current reference to finish importing.');
            return;
        }
        referenceImportInProgress = true;
        if (targetZone) targetZone.classList.add('is-drop-target');
        else setExternalDropState(true, 'ADDING REFERENCE…');
        try {
            const formData = new FormData();
            formData.set('csrf', csrf);
            formData.set('title', suggestedTitle(file.name));
            formData.set('category', activeImportCategory(targetZone));
            formData.set('reference_image', file, file.name || 'reference.png');
            const response = await fetch(uploadEndpoint, {
                method: 'POST',
                headers: { 'Accept': 'application/json' },
                body: formData
            });
            const result = await response.json().catch(() => ({}));
            if (!response.ok || !result.ok) throw new Error(result.error || 'The reference could not be uploaded.');
            finishReferenceImport(result.reference, targetZone);
        } catch (error) {
            announce(error instanceof Error ? error.message : 'The reference could not be uploaded.');
        } finally {
            referenceImportInProgress = false;
            setExternalDropState(false);
            targetZone?.classList.remove('is-drop-target');
            if (uploadFile) uploadFile.value = '';
        }
    };

    const importReferenceUrl = async (url, targetZone = null) => {
        const normalized = String(url || '').trim();
        if (!/^https?:\/\//i.test(normalized)) {
            externalDragDepth = 0;
            setExternalDropState(false);
            targetZone?.classList.remove('is-drop-target');
            announce('That browser did not provide an importable image file or URL.');
            return;
        }
        if (referenceImportInProgress) {
            announce('Wait for the current reference to finish importing.');
            return;
        }
        referenceImportInProgress = true;
        if (targetZone) targetZone.classList.add('is-drop-target');
        else setExternalDropState(true, 'IMPORTING REFERENCE…');
        try {
            const response = await fetch(importEndpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({
                    csrf,
                    url: normalized,
                    title: suggestedTitle(normalized),
                    category: activeImportCategory(targetZone)
                })
            });
            const result = await response.json().catch(() => ({}));
            if (!response.ok || !result.ok) throw new Error(result.error || 'The reference could not be imported.');
            finishReferenceImport(result.reference, targetZone);
        } catch (error) {
            announce(error instanceof Error ? error.message : 'The reference could not be imported.');
        } finally {
            referenceImportInProgress = false;
            setExternalDropState(false);
            targetZone?.classList.remove('is-drop-target');
        }
    };

    const externalUrlFromTransfer = (transfer) => {
        const html = String(transfer?.getData('text/html') || '');
        if (html) {
            const documentFragment = new DOMParser().parseFromString(html, 'text/html');
            const imageSource = documentFragment.querySelector('img')?.getAttribute('src') || '';
            if (imageSource) return imageSource;
        }
        const uriList = String(transfer?.getData('text/uri-list') || '')
            .split(/\r?\n/)
            .map((value) => value.trim())
            .find((value) => value !== '' && !value.startsWith('#'));
        if (uriList) return uriList;

        const downloadUrl = String(transfer?.getData('DownloadURL') || '');
        if (downloadUrl) {
            const match = downloadUrl.match(/https?:\/\/.*$/i);
            if (match) return match[0];
        }
        const firefoxUrl = String(transfer?.getData('text/x-moz-url') || '').split(/\r?\n/)[0].trim();
        if (firefoxUrl) return firefoxUrl;

        const plain = String(transfer?.getData('text/plain') || '').trim();
        return /^https?:\/\//i.test(plain) || /^data:image\//i.test(plain) ? plain : '';
    };

    const fileFromDataUrl = (dataUrl) => {
        const match = String(dataUrl || '').match(/^data:(image\/(?:jpeg|png|webp));base64,(.+)$/i);
        if (!match) return null;
        try {
            const bytes = window.atob(match[2]);
            const buffer = new Uint8Array(bytes.length);
            for (let index = 0; index < bytes.length; index += 1) buffer[index] = bytes.charCodeAt(index);
            const extension = match[1].toLowerCase() === 'image/jpeg' ? 'jpg' : match[1].split('/')[1];
            return new File([buffer], `pasted-reference.${extension}`, { type: match[1].toLowerCase() });
        } catch (error) {
            return null;
        }
    };

    const hasExternalPayload = (transfer) => {
        if (draggedCard) return false;
        const types = Array.from(transfer?.types || []);
        return types.includes('Files') || types.includes('text/uri-list') || types.includes('text/html')
            || types.includes('DownloadURL') || types.includes('text/x-moz-url') || types.includes('text/plain');
    };

    const importExternalTransfer = async (transfer, targetZone = null) => {
        const file = Array.from(transfer?.files || []).find(isAcceptedImageFile);
        if (file) {
            await uploadReferenceFile(file, targetZone);
            return;
        }
        const externalUrl = externalUrlFromTransfer(transfer);
        const inlineFile = /^data:image\//i.test(externalUrl) ? fileFromDataUrl(externalUrl) : null;
        if (inlineFile) {
            await uploadReferenceFile(inlineFile, targetZone);
            return;
        }
        await importReferenceUrl(externalUrl, targetZone);
    };

    chooseReferenceButton?.addEventListener('click', () => uploadFile?.click());

    uploadFile?.addEventListener('change', () => {
        const file = uploadFile.files?.[0] || null;
        if (file) void uploadReferenceFile(file);
    });

    uploadDropSurface?.addEventListener('dragenter', (event) => {
        if (!hasExternalPayload(event.dataTransfer)) return;
        event.preventDefault();
        externalDragDepth += 1;
        setExternalDropState(true);
    });

    uploadDropSurface?.addEventListener('dragover', (event) => {
        if (!hasExternalPayload(event.dataTransfer)) return;
        event.preventDefault();
        if (event.dataTransfer) event.dataTransfer.dropEffect = 'copy';
        setExternalDropState(true);
    });

    uploadDropSurface?.addEventListener('dragleave', () => {
        externalDragDepth = Math.max(0, externalDragDepth - 1);
        if (externalDragDepth === 0 && !referenceImportInProgress) setExternalDropState(false);
    });

    uploadDropSurface?.addEventListener('drop', (event) => {
        if (!hasExternalPayload(event.dataTransfer)) return;
        event.preventDefault();
        externalDragDepth = 0;

        void importExternalTransfer(event.dataTransfer);
    });

    document.addEventListener('paste', (event) => {
        const target = event.target;
        const file = Array.from(event.clipboardData?.files || []).find(isAcceptedImageFile)
            || Array.from(event.clipboardData?.items || [])
                .map((item) => item.kind === 'file' ? item.getAsFile() : null)
                .find(isAcceptedImageFile);
        if (file) {
            event.preventDefault();
            void uploadReferenceFile(file);
            uploadDropSurface?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }
        if (target instanceof HTMLElement && (target.matches('input, textarea, select') || target.isContentEditable)) return;
        const externalUrl = externalUrlFromTransfer(event.clipboardData);
        const inlineFile = /^data:image\//i.test(externalUrl) ? fileFromDataUrl(externalUrl) : null;
        if (inlineFile || /^https?:\/\//i.test(externalUrl)) {
            event.preventDefault();
            if (inlineFile) void uploadReferenceFile(inlineFile);
            else void importReferenceUrl(externalUrl);
            uploadDropSurface?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    });

    const pollGeneration = async (jobId) => {
        try {
            const response = await fetch(`${statusEndpoint}?job_id=${encodeURIComponent(jobId)}`, {
                headers: { 'Accept': 'application/json' },
                cache: 'no-store'
            });
            const result = await response.json().catch(() => ({}));
            if (!response.ok || !result.ok) throw new Error(result.error || 'Generation status could not be read.');
            if (result.status === 'done' && result.result_url) {
                if (generationImage) {
                    generationImage.src = result.result_url;
                    generationImage.hidden = false;
                }
                if (generationPlaceholder) generationPlaceholder.hidden = true;
                if (generationState) generationState.textContent = `${result.reference_set_name || 'Visual DNA'} generated and saved.`;
                if (generateButton) generateButton.disabled = false;
                announce('Visual DNA test mockup generated and saved.');
                return;
            }
            if (result.error || ['error', 'failed', 'failed_enqueue'].includes(result.status)) {
                throw new Error(result.error || 'Visual DNA generation failed.');
            }
            if (generationState) generationState.textContent = result.status === 'processing'
                ? 'Gemini is composing the Visual DNA mockup…'
                : 'Visual DNA test is queued…';
            window.setTimeout(() => pollGeneration(jobId), 2200);
        } catch (error) {
            if (generationState) generationState.textContent = error instanceof Error ? error.message : 'Visual DNA generation failed.';
            if (generateButton) generateButton.disabled = false;
            announce(error instanceof Error ? error.message : 'Visual DNA generation failed.');
        }
    };

    generateButton?.addEventListener('click', async () => {
        const artworkId = generationArtwork?.value || '';
        const referenceSetId = generationSet?.value || '';
        if (!artworkId || !referenceSetId) {
            announce('Choose an artwork and a saved Visual DNA.');
            return;
        }
        generateButton.disabled = true;
        if (generationImage) generationImage.hidden = true;
        if (generationPlaceholder) generationPlaceholder.hidden = false;
        if (generationState) generationState.textContent = 'Registering the isolated Visual DNA test…';
        try {
            const idempotencyKey = window.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(16).slice(2)}`;
            const response = await fetch(generateEndpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ csrf, artwork_id: artworkId, reference_set_id: referenceSetId, idempotency_key: idempotencyKey })
            });
            const result = await response.json().catch(() => ({}));
            if (!response.ok || !result.ok) throw new Error(result.error || 'The Visual DNA test could not be started.');
            if (generationState) generationState.textContent = 'Visual DNA test is queued…';
            announce('Visual DNA test started in the isolated Gemini connection.');
            pollGeneration(result.job_id);
        } catch (error) {
            if (generationState) generationState.textContent = error instanceof Error ? error.message : 'The Visual DNA test could not be started.';
            generateButton.disabled = false;
            announce(error instanceof Error ? error.message : 'The Visual DNA test could not be started.');
        }
    });

    saveSetButton?.addEventListener('click', async () => {
        const cards = dropZones.flatMap((zone) => referenceCards(zone));
        if (!cards.length) {
            announce('Assign at least one reference before saving a set.');
            return;
        }
        const name = setName?.value.trim() || '';
        if (!name) {
            announce('Name the visual intention before saving.');
            setName?.focus();
            return;
        }

        saveSetButton.disabled = true;
        try {
            const response = await fetch(saveEndpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({
                    csrf,
                    name,
                    description: setDescription?.value.trim() || '',
                    identifier_color: setColor?.value || 'rose',
                    references: cards.map((card) => card.dataset.sourceReferenceId || card.dataset.referenceId || '')
                })
            });
            const result = await response.json().catch(() => ({}));
            if (!response.ok || !result.ok) {
                throw new Error(result.error || 'The Reference Set could not be saved.');
            }
            const article = renderSavedSet(result.reference_set);
            article?.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'start' });
            if (setName) setName.value = '';
            if (setDescription) setDescription.value = '';
            announce(`${result.reference_set.name} saved as reusable Visual DNA.`);
        } catch (error) {
            announce(error instanceof Error ? error.message : 'The Reference Set could not be saved.');
        } finally {
            saveSetButton.disabled = false;
        }
    });

    updateLibraryCount();
    updateBoardCounts();
})();

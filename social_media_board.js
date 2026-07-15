(() => {
    'use strict';

    const payloadNode = document.getElementById('social-board-mockups');
    if (!payloadNode) return;

    let mockups = [];
    try {
        mockups = JSON.parse(payloadNode.textContent || '[]');
    } catch (error) {
        mockups = [];
    }

    let boardConfig = {};
    try {
        boardConfig = JSON.parse(document.getElementById('social-board-config')?.textContent || '{}');
    } catch (error) {
        boardConfig = {};
    }
    const configuredDestinations = {
        website: String(boardConfig?.destinations?.website || ''),
        saatchi: String(boardConfig?.destinations?.saatchi || ''),
    };

    const mockupById = new Map(mockups.map((mockup) => [String(mockup.id), mockup]));
    const userId = document.body.dataset.socialBoardUser || 'guest';
    const storageKey = `artwork-mockups-social-board-v2:${userId}`;
    const legacyStorageKey = `artwork-mockups-social-board-v1:${userId}`;
    const publicationPlatforms = ['instagram', 'facebook'];
    const networkPlatforms = ['pinterest', ...publicationPlatforms];
    const groupLimits = { instagram: 10, facebook: 3 };
    const cards = Array.from(document.querySelectorAll('[data-catalog-card]'));
    const originalOrder = new Map(cards.map((card, index) => [card.dataset.mockupId, index]));
    let toastTimer = 0;
    let catalogSortable = null;
    let boardSortables = [];
    let deferredRenderTimer = 0;
    let pinterestBoards = [];
    let pinterestBoardsStatus = 'loading';
    let pendingPublishPayload = null;
    let focusedNetwork = networkPlatforms.includes(history.state?.socialMediaFocus)
        ? history.state.socialMediaFocus
        : '';

    const uniqueId = (platform) => `${platform}-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
    const validIds = (values, limit = Infinity) => (Array.isArray(values) ? values : [])
        .map(String)
        .filter((id, index, list) => mockupById.has(id) && list.indexOf(id) === index)
        .slice(0, limit);

    const defaultState = () => ({
        pinterest: [],
        publications: { instagram: [], facebook: [] },
        pinData: {},
        scheduled: { pinterest: {}, instagram: {}, facebook: {} },
        schedule: { date: '', time: '10:00', perPublication: false },
    });

    const normalizeGroup = (group, platform) => ({
        id: String(group?.id || uniqueId(platform)),
        items: validIds(group?.items, groupLimits[platform]),
        copy: String(group?.copy || ''),
        link: group?.link === 'saatchi' ? 'saatchi' : 'website',
        linkUrl: String(group?.linkUrl || configuredDestinations[group?.link === 'saatchi' ? 'saatchi' : 'website'] || ''),
        date: String(group?.date || ''),
        time: String(group?.time || ''),
    });

    const readState = () => {
        const fresh = defaultState();
        let saved = {};
        try {
            saved = JSON.parse(localStorage.getItem(storageKey) || 'null')
                || JSON.parse(localStorage.getItem(legacyStorageKey) || '{}');
        } catch (error) {
            return fresh;
        }

        fresh.pinterest = validIds(saved.pinterest);
        fresh.pinData = saved.pinData && typeof saved.pinData === 'object' ? saved.pinData : {};
        networkPlatforms.forEach((platform) => {
            fresh.scheduled[platform] = saved.scheduled?.[platform] && typeof saved.scheduled[platform] === 'object'
                ? saved.scheduled[platform]
                : {};
        });
        fresh.schedule = { ...fresh.schedule, ...(saved.schedule || {}) };

        publicationPlatforms.forEach((platform) => {
            const grouped = saved.publications?.[platform];
            if (Array.isArray(grouped)) {
                fresh.publications[platform] = grouped
                    .map((group) => normalizeGroup(group, platform))
                    .filter((group) => group.items.length > 0);
                return;
            }

            const legacyItems = validIds(saved[platform], groupLimits[platform]);
            if (legacyItems.length) {
                fresh.publications[platform] = [normalizeGroup({
                    items: legacyItems,
                    copy: saved.copy?.[platform] || '',
                    link: saved.link?.[platform] || 'website',
                }, platform)];
            }
        });

        return fresh;
    };

    let state = readState();

    const saveState = () => localStorage.setItem(storageKey, JSON.stringify(state));
    const clearScheduled = (platform, clientKey) => {
        if (state.scheduled?.[platform]) delete state.scheduled[platform][String(clientKey)];
    };
    const plural = (count, singular, pluralLabel) => `${count} ${count === 1 ? singular : pluralLabel}`;
    const escapeHtml = (value) => String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
    const platformLabels = { pinterest: 'Pinterest', instagram: 'Instagram', facebook: 'Facebook' };
    const cleanList = (values) => (Array.isArray(values) ? values : []).map(String).filter(Boolean);
    const defaultPinTitle = (mockup) => String(mockup?.pinterest?.title || mockup?.editorialTitle || mockup?.artworkTitle || '');
    const normalizedLabel = (value) => String(value || '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, ' ')
        .trim();
    const matchingPinterestBoard = (value) => {
        const normalized = normalizedLabel(value);
        if (!normalized) return null;
        return pinterestBoards.find((board) => board.id === String(value))
            || pinterestBoards.find((board) => normalizedLabel(board.name) === normalized)
            || null;
    };
    const defaultPinBoard = (mockup) => {
        const suggestions = cleanList(mockup?.pinterest?.boards);
        for (const suggestion of suggestions) {
            const exact = matchingPinterestBoard(suggestion);
            if (exact) return exact.id;
            const normalizedSuggestion = normalizedLabel(suggestion);
            const partial = pinterestBoards.find((board) => {
                const normalizedName = normalizedLabel(board.name);
                return normalizedSuggestion.length >= 5
                    && (normalizedName.includes(normalizedSuggestion) || normalizedSuggestion.includes(normalizedName));
            });
            if (partial) return partial.id;
        }
        return '';
    };
    const selectedPinBoard = (mockup, pin) => matchingPinterestBoard(pin?.board)?.id || defaultPinBoard(mockup);
    const selectedPinBoardName = (mockup, pin) => {
        const id = selectedPinBoard(mockup, pin);
        return pinterestBoards.find((board) => board.id === id)?.name || '';
    };
    const pinterestBoardOptions = (selected) => {
        if (pinterestBoardsStatus === 'loading') return '<option value="">Cargando tableros…</option>';
        if (pinterestBoardsStatus === 'error') return '<option value="">Pinterest no conectado</option>';
        return `
            <option value=""${selected === '' ? ' selected' : ''}>Seleccionar tablero</option>
            ${pinterestBoards.map((board) => `<option value="${escapeHtml(board.id)}"${selected === board.id ? ' selected' : ''}>${escapeHtml(board.name)}</option>`).join('')}`;
    };
    const defaultGroupCopy = (platform, id) => {
        const mockup = mockupById.get(String(id));
        if (!mockup) return '';
        if (platform === 'instagram') {
            return [mockup.instagram?.caption, cleanList(mockup.instagram?.hashtags).join(' ')]
                .map((value) => String(value || '').trim()).filter(Boolean).join('\n\n');
        }
        return String(mockup.facebook?.postText || mockup.metadata?.caption || '').trim();
    };
    const groupDisplayTitle = (platform, group) => {
        const mockup = mockupById.get(String(group?.items?.[0] || ''));
        if (!mockup) return 'Sin imágenes';
        if (platform === 'instagram') return String(mockup.instagram?.hook || mockup.editorialTitle || mockup.contextTitle || '');
        return String(mockup.facebook?.headline || mockup.editorialTitle || mockup.contextTitle || '');
    };
    publicationPlatforms.forEach((platform) => {
        state.publications[platform].forEach((group) => {
            if (!group.copy.trim() && group.items[0]) group.copy = defaultGroupCopy(platform, group.items[0]);
        });
    });

    const showToast = (message, duration = 3200) => {
        const toast = document.querySelector('[data-social-toast]');
        if (!toast) return;
        window.clearTimeout(toastTimer);
        toast.textContent = message;
        toast.classList.add('is-visible');
        toastTimer = window.setTimeout(() => toast.classList.remove('is-visible'), duration);
    };

    const publicationCountFor = (platform = '') => {
        if (platform === 'pinterest') return state.pinterest.length;
        if (publicationPlatforms.includes(platform)) {
            return state.publications[platform].filter((group) => group.items.length).length;
        }
        return state.pinterest.length + publicationPlatforms.reduce(
            (total, network) => total + state.publications[network].filter((group) => group.items.length).length,
            0
        );
    };

    const scheduleFor = (entry = {}) => ({
        date: String(state.schedule.perPublication ? (entry.date || state.schedule.date) : state.schedule.date),
        time: String(state.schedule.perPublication ? (entry.time || state.schedule.time) : state.schedule.time),
    });

    const exactPinUrl = (pin = {}) => String(pin.destinationUrl || configuredDestinations[pin.destination === 'saatchi' ? 'saatchi' : 'website'] || '').trim();
    const exactGroupUrl = (group = {}) => String(group.linkUrl || configuredDestinations[group.link === 'saatchi' ? 'saatchi' : 'website'] || '').trim();

    const buildPublishPayload = (scope = '') => {
        const include = (platform) => !scope || scope === platform;
        return {
            csrf: String(boardConfig.csrf || ''),
            timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC',
            schedule: { date: state.schedule.date, time: state.schedule.time },
            pinterest: include('pinterest') ? state.pinterest.map((id, index) => {
                const mockup = mockupById.get(String(id));
                const pin = state.pinData[String(id)] || {};
                return {
                    client_key: `pinterest-${id}`,
                    mockup_id: Number(id),
                    title: String(pin.title ?? defaultPinTitle(mockup)),
                    description: String(pin.description ?? mockup?.pinterest?.description ?? mockup?.metadata?.description ?? ''),
                    alt_text: String(mockup?.metadata?.altText || ''),
                    board_id: selectedPinBoard(mockup, pin),
                    destination_url: exactPinUrl(pin),
                    schedule: scheduleFor(pin),
                    position: index,
                };
            }) : [],
            instagram: include('instagram') ? state.publications.instagram.map((group, index) => ({
                client_key: group.id,
                mockup_ids: group.items.map(Number),
                copy: String(group.copy || ''),
                destination_url: exactGroupUrl(group),
                schedule: scheduleFor(group),
                position: index,
            })) : [],
            facebook: include('facebook') ? state.publications.facebook.map((group, index) => ({
                client_key: group.id,
                mockup_ids: group.items.map(Number),
                copy: String(group.copy || ''),
                destination_url: exactGroupUrl(group),
                schedule: scheduleFor(group),
                position: index,
            })) : [],
        };
    };

    const isPublicHttpsUrl = (value) => {
        try {
            const url = new URL(String(value || ''));
            return url.protocol === 'https:' && Boolean(url.hostname) && !['localhost', '127.0.0.1'].includes(url.hostname);
        } catch (error) {
            return false;
        }
    };

    const validatePublishPayload = (payload) => {
        const errors = [];
        const checkSchedule = (schedule, label) => {
            if (!/^\d{4}-\d{2}-\d{2}$/.test(schedule?.date || '') || !/^\d{2}:\d{2}$/.test(schedule?.time || '')) {
                errors.push(`${label}: elige fecha y hora.`);
            }
        };
        payload.pinterest.forEach((pin, index) => {
            const label = `Pin ${index + 1}`;
            if (!pin.title.trim()) errors.push(`${label}: falta el título.`);
            if (!pin.board_id) errors.push(`${label}: falta seleccionar el tablero.`);
            if (!isPublicHttpsUrl(pin.destination_url)) errors.push(`${label}: revisa el enlace HTTPS.`);
            checkSchedule(pin.schedule, label);
        });
        ['instagram', 'facebook'].forEach((platform) => {
            payload[platform].forEach((group, index) => {
                const label = `${platformLabels[platform]} · publicación ${index + 1}`;
                if (!group.mockup_ids.length) errors.push(`${label}: no tiene imágenes.`);
                if (!group.copy.trim()) errors.push(`${label}: falta el texto.`);
                if (!isPublicHttpsUrl(group.destination_url)) errors.push(`${label}: revisa el enlace HTTPS.`);
                checkSchedule(group.schedule, label);
            });
        });
        return errors;
    };

    const closePublishConfirmation = () => {
        const backdrop = document.querySelector('[data-confirm-backdrop]');
        if (backdrop) backdrop.hidden = true;
        pendingPublishPayload = null;
        document.body.classList.remove('smb-confirm-open');
    };

    const openPublishConfirmation = (payload) => {
        const backdrop = document.querySelector('[data-confirm-backdrop]');
        const summary = document.querySelector('[data-confirm-summary]');
        if (!backdrop || !summary) return;
        const rows = [];
        if (payload.pinterest.length) rows.push(`<li><strong>Pinterest</strong><span>${plural(payload.pinterest.length, 'Pin', 'Pines')}</span></li>`);
        if (payload.instagram.length) rows.push(`<li><strong>Instagram</strong><span>${plural(payload.instagram.length, 'publicación', 'publicaciones')}</span></li>`);
        if (payload.facebook.length) rows.push(`<li><strong>Facebook</strong><span>${plural(payload.facebook.length, 'publicación', 'publicaciones')}</span></li>`);
        const allSchedules = [...payload.pinterest, ...payload.instagram, ...payload.facebook].map((entry) => `${entry.schedule.date} ${entry.schedule.time}`);
        const scheduleText = new Set(allSchedules).size === 1
            ? `Fecha y hora: ${allSchedules[0]}`
            : `${new Set(allSchedules).size} fechas u horarios configurados`;
        summary.innerHTML = `<ul>${rows.join('')}</ul><p>${escapeHtml(scheduleText)} · ${escapeHtml(payload.timezone)}</p>`;
        pendingPublishPayload = payload;
        backdrop.hidden = false;
        document.body.classList.add('smb-confirm-open');
    };

    const submitPublishPayload = async () => {
        if (!pendingPublishPayload) return;
        const button = document.querySelector('[data-submit-publish]');
        const payload = { ...pendingPublishPayload, confirmation: 'PROGRAMAR' };
        if (button) {
            button.disabled = true;
            button.textContent = 'Programando…';
        }
        try {
            const response = await fetch('social_media_schedule.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify(payload),
            });
            const result = await response.json().catch(() => ({}));
            if (!response.ok || !result.ok) throw new Error(result.error || 'No se pudo programar la publicación.');
            (result.jobs || []).forEach((job) => {
                const platform = String(job.channel || '');
                const key = String(job.client_key || '');
                if (networkPlatforms.includes(platform) && key) state.scheduled[platform][key] = job;
            });
            closePublishConfirmation();
            renderAll();
            showToast(result.message || `${result.publication_count} publicaciones programadas.`, 8000);
        } catch (error) {
            showToast(error.message || 'No se pudo programar la publicación.');
        } finally {
            if (button) {
                button.disabled = false;
                button.textContent = 'Confirmar y programar';
            }
        }
    };

    const applyFocusMode = () => {
        const boards = document.querySelector('.smb-boards');
        const page = document.querySelector('.smb-page');
        if (!boards) return;
        const hasFocus = networkPlatforms.includes(focusedNetwork);
        boards.classList.toggle('is-focused', hasFocus);
        boards.dataset.focusedNetwork = hasFocus ? focusedNetwork : '';
        if (page) page.dataset.focusedNetwork = hasFocus ? focusedNetwork : '';
        document.querySelectorAll('[data-board]').forEach((board) => {
            const active = hasFocus && board.dataset.board === focusedNetwork;
            board.classList.toggle('is-focused', active);
            if (hasFocus && !active) board.setAttribute('aria-hidden', 'true');
            else board.removeAttribute('aria-hidden');
            board.querySelector('[data-focus-network]')?.setAttribute('aria-expanded', active ? 'true' : 'false');
        });

        const confirmLabel = document.querySelector('[data-confirm-label]');
        if (confirmLabel) {
            confirmLabel.textContent = hasFocus
                ? `Confirmar y programar ${platformLabels[focusedNetwork]}`
                : 'Confirmar y programar todo';
        }
    };

    const setFocusedNetwork = (platform = '') => {
        focusedNetwork = networkPlatforms.includes(platform) ? platform : '';
        applyFocusMode();
    };

    const enterFocusedNetwork = (platform) => {
        if (!networkPlatforms.includes(platform) || focusedNetwork === platform) return;
        history.pushState({ ...(history.state || {}), socialMediaFocus: platform }, '', location.href);
        setFocusedNetwork(platform);
    };

    const exitFocusedNetwork = () => {
        if (networkPlatforms.includes(history.state?.socialMediaFocus)) history.back();
        else setFocusedNetwork('');
    };

    window.addEventListener('popstate', (event) => {
        const platform = networkPlatforms.includes(event.state?.socialMediaFocus)
            ? event.state.socialMediaFocus
            : '';
        setFocusedNetwork(platform);
    });

    const inspectorValue = (label, value) => {
        const text = String(value || '').trim();
        if (!text) return '';
        return `<div><dt>${escapeHtml(label)}</dt><dd>${escapeHtml(text).replaceAll('\n', '<br>')}</dd></div>`;
    };

    const inspectorTerms = (label, values) => {
        const terms = cleanList(values);
        if (!terms.length) return '';
        return `<div><dt>${escapeHtml(label)}</dt><dd class="smb-inspector-terms">${terms.map((term) => `<span>${escapeHtml(term)}</span>`).join('')}</dd></div>`;
    };

    const closeInspector = () => {
        const backdrop = document.querySelector('[data-inspector-backdrop]');
        if (!backdrop) return;
        backdrop.hidden = true;
        document.body.classList.remove('smb-inspector-open');
    };

    const openInspector = (id, platform = '', groupId = '') => {
        const mockup = mockupById.get(String(id));
        const backdrop = document.querySelector('[data-inspector-backdrop]');
        const kicker = document.querySelector('[data-inspector-kicker]');
        const title = document.querySelector('[data-inspector-title]');
        const body = document.querySelector('[data-inspector-body]');
        if (!mockup || !backdrop || !kicker || !title || !body) return;

        let currentPublication = '';
        if (platform === 'pinterest') {
            const pin = state.pinData[String(id)] || {};
            const boardName = selectedPinBoardName(mockup, pin);
            currentPublication = `
                <section class="smb-inspector-current smb-inspector-current--pinterest">
                    <span>Contenido actual en Pinterest</span>
                    <strong>${escapeHtml(pin.title ?? defaultPinTitle(mockup))}</strong>
                    <p>Tablero: ${escapeHtml(boardName || 'Sin seleccionar')}</p>
                </section>`;
        } else if (publicationPlatforms.includes(platform)) {
            const group = findGroup(platform, groupId);
            currentPublication = `
                <section class="smb-inspector-current smb-inspector-current--${platform}">
                    <span>Contenido actual en ${escapeHtml(platformLabels[platform])}</span>
                    <strong>${escapeHtml(groupDisplayTitle(platform, group))}</strong>
                    <p>${escapeHtml(group?.copy || defaultGroupCopy(platform, id)).replaceAll('\n', '<br>')}</p>
                </section>`;
        }

        const pinterestSection = `
            <section class="smb-inspector-section smb-inspector-section--pinterest">
                <h3>Pinterest</h3><dl>
                    ${inspectorValue('Título', mockup.pinterest?.title)}
                    ${inspectorValue('Descripción', mockup.pinterest?.description)}
                    ${inspectorTerms('Boards sugeridos', mockup.pinterest?.boards)}
                    ${inspectorTerms('Keywords', mockup.pinterest?.keywords)}
                </dl>
            </section>`;
        const instagramSection = `
            <section class="smb-inspector-section smb-inspector-section--instagram">
                <h3>Instagram</h3><dl>
                    ${inspectorValue('Hook', mockup.instagram?.hook)}
                    ${inspectorValue('Caption', mockup.instagram?.caption)}
                    ${inspectorTerms('Hashtags', mockup.instagram?.hashtags)}
                    ${inspectorValue('CTA', mockup.instagram?.cta)}
                </dl>
            </section>`;
        const facebookSection = `
            <section class="smb-inspector-section smb-inspector-section--facebook">
                <h3>Facebook</h3><dl>
                    ${inspectorValue('Titular', mockup.facebook?.headline)}
                    ${inspectorValue('Texto', mockup.facebook?.postText)}
                    ${inspectorValue('Descripción del enlace', mockup.facebook?.linkDescription)}
                    ${inspectorValue('CTA', mockup.facebook?.cta)}
                </dl>
            </section>`;
        const metadataSection = `
            <section class="smb-inspector-section">
                <h3>Metadata visual</h3><dl>
                    ${inspectorValue('Descripción', mockup.metadata?.description)}
                    ${inspectorValue('Caption', mockup.metadata?.caption)}
                    ${inspectorValue('Alt text', mockup.metadata?.altText)}
                    ${inspectorTerms('Keywords', mockup.metadata?.keywords)}
                    ${inspectorTerms('Tags', mockup.metadata?.tags)}
                </dl>
            </section>`;
        const channelSections = platform === 'pinterest'
            ? pinterestSection
            : platform === 'instagram'
                ? instagramSection
                : platform === 'facebook'
                    ? facebookSection
                    : pinterestSection + instagramSection + facebookSection + metadataSection;

        kicker.textContent = platform ? `Datos de ${platformLabels[platform]}` : 'Datos de publicación';
        title.textContent = mockup.editorialTitle || mockup.contextTitle || mockup.artworkTitle || 'Mockup';
        body.innerHTML = `
            <figure class="smb-inspector-preview"><img src="${escapeHtml(mockup.image)}" alt="${escapeHtml(mockup.artworkTitle)}"></figure>
            <dl class="smb-inspector-identity">
                ${inspectorValue('Obra', mockup.artworkTitle)}
                ${inspectorValue('Serie', mockup.seriesTitle || 'Sin serie')}
                ${inspectorValue('Escena', mockup.contextTitle)}
            </dl>
            ${currentPublication}
            ${channelSections}`;
        backdrop.hidden = false;
        document.body.classList.add('smb-inspector-open');
    };

    const pinMarkup = (id, index) => {
        const mockup = mockupById.get(id);
        if (!mockup) return '';
        const pin = state.pinData[id] || {};
        const title = pin.title ?? defaultPinTitle(mockup);
        const description = pin.description ?? String(mockup.pinterest?.description || mockup.metadata?.description || '');
        const destination = pin.destination === 'saatchi' ? 'saatchi' : 'website';
        const destinationUrl = String(pin.destinationUrl || configuredDestinations[destination] || '');
        const board = selectedPinBoard(mockup, pin);
        const scheduled = state.scheduled.pinterest[`pinterest-${id}`] || null;
        const scheduleFields = state.schedule.perPublication ? `
            <div class="smb-item-schedule">
                <input type="date" value="${escapeHtml(pin.date || state.schedule.date)}" data-pin-date aria-label="Fecha del Pin">
                <input type="time" value="${escapeHtml(pin.time || state.schedule.time)}" data-pin-time aria-label="Hora del Pin">
            </div>` : '';
        return `
            <article class="smb-pin-card smb-sortable-item${state.schedule.perPublication ? ' has-schedule' : ''}" data-board-item data-platform="pinterest" data-mockup-id="${escapeHtml(id)}" data-id="${escapeHtml(id)}" data-index="${index}">
                <span class="smb-pin-drag" data-drag-handle aria-label="Mover Pin" role="button">⋮⋮</span>
                ${scheduled ? `<span class="smb-scheduled-chip" title="${escapeHtml(scheduled.scheduled_at || '')}">Programado</span>` : ''}
                <button class="smb-remove-media" type="button" data-remove-media aria-label="Quitar de Pinterest">×</button>
                <img src="${escapeHtml(mockup.image)}" alt="${escapeHtml(mockup.artworkTitle)}" data-inspect-mockup draggable="false">
                <label class="smb-pin-field smb-pin-field--title"><span>Título</span><input type="text" value="${escapeHtml(title)}" data-pin-title placeholder="Título del Pin"></label>
                <label class="smb-pin-field smb-pin-field--description"><span>Descripción</span><textarea data-pin-description placeholder="Descripción del Pin">${escapeHtml(description)}</textarea></label>
                <label class="smb-pin-field smb-pin-field--board"><span>Tablero</span><select data-pin-board aria-label="Tablero de destino en Pinterest">${pinterestBoardOptions(board)}</select></label>
                <label class="smb-pin-field smb-pin-field--destination"><span>Enlace de destino</span><select data-pin-destination aria-label="Destino sugerido del Pin"><option value="website"${destination === 'website' ? ' selected' : ''}>Website</option><option value="saatchi"${destination === 'saatchi' ? ' selected' : ''}>Saatchi Art</option></select><input type="url" value="${escapeHtml(destinationUrl)}" data-pin-destination-url placeholder="https://…" aria-label="URL exacta del Pin"></label>
                ${scheduleFields}
            </article>`;
    };

    const mediaMarkup = (platform, groupId, id, index) => {
        const mockup = mockupById.get(id);
        if (!mockup) return '';
        return `
            <article class="smb-media-tile smb-sortable-item" data-board-item data-platform="${platform}" data-group-id="${escapeHtml(groupId)}" data-mockup-id="${escapeHtml(id)}" data-id="${escapeHtml(id)}" data-index="${index}">
                <span class="smb-media-position">${index + 1}</span>
                <button class="smb-remove-media" type="button" data-remove-media aria-label="Quitar imagen">×</button>
                <img src="${escapeHtml(mockup.image)}" alt="${escapeHtml(mockup.artworkTitle)}" data-drag-handle data-inspect-mockup draggable="false">
            </article>`;
    };

    const publicationMarkup = (platform, group, index) => {
        const isInstagram = platform === 'instagram';
        const displayTitle = groupDisplayTitle(platform, group);
        const copyLabel = isInstagram ? 'Caption y hashtags' : 'Texto de la publicación';
        const websiteLabel = isInstagram ? 'Website · enlace en bio' : 'Website';
        const saatchiLabel = isInstagram ? 'Saatchi Art · enlace en bio' : 'Saatchi Art';
        const linkUrl = String(group.linkUrl || configuredDestinations[group.link] || '');
        const scheduled = state.scheduled[platform][group.id] || null;
        const scheduleFields = state.schedule.perPublication ? `
            <div class="smb-item-schedule">
                <input type="date" value="${escapeHtml(group.date || state.schedule.date)}" data-group-date aria-label="Fecha de publicación">
                <input type="time" value="${escapeHtml(group.time || state.schedule.time)}" data-group-time aria-label="Hora de publicación">
            </div>` : '';
        const media = group.items.length
            ? group.items.map((id, itemIndex) => mediaMarkup(platform, group.id, id, itemIndex)).join('')
            : '<div class="smb-publication-empty">Arrastra mockups dentro de esta publicación.</div>';

        return `
            <article class="smb-publication-card${state.schedule.perPublication ? ' has-schedule' : ''}" data-publication-group="${platform}" data-group-id="${escapeHtml(group.id)}">
                <header class="smb-publication-head">
                    <div class="smb-publication-label"><strong>Publicación ${index + 1}</strong><span title="${escapeHtml(displayTitle)}">${escapeHtml(displayTitle)}</span><small>${plural(group.items.length, 'imagen', 'imágenes')}${scheduled ? ` · <em title="${escapeHtml(scheduled.scheduled_at || '')}">Programada</em>` : ''}</small></div>
                    <button type="button" data-remove-publication aria-label="Eliminar publicación">×</button>
                </header>
                <div class="smb-group-carousel-wrap">
                    <div class="smb-group-carousel smb-group-carousel--${platform}" data-group-carousel data-sortable-platform="${platform}" data-sortable-group-id="${escapeHtml(group.id)}">${media}</div>
                    ${isInstagram && group.items.length ? `<span class="smb-carousel-count" data-carousel-counter>1 / ${group.items.length}</span>` : ''}
                </div>
                <div class="smb-publication-details">
                    <label><span>${copyLabel}</span><textarea data-group-copy placeholder="Escribe el contenido de la publicación">${escapeHtml(group.copy || '')}</textarea></label>
                    <label><span>Destino</span><select data-group-link><option value="website"${group.link === 'website' ? ' selected' : ''}>${websiteLabel}</option><option value="saatchi"${group.link === 'saatchi' ? ' selected' : ''}>${saatchiLabel}</option></select><input type="url" value="${escapeHtml(linkUrl)}" data-group-link-url placeholder="https://…" aria-label="URL exacta de la publicación"></label>
                </div>
                ${scheduleFields}
            </article>`;
    };

    const renderPinterest = () => {
        const container = document.querySelector('[data-board-items="pinterest"]');
        const counter = document.querySelector('[data-board-count="pinterest"]');
        if (!container || !counter) return;
        counter.textContent = plural(state.pinterest.length, 'publicación', 'publicaciones');
        container.innerHTML = state.pinterest.length
            ? state.pinterest.map(pinMarkup).join('')
            : '<div class="smb-board-empty">Arrastra aquí los mockups. Cada uno será un Pin.</div>';
    };

    const renderPublicationBoard = (platform) => {
        const stack = document.querySelector(`[data-publication-stack="${platform}"]`);
        const counter = document.querySelector(`[data-board-count="${platform}"]`);
        if (!stack || !counter) return;
        const groups = state.publications[platform];
        counter.textContent = plural(groups.length, 'publicación', 'publicaciones');
        stack.dataset.sortableNewPublication = platform;
        stack.innerHTML = groups.map((group, index) => publicationMarkup(platform, group, index)).join('');
    };

    const initializeCarouselNavigation = () => {
        document.querySelectorAll('[data-group-carousel]').forEach((carousel) => {
            const updateCounter = () => {
                const counter = carousel.parentElement?.querySelector('[data-carousel-counter]');
                if (!counter) return;
                const items = Array.from(carousel.querySelectorAll(':scope > .smb-sortable-item'));
                if (!items.length) return;
                const activeIndex = items.reduce((bestIndex, item, index) => {
                    const currentDistance = Math.abs(item.offsetLeft - carousel.scrollLeft);
                    const bestDistance = Math.abs(items[bestIndex].offsetLeft - carousel.scrollLeft);
                    return currentDistance < bestDistance ? index : bestIndex;
                }, 0);
                counter.textContent = `${activeIndex + 1} / ${items.length}`;
            };
            carousel.addEventListener('scroll', updateCounter, { passive: true });
            carousel.addEventListener('wheel', (event) => {
                if (Math.abs(event.deltaY) <= Math.abs(event.deltaX) || carousel.scrollWidth <= carousel.clientWidth) return;
                event.preventDefault();
                carousel.scrollLeft += event.deltaY;
            }, { passive: false });
        });
    };

    const renderAll = () => {
        boardSortables.forEach((sortable) => sortable.destroy());
        boardSortables = [];
        renderPinterest();
        publicationPlatforms.forEach(renderPublicationBoard);
        initializeCarouselNavigation();
        const scheduleModeButton = document.querySelector('[data-schedule-by-network]');
        scheduleModeButton?.classList.toggle('is-active', Boolean(state.schedule.perPublication));
        scheduleModeButton?.setAttribute('aria-pressed', state.schedule.perPublication ? 'true' : 'false');
        applyFocusMode();
        saveState();
        initializeBoardSortables();
    };

    const deferRenderAll = () => {
        window.clearTimeout(deferredRenderTimer);
        deferredRenderTimer = window.setTimeout(renderAll, 0);
    };

    const createPublication = (platform, shouldRender = true) => {
        const group = normalizeGroup({}, platform);
        state.publications[platform].push(group);
        if (shouldRender) renderAll();
        return group;
    };

    const findGroup = (platform, groupId) => state.publications[platform].find((group) => group.id === groupId);

    const addToPinterest = (id, insertAt = null, shouldRender = true) => {
        clearScheduled('pinterest', `pinterest-${id}`);
        const existingIndex = state.pinterest.indexOf(id);
        const adjustedInsertAt = Number.isInteger(insertAt) && existingIndex >= 0 && existingIndex < insertAt
            ? insertAt - 1
            : insertAt;
        if (existingIndex >= 0) state.pinterest.splice(existingIndex, 1);
        const targetIndex = Number.isInteger(adjustedInsertAt)
            ? Math.max(0, Math.min(adjustedInsertAt, state.pinterest.length))
            : state.pinterest.length;
        state.pinterest.splice(targetIndex, 0, id);
        if (shouldRender) renderAll();
    };

    const addToPublication = (platform, id, groupId = '', insertAt = null, sourceGroupId = '', shouldRender = true) => {
        let targetGroup = findGroup(platform, groupId);
        if (!targetGroup) targetGroup = state.publications[platform].at(-1) || createPublication(platform, false);
        clearScheduled(platform, targetGroup.id);
        const targetWasEmpty = targetGroup.items.length === 0;

        const existingIndex = targetGroup.items.indexOf(id);
        const adjustedInsertAt = Number.isInteger(insertAt) && existingIndex >= 0 && existingIndex < insertAt
            ? insertAt - 1
            : insertAt;
        if (existingIndex < 0 && targetGroup.items.length >= groupLimits[platform]) {
            showToast(platform === 'facebook'
                ? 'Esta publicación de Facebook ya tiene 3 imágenes.'
                : 'Este carrusel de Instagram ya tiene 10 imágenes.');
            return false;
        }

        if (sourceGroupId && sourceGroupId !== targetGroup.id) {
            const sourceGroup = findGroup(platform, sourceGroupId);
            if (sourceGroup) {
                clearScheduled(platform, sourceGroup.id);
                sourceGroup.items = sourceGroup.items.filter((value) => value !== id);
            }
        }
        if (existingIndex >= 0) targetGroup.items.splice(existingIndex, 1);

        const targetIndex = Number.isInteger(adjustedInsertAt)
            ? Math.max(0, Math.min(adjustedInsertAt, targetGroup.items.length))
            : targetGroup.items.length;
        targetGroup.items.splice(targetIndex, 0, id);
        if (targetWasEmpty && !targetGroup.copy.trim()) targetGroup.copy = defaultGroupCopy(platform, id);
        state.publications[platform] = state.publications[platform].filter((group) => group.items.length > 0);
        if (shouldRender) renderAll();
        return true;
    };

    const removeMedia = (platform, id, groupId = '') => {
        if (platform === 'pinterest') {
            clearScheduled('pinterest', `pinterest-${id}`);
            state.pinterest = state.pinterest.filter((value) => value !== id);
        } else {
            const group = findGroup(platform, groupId);
            if (group) {
                clearScheduled(platform, group.id);
                group.items = group.items.filter((value) => value !== id);
            }
            state.publications[platform] = state.publications[platform].filter((publication) => publication.items.length > 0);
        }
        renderAll();
    };

    const sortableListMeta = (list) => {
        if (!list) return { kind: '', platform: '', groupId: '' };
        if (list.dataset.sortableCatalog === 'true') return { kind: 'catalog', platform: '', groupId: '' };
        if (list.dataset.sortablePinterest === 'true') return { kind: 'pinterest', platform: 'pinterest', groupId: '' };
        if (list.dataset.sortablePlatform) {
            return { kind: 'publication', platform: list.dataset.sortablePlatform, groupId: list.dataset.sortableGroupId || '' };
        }
        if (list.dataset.sortableNewPublication) {
            return { kind: 'new-publication', platform: list.dataset.sortableNewPublication, groupId: '' };
        }
        return { kind: '', platform: '', groupId: '' };
    };

    const sortableItemIds = (list) => Array.from(list.querySelectorAll(':scope > .smb-sortable-item'))
        .map((item) => String(item.dataset.mockupId || item.dataset.id || ''))
        .filter((id) => mockupById.has(id));

    const canMoveSortable = (event) => {
        const target = sortableListMeta(event.to);
        if (target.kind !== 'publication') return true;
        const group = findGroup(target.platform, target.groupId);
        const id = String(event.dragged?.dataset.mockupId || event.dragged?.dataset.id || '');
        if (!group || group.items.includes(id) || event.from === event.to) return true;
        return group.items.length < groupLimits[target.platform];
    };

    const handleSortableAdd = (event) => {
        const source = sortableListMeta(event.from);
        const target = sortableListMeta(event.to);
        const id = String(event.item?.dataset.mockupId || event.item?.dataset.id || '');
        const insertAt = Number.isInteger(event.newDraggableIndex) ? event.newDraggableIndex : event.newIndex;

        if (!mockupById.has(id)) {
            deferRenderAll();
            return;
        }

        if (target.kind === 'pinterest') {
            addToPinterest(id, Number.isInteger(insertAt) ? insertAt : null, false);
            deferRenderAll();
            return;
        }

        if (target.kind === 'publication') {
            addToPublication(
                target.platform,
                id,
                target.groupId,
                Number.isInteger(insertAt) ? insertAt : null,
                source.platform === target.platform ? source.groupId : '',
                false
            );
            deferRenderAll();
            return;
        }

        if (target.kind === 'new-publication') {
            const group = createPublication(target.platform, false);
            addToPublication(
                target.platform,
                id,
                group.id,
                0,
                source.platform === target.platform ? source.groupId : '',
                false
            );
            deferRenderAll();
        }
    };

    const handleSortableUpdate = (event) => {
        const target = sortableListMeta(event.to);
        const order = sortableItemIds(event.to);
        if (target.kind === 'pinterest') {
            state.pinterest = order;
            order.forEach((id) => clearScheduled('pinterest', `pinterest-${id}`));
        } else if (target.kind === 'publication') {
            const group = findGroup(target.platform, target.groupId);
            if (group) {
                clearScheduled(target.platform, group.id);
                group.items = order.slice(0, groupLimits[target.platform]);
            }
        }
        deferRenderAll();
    };

    const sortableBaseOptions = () => ({
        group: { name: 'social-media-mockups', pull: true, put: true },
        draggable: '.smb-sortable-item',
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
        ghostClass: 'smb-sortable-placeholder',
        chosenClass: 'smb-sortable-chosen',
        dragClass: 'smb-sortable-drag',
        fallbackClass: 'smb-sortable-mirror',
        onStart: () => document.body.classList.add('smb-is-sorting'),
        onEnd: () => document.body.classList.remove('smb-is-sorting'),
        onUnchoose: () => document.body.classList.remove('smb-is-sorting'),
        onMove: canMoveSortable,
        onAdd: handleSortableAdd,
        onUpdate: handleSortableUpdate,
    });

    const initializeBoardSortables = () => {
        if (typeof window.Sortable !== 'function') return;

        const pinterest = document.querySelector('[data-board-items="pinterest"]');
        if (pinterest) {
            pinterest.dataset.sortablePinterest = 'true';
            boardSortables.push(window.Sortable.create(pinterest, {
                ...sortableBaseOptions(),
                handle: '[data-drag-handle]',
                direction: 'horizontal',
            }));
        }

        document.querySelectorAll('[data-group-carousel]').forEach((carousel) => {
            boardSortables.push(window.Sortable.create(carousel, {
                ...sortableBaseOptions(),
                handle: '[data-drag-handle]',
                direction: 'horizontal',
            }));
        });

        document.querySelectorAll('[data-sortable-new-publication]').forEach((dropzone) => {
            boardSortables.push(window.Sortable.create(dropzone, {
                ...sortableBaseOptions(),
                group: { name: 'social-media-mockups', pull: false, put: true },
                sort: false,
                direction: 'horizontal',
            }));
        });
    };

    const initializeCatalogSortable = () => {
        const catalog = document.querySelector('[data-catalog-rail]');
        if (!catalog || typeof window.Sortable !== 'function') {
            showToast('No se pudo iniciar el sistema de arrastre.');
            return;
        }
        catalog.dataset.sortableCatalog = 'true';
        catalogSortable = window.Sortable.create(catalog, {
            ...sortableBaseOptions(),
            group: { name: 'social-media-mockups', pull: 'clone', put: false, revertClone: true },
            sort: false,
            direction: 'horizontal',
            filter: 'button, input, select, textarea, a',
            preventOnFilter: false,
            removeCloneOnHide: true,
        });
    };

    const applyCatalogFilters = () => {
        const artworkId = document.querySelector('[data-artwork-filter]')?.value || '';
        const seriesValue = document.querySelector('[data-series-filter]')?.value || '';
        cards.forEach((card) => {
            const matchesArtwork = !artworkId || card.dataset.artworkId === artworkId;
            const matchesSeries = !seriesValue
                || (seriesValue === 'none' ? card.dataset.seriesId === '0' : card.dataset.seriesId === seriesValue);
            card.hidden = !(matchesArtwork && matchesSeries);
        });
    };

    document.addEventListener('click', async (event) => {
        const publishBackdrop = event.target.closest('[data-confirm-backdrop]');
        if (event.target.closest('[data-cancel-publish]') || (publishBackdrop && event.target === publishBackdrop)) {
            closePublishConfirmation();
            return;
        }
        if (event.target.closest('[data-submit-publish]')) {
            await submitPublishPayload();
            return;
        }

        const exitFocus = event.target.closest('[data-exit-network-focus]');
        if (exitFocus) {
            exitFocusedNetwork();
            return;
        }

        const focusTrigger = event.target.closest('[data-focus-network]');
        if (focusTrigger) {
            enterFocusedNetwork(focusTrigger.dataset.focusNetwork || '');
            return;
        }

        const backdrop = event.target.closest('[data-inspector-backdrop]');
        if (event.target.closest('[data-close-inspector]') || (backdrop && event.target === backdrop)) {
            closeInspector();
            return;
        }

        const remove = event.target.closest('[data-remove-media]');
        if (remove) {
            const item = remove.closest('[data-board-item]');
            if (item) removeMedia(item.dataset.platform, item.dataset.mockupId, item.dataset.groupId || '');
            return;
        }

        const removePublication = event.target.closest('[data-remove-publication]');
        if (removePublication) {
            const groupElement = removePublication.closest('[data-publication-group]');
            const platform = groupElement?.dataset.publicationGroup;
            const groupId = groupElement?.dataset.groupId;
            if (platform && groupId) {
                clearScheduled(platform, groupId);
                state.publications[platform] = state.publications[platform].filter((group) => group.id !== groupId);
                renderAll();
            }
            return;
        }

        const favoriteButton = event.target.closest('[data-toggle-favorite]');
        if (favoriteButton) {
            const card = favoriteButton.closest('[data-catalog-card]');
            if (!card || favoriteButton.disabled) return;
            const form = new FormData();
            form.append('mockup_id', card.dataset.mockupId || '');
            favoriteButton.disabled = true;
            try {
                const response = await fetch('toggle_mockup_favorite.php', { method: 'POST', body: form });
                const result = await response.json();
                if (!response.ok || !result.ok) throw new Error(result.error || 'No se pudo actualizar el favorito.');
                const isFavorite = Boolean(result.favorite);
                card.classList.toggle('is-favorite', isFavorite);
                favoriteButton.textContent = isFavorite ? '♥' : '♡';
                favoriteButton.setAttribute('aria-pressed', isFavorite ? 'true' : 'false');
                favoriteButton.setAttribute('aria-label', isFavorite ? 'Quitar de favoritos' : 'Agregar a favoritos');
                const mockup = mockupById.get(card.dataset.mockupId);
                if (mockup) mockup.favorite = isFavorite;
                sortCatalog();
            } catch (error) {
                showToast(error.message || 'No se pudo actualizar el favorito.');
            } finally {
                favoriteButton.disabled = false;
            }
            return;
        }

        const scrollButton = event.target.closest('[data-scroll-catalog]');
        if (scrollButton) {
            const rail = document.querySelector('[data-catalog-rail]');
            rail?.scrollBy({ left: Number(scrollButton.dataset.scrollCatalog) * Math.max(480, rail.clientWidth * .72), behavior: 'smooth' });
            return;
        }

        const inspectTarget = event.target.closest('[data-inspect-mockup]');
        if (inspectTarget) {
            const item = inspectTarget.closest('[data-board-item], [data-catalog-card]');
            if (item) openInspector(item.dataset.mockupId, item.dataset.platform || '', item.dataset.groupId || '');
            return;
        }

        if (event.target.closest('[data-schedule-by-network]')) {
            state.schedule.perPublication = !state.schedule.perPublication;
            state.scheduled = { pinterest: {}, instagram: {}, facebook: {} };
            renderAll();
            return;
        }

        if (event.target.closest('[data-confirm-schedule]')) {
            const publicationCount = publicationCountFor(focusedNetwork);
            if (!publicationCount) {
                showToast(focusedNetwork
                    ? `Arrastra al menos un mockup al tablero de ${platformLabels[focusedNetwork]}.`
                    : 'Arrastra al menos un mockup a uno de los tres tableros.');
                return;
            }
            const payload = buildPublishPayload(focusedNetwork);
            const errors = validatePublishPayload(payload);
            if (errors.length) {
                showToast(errors[0]);
                return;
            }
            openPublishConfirmation(payload);
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            const confirmation = document.querySelector('[data-confirm-backdrop]');
            if (confirmation && !confirmation.hidden) {
                closePublishConfirmation();
                return;
            }
            const inspector = document.querySelector('[data-inspector-backdrop]');
            if (inspector && !inspector.hidden) closeInspector();
            else if (focusedNetwork) exitFocusedNetwork();
            return;
        }
        if (!['Enter', ' '].includes(event.key)) return;
        const card = event.target.closest('[data-catalog-card]');
        if (!card || event.target.closest('button, input, select, textarea, a')) return;
        event.preventDefault();
        openInspector(card.dataset.mockupId);
    });

    document.addEventListener('input', (event) => {
        const pinItem = event.target.closest('[data-board-item][data-platform="pinterest"]');
        if (pinItem && event.target.matches('[data-pin-title], [data-pin-description], [data-pin-board], [data-pin-destination], [data-pin-destination-url]')) {
            const id = pinItem.dataset.mockupId;
            clearScheduled('pinterest', `pinterest-${id}`);
            state.pinData[id] = state.pinData[id] || {};
            if (event.target.matches('[data-pin-title]')) state.pinData[id].title = event.target.value;
            if (event.target.matches('[data-pin-description]')) state.pinData[id].description = event.target.value;
            if (event.target.matches('[data-pin-board]')) {
                state.pinData[id].board = event.target.value;
                state.pinData[id].boardName = event.target.selectedOptions[0]?.textContent || '';
            }
            if (event.target.matches('[data-pin-destination]')) {
                state.pinData[id].destination = event.target.value;
                state.pinData[id].destinationUrl = configuredDestinations[event.target.value] || '';
                renderAll();
                return;
            }
            if (event.target.matches('[data-pin-destination-url]')) state.pinData[id].destinationUrl = event.target.value;
            saveState();
            return;
        }

        const copy = event.target.closest('[data-group-copy]');
        if (copy) {
            const groupElement = copy.closest('[data-publication-group]');
            if (groupElement) {
                const group = findGroup(groupElement.dataset.publicationGroup, groupElement.dataset.groupId);
                if (group) {
                    clearScheduled(groupElement.dataset.publicationGroup, group.id);
                    group.copy = copy.value;
                }
            }
            saveState();
            return;
        }

        const groupUrl = event.target.closest('[data-group-link-url]');
        if (groupUrl) {
            const groupElement = groupUrl.closest('[data-publication-group]');
            if (groupElement) {
                const group = findGroup(groupElement.dataset.publicationGroup, groupElement.dataset.groupId);
                if (group) {
                    clearScheduled(groupElement.dataset.publicationGroup, group.id);
                    group.linkUrl = groupUrl.value;
                }
            }
            saveState();
        }
    });

    document.addEventListener('change', (event) => {
        const catalogFilter = event.target.closest('[data-artwork-filter], [data-series-filter]');
        if (catalogFilter) {
            applyCatalogFilters();
            return;
        }

        const groupLink = event.target.closest('[data-group-link]');
        if (groupLink) {
            const groupElement = groupLink.closest('[data-publication-group]');
            if (groupElement) {
                const group = findGroup(groupElement.dataset.publicationGroup, groupElement.dataset.groupId);
                if (group) {
                    clearScheduled(groupElement.dataset.publicationGroup, group.id);
                    group.link = groupLink.value === 'saatchi' ? 'saatchi' : 'website';
                    group.linkUrl = configuredDestinations[group.link] || '';
                }
            }
            renderAll();
            return;
        }

        const pinScheduleField = event.target.closest('[data-pin-date], [data-pin-time]');
        if (pinScheduleField) {
            const item = pinScheduleField.closest('[data-board-item][data-platform="pinterest"]');
            if (item) {
                const id = item.dataset.mockupId;
                clearScheduled('pinterest', `pinterest-${id}`);
                state.pinData[id] = state.pinData[id] || {};
                if (pinScheduleField.matches('[data-pin-date]')) state.pinData[id].date = pinScheduleField.value;
                if (pinScheduleField.matches('[data-pin-time]')) state.pinData[id].time = pinScheduleField.value;
                saveState();
            }
            return;
        }

        const groupScheduleField = event.target.closest('[data-group-date], [data-group-time]');
        if (groupScheduleField) {
            const groupElement = groupScheduleField.closest('[data-publication-group]');
            if (!groupElement) return;
            const group = findGroup(groupElement.dataset.publicationGroup, groupElement.dataset.groupId);
            if (group) {
                clearScheduled(groupElement.dataset.publicationGroup, group.id);
                if (groupScheduleField.matches('[data-group-date]')) group.date = groupScheduleField.value;
                if (groupScheduleField.matches('[data-group-time]')) group.time = groupScheduleField.value;
                saveState();
            }
            return;
        }

        const date = event.target.closest('[data-schedule-date]');
        const time = event.target.closest('[data-schedule-time]');
        if (date) state.schedule.date = date.value;
        if (time) state.schedule.time = time.value;
        if (date || time) {
            if (!state.schedule.perPublication) state.scheduled = { pinterest: {}, instagram: {}, facebook: {} };
            if (state.schedule.perPublication) renderAll();
            else saveState();
        }
    });

    function sortCatalog() {
        const rail = document.querySelector('[data-catalog-rail]');
        if (!rail) return;
        cards
            .slice()
            .sort((left, right) => {
                const favoriteDifference = Number(right.classList.contains('is-favorite')) - Number(left.classList.contains('is-favorite'));
                if (favoriteDifference) return favoriteDifference;
                return (originalOrder.get(left.dataset.mockupId) || 0) - (originalOrder.get(right.dataset.mockupId) || 0);
            })
            .forEach((card) => rail.appendChild(card));
    }

    const loadPinterestBoards = async () => {
        try {
            const response = await fetch('social_media_pinterest_boards.php', {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const result = await response.json();
            if (!response.ok || !result.ok || !Array.isArray(result.boards)) throw new Error(result.error || 'Pinterest unavailable');
            pinterestBoards = result.boards
                .map((board) => ({ id: String(board.id || ''), name: String(board.name || '') }))
                .filter((board) => board.id && board.name);
            pinterestBoardsStatus = 'ready';
        } catch (error) {
            pinterestBoards = [];
            pinterestBoardsStatus = 'error';
        }
        renderAll();
    };

    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    const defaultDate = tomorrow.toISOString().slice(0, 10);
    const dateInput = document.querySelector('[data-schedule-date]');
    const timeInput = document.querySelector('[data-schedule-time]');
    if (dateInput) dateInput.value = state.schedule.date || defaultDate;
    if (timeInput) timeInput.value = state.schedule.time || '10:00';
    state.schedule.date = dateInput?.value || defaultDate;
    state.schedule.time = timeInput?.value || '10:00';

    sortCatalog();
    renderAll();
    initializeCatalogSortable();
    loadPinterestBoards();
})();

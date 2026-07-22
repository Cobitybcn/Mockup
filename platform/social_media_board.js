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
    const configuredPinterestPurposes = Array.isArray(boardConfig?.pinterest?.purposes)
        ? boardConfig.pinterest.purposes
            .map((item) => String(item?.value || ''))
            .filter((value) => ['artist', 'platform'].includes(value))
        : ['artist'];
    const defaultPinterestPurpose = configuredPinterestPurposes.includes(String(boardConfig?.pinterest?.purpose || ''))
        ? String(boardConfig.pinterest.purpose)
        : configuredPinterestPurposes[0] || 'artist';
    const pinterestEnvironment = String(boardConfig?.pinterest?.environment || 'production');
    const pinterestEnvironments = boardConfig?.pinterest?.environments && typeof boardConfig.pinterest.environments === 'object'
        ? boardConfig.pinterest.environments
        : {};

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
    let scheduledJobs = [];
    let publicationHistoryReady = false;
    let scheduledReloadTimer = 0;
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
        pinterestPurpose: defaultPinterestPurpose,
        scheduled: { pinterest: {}, instagram: {}, facebook: {} },
        schedule: { mode: 'now', date: '', time: '', perPublication: false },
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
        fresh.pinterestPurpose = configuredPinterestPurposes.includes(String(saved.pinterestPurpose || ''))
            ? String(saved.pinterestPurpose)
            : defaultPinterestPurpose;
        networkPlatforms.forEach((platform) => {
            fresh.scheduled[platform] = saved.scheduled?.[platform] && typeof saved.scheduled[platform] === 'object'
                ? saved.scheduled[platform]
                : {};
        });
        fresh.schedule = { ...fresh.schedule, ...(saved.schedule || {}) };
        fresh.schedule.mode = saved.schedule?.mode === 'scheduled' ? 'scheduled' : 'now';

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
        if (pinterestBoardsStatus === 'loading') return '<option value="">Loading boards…</option>';
        if (pinterestBoardsStatus === 'error') return '<option value="">Pinterest no conectado</option>';
        return `
            <option value=""${selected === '' ? ' selected' : ''}>Select board</option>
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
        if (!mockup) return 'No images';
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
        toast.replaceChildren(document.createTextNode(message));
        toast.classList.toggle('is-persistent', duration <= 0);
        if (duration <= 0) {
            const close = document.createElement('button');
            close.type = 'button';
            close.className = 'smb-toast-close';
            close.setAttribute('aria-label', 'Close message');
            close.textContent = 'Close';
            close.addEventListener('click', () => toast.classList.remove('is-visible'));
            toast.appendChild(close);
        }
        toast.classList.add('is-visible');
        if (duration > 0) toastTimer = window.setTimeout(() => toast.classList.remove('is-visible'), duration);
    };

    const localScheduleParts = (value) => {
        const instant = new Date(String(value || ''));
        if (Number.isNaN(instant.getTime())) return { date: '', time: '' };
        const local = new Date(instant.getTime() - instant.getTimezoneOffset() * 60000);
        return { date: local.toISOString().slice(0, 10), time: local.toISOString().slice(11, 16) };
    };

    const scheduledStatusLabel = (job) => {
        const status = String(job?.status || '');
        if (status === 'queued') {
            const scheduledAt = new Date(String(job?.scheduled_at || '')).getTime();
            return Number.isFinite(scheduledAt) && scheduledAt <= Date.now() + 2 * 60 * 1000
                ? 'Queued to publish now'
                : 'Programada';
        }
        return ({
            published: 'Publicada',
            publishing: 'Publicando',
            failed: 'Failed',
            needs_verification: 'Needs verification',
            enqueue_failed: 'Not queued',
            rescheduling: 'Rescheduling',
            retrying: 'Preparando reintento',
            cancelling: 'Cancelando',
            cancelled: 'Cancelada',
        })[status] || status;
    };

    const scheduledChip = (job) => {
        if (!job) return '';
        const status = String(job.status || '');
        const label = status === 'published' ? 'Publicado'
            : status === 'failed' ? 'Failed'
                : status === 'needs_verification' ? 'Verificar'
                    : status === 'publishing' ? 'Publicando'
                        : status === 'queued' ? (scheduledStatusLabel(job).includes('now') ? 'Queued' : 'Scheduled')
                            : scheduledStatusLabel(job);
        return `<span class="smb-scheduled-chip smb-scheduled-chip--${escapeHtml(status)}" title="${escapeHtml(job.error || job.scheduled_at || '')}">${escapeHtml(label)}</span>`;
    };

    const syncScheduledState = (jobs) => {
        state.scheduled = { pinterest: {}, instagram: {}, facebook: {} };
        jobs.forEach((job) => {
            const platform = String(job.channel || '');
            const key = String(job.client_key || '');
            if (networkPlatforms.includes(platform) && key && !state.scheduled[platform][key]) state.scheduled[platform][key] = job;
        });
        saveState();
        renderAll();
    };

    const renderScheduledJobs = () => {
        const list = document.querySelector('[data-scheduled-list]');
        if (!list) return;
        if (!scheduledJobs.length) {
            list.innerHTML = '<div class="smb-scheduled-empty">No publishing activity yet.</div>';
            return;
        }
        list.innerHTML = scheduledJobs.map((job) => {
            const parts = localScheduleParts(job.scheduled_at);
            const count = Math.max(1, Number(job.item_count || 1));
            const countLabel = job.channel === 'pinterest'
                ? plural(count, 'Pin', 'Pines')
                : plural(count, 'image', 'images');
            const status = String(job.status || '');
            const sandboxDestinationPending = job.channel === 'pinterest'
                && pinterestEnvironment === 'sandbox'
                && pinterestBoardsStatus !== 'ready';
            const sandboxDestinationMismatch = job.channel === 'pinterest'
                && pinterestEnvironment === 'sandbox'
                && pinterestBoardsStatus === 'ready'
                && Boolean(job.board_id)
                && !pinterestBoards.some((board) => board.id === String(job.board_id));
            const destinationReady = !sandboxDestinationPending && !sandboxDestinationMismatch;
            const canReschedule = Boolean(job.can_reschedule) && status === 'queued' && destinationReady;
            const when = canReschedule ? `
                    <div class="smb-scheduled-when">
                        <label><span>Date</span><input type="date" value="${escapeHtml(parts.date)}" data-scheduled-date aria-label="New publishing date"></label>
                        <label><span>Time</span><input type="time" value="${escapeHtml(parts.time)}" data-scheduled-time aria-label="New publishing time"></label>
                    </div>` : '<div class="smb-scheduled-when smb-scheduled-when--empty"></div>';
            const actions = [];
            if (canReschedule) {
                actions.push('<button type="button" class="smb-scheduled-action" data-scheduled-action="reschedule">Save new date</button>');
                actions.push('<button type="button" class="smb-scheduled-action smb-scheduled-action--now" data-scheduled-action="publish_now">Publish now</button>');
                actions.push('<button type="button" class="smb-scheduled-action smb-scheduled-action--cancel" data-scheduled-action="cancel">Cancel</button>');
            } else if (status === 'queued' && !destinationReady) {
                actions.push('<button type="button" class="smb-scheduled-action smb-scheduled-action--cancel" data-scheduled-action="cancel">Cancel schedule</button>');
                actions.push(`<span class="smb-scheduled-note">${sandboxDestinationMismatch
                    ? `The “${escapeHtml(job.board_name || 'previous')}” board does not exist in Sandbox. Cancel this schedule and create the Pin with “Artwork Mockups Sandbox Test”.`
                    : 'Validating the Pinterest board before enabling actions…'}</span>`);
            } else if (job.can_retry) {
                actions.push('<button type="button" class="smb-scheduled-action smb-scheduled-action--retry" data-scheduled-action="retry">Retry this one only</button>');
            }
            if (status === 'published' && job.external_url) {
                actions.push(`<a class="smb-scheduled-link" href="${escapeHtml(job.external_url)}" target="_blank" rel="noopener">View publication</a>`);
            }
            if (status === 'needs_verification') {
                actions.push('<span class="smb-scheduled-note">Check the network before retrying to avoid duplicates.</span>');
            }
            if (status === 'failed' && !job.can_retry) {
                actions.push(`<span class="smb-scheduled-note">${escapeHtml(job.retry_hint || 'Correct the reported cause before publishing again.')}</span>`);
            }
            return `
                <article class="smb-scheduled-card smb-scheduled-card--${escapeHtml(status)}" data-scheduled-job="${escapeHtml(job.id)}" data-destination-ready="${destinationReady ? 'true' : 'false'}">
                    <div class="smb-scheduled-copy">
                        <span class="smb-scheduled-network smb-scheduled-network--${escapeHtml(job.channel)}">${escapeHtml(platformLabels[job.channel] || job.channel)}</span>
                        <strong title="${escapeHtml(job.label)}">${escapeHtml(job.label)}</strong>
                        <small><b class="smb-status smb-status--${escapeHtml(status)}">${escapeHtml(scheduledStatusLabel(job))}</b> · ${escapeHtml(countLabel)} · ${escapeHtml(new Date(job.scheduled_at).toLocaleString())}</small>
                        ${job.error ? `<p class="smb-scheduled-error">${escapeHtml(job.error)}</p>` : ''}
                    </div>
                    ${when}
                    <div class="smb-scheduled-actions">${actions.join('')}</div>
                </article>`;
        }).join('');
    };

    const loadScheduledJobs = async (quiet = false) => {
        const refresh = document.querySelector('[data-refresh-scheduled]');
        if (refresh) refresh.disabled = true;
        try {
            const response = await fetch('social_media_scheduled_jobs.php', {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            const result = await response.json().catch(() => ({}));
            if (!response.ok || !result.ok || !Array.isArray(result.jobs)) throw new Error(result.error || 'The publishing queue could not be loaded.');
            scheduledJobs = result.jobs;
            publicationHistoryReady = true;
            renderScheduledJobs();
            syncScheduledState(scheduledJobs);
        } catch (error) {
            publicationHistoryReady = false;
            if (!quiet) showToast(error.message || 'The publishing queue could not be loaded.');
            const list = document.querySelector('[data-scheduled-list]');
            if (list && !scheduledJobs.length) list.innerHTML = '<div class="smb-scheduled-empty">Publishing history could not be loaded. Select Refresh to try again.</div>';
        } finally {
            if (refresh) refresh.disabled = false;
        }
    };

    const manageScheduledJob = async (action, card) => {
        const jobId = Number(card?.dataset.scheduledJob || 0);
        if (!jobId || !['reschedule', 'publish_now', 'retry', 'cancel'].includes(action)) return;
        if (['reschedule', 'publish_now', 'retry'].includes(action) && card?.dataset.destinationReady === 'false') {
            showToast('This Pin uses a board that does not exist in Pinterest Sandbox. Cancel it and create it again with the test board.');
            return;
        }
        if (action === 'publish_now' && !window.confirm('Publish this item now? It will enter the live queue immediately.')) return;
        if (action === 'retry' && !window.confirm('Retry only this failed publication? Items already published will not be repeated.')) return;
        if (action === 'cancel' && !window.confirm('Cancel this scheduled publication?')) return;
        const payload = {
            csrf: String(boardConfig.csrf || ''),
            job_id: jobId,
            action,
            timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC',
            date: card.querySelector('[data-scheduled-date]')?.value || '',
            time: card.querySelector('[data-scheduled-time]')?.value || '',
            confirmation: action === 'reschedule' ? 'REPROGRAMAR' : action === 'publish_now' ? 'PUBLICAR_AHORA' : action === 'retry' ? 'REINTENTAR' : 'CANCELAR',
        };
        if (action === 'reschedule' && (!payload.date || !payload.time)) {
            showToast('Choose a date and time to reschedule.');
            return;
        }
        card.classList.add('is-busy');
        card.querySelectorAll('button, input').forEach((control) => { control.disabled = true; });
        try {
            const response = await fetch('social_media_scheduled_jobs.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify(payload),
            });
            const result = await response.json().catch(() => ({}));
            if (!response.ok || !result.ok) throw new Error(result.error || 'The scheduled publication could not be changed.');
            scheduledJobs = Array.isArray(result.jobs) ? result.jobs : [];
            renderScheduledJobs();
            syncScheduledState(scheduledJobs);
            showToast(result.message || 'Scheduled publication updated.', 7000);
            window.clearTimeout(scheduledReloadTimer);
            scheduledReloadTimer = window.setTimeout(() => loadScheduledJobs(true), ['publish_now', 'retry'].includes(action) ? 5000 : 1500);
        } catch (error) {
            showToast(error.message || 'The scheduled publication could not be changed.', 7000);
            card.classList.remove('is-busy');
            card.querySelectorAll('button, input').forEach((control) => { control.disabled = false; });
        }
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
        mode: state.schedule.mode === 'scheduled' ? 'scheduled' : 'now',
        date: String(state.schedule.perPublication ? (entry.date || state.schedule.date) : state.schedule.date),
        time: String(state.schedule.perPublication ? (entry.time || state.schedule.time) : state.schedule.time),
    });

    const exactPinUrl = (pin = {}) => String(pin.destinationUrl || configuredDestinations[pin.destination === 'saatchi' ? 'saatchi' : 'website'] || '').trim();
    const exactGroupUrl = (group = {}) => String(group.linkUrl || configuredDestinations[group.link === 'saatchi' ? 'saatchi' : 'website'] || '').trim();

    const buildPublishPayload = (scope = '') => {
        const include = (platform) => !scope || scope === platform;
        return {
            csrf: String(boardConfig.csrf || ''),
            pinterest_purpose: state.pinterestPurpose,
            timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC',
            schedule: { mode: state.schedule.mode, date: state.schedule.date, time: state.schedule.time },
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
            if (schedule?.mode === 'now') return;
            if (!/^\d{4}-\d{2}-\d{2}$/.test(schedule?.date || '') || !/^\d{2}:\d{2}$/.test(schedule?.time || '')) {
                errors.push(`${label}: choose a date and time.`);
            }
        };
        const checkPreviousJob = (platform, clientKey, label) => {
            const previous = state.scheduled?.[platform]?.[String(clientKey)] || null;
            const status = String(previous?.status || '');
            if (status === 'published') errors.push(`${label}: it is already marked as published. Change the publication before creating another one.`);
            else if (['queued', 'publishing', 'rescheduling', 'retrying'].includes(status)) errors.push(`${label}: it is already queued or publishing.`);
            else if (['failed', 'enqueue_failed'].includes(status)) errors.push(`${label}: it failed previously. Use “Retry only this item” in Publishing history.`);
            else if (status === 'needs_verification') errors.push(`${label}: verify the network first to prevent a duplicate publication.`);
        };
        payload.pinterest.forEach((pin, index) => {
            const label = `Pin ${index + 1}`;
            if (!pin.title.trim()) errors.push(`${label}: the title is missing.`);
            if (!pin.board_id) errors.push(`${label}: select a board.`);
            if (!isPublicHttpsUrl(pin.destination_url)) errors.push(`${label}: check the HTTPS link.`);
            checkSchedule(pin.schedule, label);
            checkPreviousJob('pinterest', pin.client_key, label);
        });
        ['instagram', 'facebook'].forEach((platform) => {
            payload[platform].forEach((group, index) => {
                const label = `${platformLabels[platform]} · publication ${index + 1}`;
                if (!group.mockup_ids.length) errors.push(`${label}: it has no images.`);
                if (!group.copy.trim()) errors.push(`${label}: the copy is missing.`);
                if (!isPublicHttpsUrl(group.destination_url)) errors.push(`${label}: check the HTTPS link.`);
                checkSchedule(group.schedule, label);
                checkPreviousJob(platform, group.client_key, label);
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

    const closeScheduleDialog = () => {
        const backdrop = document.querySelector('[data-schedule-backdrop]');
        if (backdrop) backdrop.hidden = true;
        document.body.classList.remove('smb-confirm-open');
    };

    const openPublishConfirmation = (payload) => {
        const backdrop = document.querySelector('[data-confirm-backdrop]');
        const summary = document.querySelector('[data-confirm-summary]');
        const title = document.querySelector('[data-confirm-title]');
        const delivery = document.querySelector('[data-confirm-delivery]');
        const warning = document.querySelector('[data-confirm-warning]');
        const submit = document.querySelector('[data-submit-publish-label]');
        if (!backdrop || !summary || !title || !delivery || !warning || !submit) return;
        const rows = [];
        if (payload.pinterest.length) rows.push(`<li><strong>Pinterest</strong><span>${plural(payload.pinterest.length, 'Pin', 'Pines')}</span></li>`);
        if (payload.instagram.length) {
            const images = payload.instagram.reduce((total, group) => total + group.mockup_ids.length, 0);
            rows.push(`<li><strong>Instagram</strong><span>${plural(payload.instagram.length, 'carousel/publication', 'carousels/publications')} · ${plural(images, 'image', 'images')}</span></li>`);
        }
        if (payload.facebook.length) {
            const images = payload.facebook.reduce((total, group) => total + group.mockup_ids.length, 0);
            rows.push(`<li><strong>Facebook</strong><span>${plural(payload.facebook.length, 'publication', 'publications')} · ${plural(images, 'image', 'images')}</span></li>`);
        }
        const isNow = payload.schedule?.mode === 'now';
        let scheduleText = 'Timing: now, after confirmation';
        if (!isNow) {
            const allSchedules = [...payload.pinterest, ...payload.instagram, ...payload.facebook].map((entry) => `${entry.schedule.date} ${entry.schedule.time}`);
            scheduleText = new Set(allSchedules).size === 1
                ? `Date and time: ${allSchedules[0]}`
                : `${new Set(allSchedules).size} dates or times configured`;
        }
        title.textContent = isNow ? 'Confirm immediate publishing' : 'Confirm schedule';
        delivery.textContent = isNow ? 'Publishing now' : 'Publishing later';
        delivery.className = `smb-confirm-delivery smb-confirm-delivery--${isNow ? 'now' : 'scheduled'}`;
        warning.textContent = isNow
            ? 'After confirmation, these publications will enter the live queue immediately.'
            : 'After confirmation, nothing will publish now: each item will be released at its selected date and time.';
        submit.textContent = isNow ? 'Publish now' : 'Confirm schedule';
        summary.innerHTML = `<ul>${rows.join('')}</ul><p>${escapeHtml(scheduleText)}${isNow ? '' : ` · ${escapeHtml(payload.timezone)}`}</p>`;
        pendingPublishPayload = payload;
        backdrop.hidden = false;
        document.body.classList.add('smb-confirm-open');
    };

    const submitPublishPayload = async () => {
        if (!pendingPublishPayload) return;
        const button = document.querySelector('[data-submit-publish]');
        const isNow = pendingPublishPayload.schedule?.mode === 'now';
        const payload = { ...pendingPublishPayload, confirmation: isNow ? 'PUBLICAR_AHORA' : 'PROGRAMAR' };
        if (button) {
            button.disabled = true;
            button.textContent = isNow ? 'Publicando…' : 'Programando…';
        }
        try {
            const response = await fetch('social_media_schedule.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify(payload),
            });
            const result = await response.json().catch(() => ({}));
            if (!response.ok || !result.ok) throw new Error(result.error || 'The publication could not be scheduled.');
            (result.jobs || []).forEach((job) => {
                const platform = String(job.channel || '');
                const key = String(job.client_key || '');
                if (networkPlatforms.includes(platform) && key) state.scheduled[platform][key] = job;
            });
            closePublishConfirmation();
            renderAll();
            showToast(result.message || (isNow ? `${result.publication_count} publications entered the queue.` : `${result.publication_count} publications scheduled.`), 8000);
            await loadScheduledJobs(true);
        } catch (error) {
            closePublishConfirmation();
            showToast(error.message || 'The publication could not be scheduled.', 0);
        } finally {
            if (button) {
                button.disabled = false;
                button.textContent = isNow ? 'Publish now' : 'Confirm schedule';
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
            const action = state.schedule.mode === 'scheduled' ? 'Review schedule for' : 'Review and publish now';
            confirmLabel.textContent = hasFocus
                ? `${action} ${platformLabels[focusedNetwork]}`
                : state.schedule.mode === 'scheduled' ? 'Review and schedule all' : 'Review and publish now';
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
                    <p>Board: ${escapeHtml(boardName || 'Not selected')}</p>
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
                    ${inspectorValue('Title', mockup.pinterest?.title)}
                    ${inspectorValue('Description', mockup.pinterest?.description)}
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
                    ${inspectorValue('Link description', mockup.facebook?.linkDescription)}
                    ${inspectorValue('CTA', mockup.facebook?.cta)}
                </dl>
            </section>`;
        const metadataSection = `
            <section class="smb-inspector-section">
                <h3>Metadata visual</h3><dl>
                    ${inspectorValue('Description', mockup.metadata?.description)}
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

        kicker.textContent = platform ? `${platformLabels[platform]} data` : 'Publishing data';
        title.textContent = mockup.editorialTitle || mockup.contextTitle || mockup.artworkTitle || 'Mockup';
        body.innerHTML = `
            <figure class="smb-inspector-preview"><img src="${escapeHtml(mockup.image)}" alt="${escapeHtml(mockup.artworkTitle)}"></figure>
            <dl class="smb-inspector-identity">
                ${inspectorValue('Artwork', mockup.artworkTitle)}
                ${inspectorValue('Series', mockup.seriesTitle || 'No series')}
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
        const scheduleFields = state.schedule.mode === 'scheduled' && state.schedule.perPublication ? `
            <div class="smb-item-schedule">
                <input type="date" value="${escapeHtml(pin.date || state.schedule.date)}" data-pin-date aria-label="Pin date">
                <input type="time" value="${escapeHtml(pin.time || state.schedule.time)}" data-pin-time aria-label="Pin time">
            </div>` : '';
        return `
            <article class="smb-pin-card smb-sortable-item${state.schedule.mode === 'scheduled' && state.schedule.perPublication ? ' has-schedule' : ''}" data-board-item data-platform="pinterest" data-mockup-id="${escapeHtml(id)}" data-id="${escapeHtml(id)}" data-index="${index}">
                <span class="smb-pin-drag" data-drag-handle aria-label="Mover Pin" role="button">⋮⋮</span>
                ${scheduledChip(scheduled)}
                <button class="smb-remove-media" type="button" data-remove-media aria-label="Remove from Pinterest">×</button>
                <img src="${escapeHtml(mockup.image)}" alt="${escapeHtml(mockup.artworkTitle)}" data-inspect-mockup draggable="false">
                <label class="smb-pin-field smb-pin-field--title"><span>Title</span><input type="text" value="${escapeHtml(title)}" data-pin-title placeholder="Pin title"></label>
                <label class="smb-pin-field smb-pin-field--description"><span>Description</span><textarea data-pin-description placeholder="Pin description">${escapeHtml(description)}</textarea></label>
                <label class="smb-pin-field smb-pin-field--board"><span>Board</span><select data-pin-board aria-label="Destination board on Pinterest">${pinterestBoardOptions(board)}</select></label>
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
                <button class="smb-remove-media" type="button" data-remove-media aria-label="Remove image">×</button>
                <img src="${escapeHtml(mockup.image)}" alt="${escapeHtml(mockup.artworkTitle)}" data-drag-handle data-inspect-mockup draggable="false">
            </article>`;
    };

    const publicationMarkup = (platform, group, index) => {
        const isInstagram = platform === 'instagram';
        const displayTitle = groupDisplayTitle(platform, group);
        const copyLabel = isInstagram ? 'Caption and hashtags' : 'Publication copy';
        const websiteLabel = isInstagram ? 'Website · enlace en bio' : 'Website';
        const saatchiLabel = isInstagram ? 'Saatchi Art · enlace en bio' : 'Saatchi Art';
        const linkUrl = String(group.linkUrl || configuredDestinations[group.link] || '');
        const scheduled = state.scheduled[platform][group.id] || null;
        const scheduleFields = state.schedule.mode === 'scheduled' && state.schedule.perPublication ? `
            <div class="smb-item-schedule">
                <input type="date" value="${escapeHtml(group.date || state.schedule.date)}" data-group-date aria-label="Publishing date">
                <input type="time" value="${escapeHtml(group.time || state.schedule.time)}" data-group-time aria-label="Publishing time">
            </div>` : '';
        const media = group.items.length
            ? group.items.map((id, itemIndex) => mediaMarkup(platform, group.id, id, itemIndex)).join('')
            : '<div class="smb-publication-empty">Drag mockups into this publication.</div>';

        return `
            <article class="smb-publication-card${state.schedule.mode === 'scheduled' && state.schedule.perPublication ? ' has-schedule' : ''}" data-publication-group="${platform}" data-group-id="${escapeHtml(group.id)}">
                <header class="smb-publication-head">
                    <div class="smb-publication-label"><strong>Publication ${index + 1}</strong><span title="${escapeHtml(displayTitle)}">${escapeHtml(displayTitle)}</span><small>${plural(group.items.length, 'image', 'images')}</small></div>
                    ${scheduledChip(scheduled)}
                    <button type="button" data-remove-publication aria-label="Remove publication">×</button>
                </header>
                <div class="smb-group-carousel-wrap">
                    <div class="smb-group-carousel smb-group-carousel--${platform}" data-group-carousel data-sortable-platform="${platform}" data-sortable-group-id="${escapeHtml(group.id)}">${media}</div>
                    ${isInstagram && group.items.length ? `<span class="smb-carousel-count" data-carousel-counter>1 / ${group.items.length}</span>` : ''}
                </div>
                <div class="smb-publication-details">
                    <label><span>${copyLabel}</span><textarea data-group-copy placeholder="Write the publication copy">${escapeHtml(group.copy || '')}</textarea></label>
                    <label><span>Destination</span><select data-group-link><option value="website"${group.link === 'website' ? ' selected' : ''}>${websiteLabel}</option><option value="saatchi"${group.link === 'saatchi' ? ' selected' : ''}>${saatchiLabel}</option></select><input type="url" value="${escapeHtml(linkUrl)}" data-group-link-url placeholder="https://…" aria-label="Exact publication URL"></label>
                </div>
                ${scheduleFields}
            </article>`;
    };

    const renderPinterest = () => {
        const container = document.querySelector('[data-board-items="pinterest"]');
        const counter = document.querySelector('[data-board-count="pinterest"]');
        if (!container || !counter) return;
        counter.textContent = plural(state.pinterest.length, 'publication', 'publications');
        container.innerHTML = state.pinterest.length
            ? state.pinterest.map(pinMarkup).join('')
            : '<div class="smb-board-empty">Drag mockups here. Each one becomes a Pin.</div>';
    };

    const renderPublicationBoard = (platform) => {
        const stack = document.querySelector(`[data-publication-stack="${platform}"]`);
        const counter = document.querySelector(`[data-board-count="${platform}"]`);
        if (!stack || !counter) return;
        const groups = state.publications[platform];
        counter.textContent = plural(groups.length, 'publication', 'publications');
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
        const scheduleFields = document.querySelector('[data-schedule-fields]');
        if (scheduleFields) scheduleFields.hidden = state.schedule.mode !== 'scheduled';
        document.querySelectorAll('[data-delivery-mode][role="radio"]').forEach((button) => {
            const active = button.dataset.deliveryMode === state.schedule.mode;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-checked', active ? 'true' : 'false');
        });
        const confirmButton = document.querySelector('[data-confirm-schedule]');
        if (confirmButton) {
            confirmButton.disabled = !publicationHistoryReady;
            confirmButton.title = publicationHistoryReady ? '' : 'Loading previous status to avoid duplicates…';
        }
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
                ? 'This Facebook publication already has 3 images.'
                : 'This Instagram carousel already has 10 images.');
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
            showToast('The drag-and-drop system could not be started.');
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
        const scheduleBackdrop = event.target.closest('[data-schedule-backdrop]');
        if (event.target.closest('[data-close-schedule]') || (scheduleBackdrop && event.target === scheduleBackdrop)) {
            closeScheduleDialog();
            return;
        }
        if (event.target.closest('[data-open-schedule]')) {
            state.schedule.mode = 'scheduled';
            renderAll();
            const backdrop = document.querySelector('[data-schedule-backdrop]');
            if (backdrop) backdrop.hidden = false;
            document.body.classList.add('smb-confirm-open');
            return;
        }
        if (event.target.closest('[data-submit-publish]')) {
            await submitPublishPayload();
            return;
        }

        if (event.target.closest('[data-refresh-scheduled]')) {
            await loadScheduledJobs();
            return;
        }

        const historyToggle = event.target.closest('[data-toggle-scheduled]');
        if (historyToggle) {
            const panel = historyToggle.closest('[data-scheduled-panel]');
            const list = panel?.querySelector('[data-scheduled-list]');
            const refresh = panel?.querySelector('[data-refresh-scheduled]');
            const expanded = historyToggle.getAttribute('aria-expanded') === 'true';
            historyToggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
            historyToggle.textContent = expanded ? 'View history' : 'Hide history';
            panel?.classList.toggle('is-collapsed', expanded);
            if (list) list.hidden = expanded;
            if (refresh) refresh.hidden = expanded;
            return;
        }

        const scheduledAction = event.target.closest('[data-scheduled-action]');
        if (scheduledAction) {
            await manageScheduledJob(
                String(scheduledAction.dataset.scheduledAction || ''),
                scheduledAction.closest('[data-scheduled-job]')
            );
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
                if (!response.ok || !result.ok) throw new Error(result.error || 'The favorite could not be updated.');
                const isFavorite = Boolean(result.favorite);
                card.classList.toggle('is-favorite', isFavorite);
                favoriteButton.classList.toggle('active', isFavorite);
                favoriteButton.setAttribute('aria-pressed', isFavorite ? 'true' : 'false');
                favoriteButton.setAttribute('aria-label', isFavorite ? 'Remove from favorites' : 'Add to favorites');
                const mockup = mockupById.get(card.dataset.mockupId);
                if (mockup) mockup.favorite = isFavorite;
                sortCatalog();
            } catch (error) {
                showToast(error.message || 'The favorite could not be updated.');
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
            state.schedule.mode = 'scheduled';
            state.schedule.perPublication = !state.schedule.perPublication;
            renderAll();
            return;
        }

        const confirmSchedule = event.target.closest('[data-confirm-schedule]');
        if (confirmSchedule) {
            if (confirmSchedule.matches('[data-publish-now]')) {
                state.schedule.mode = 'now';
                state.schedule.perPublication = false;
                renderAll();
            }
            if (!publicationHistoryReady) {
                showToast('Wait for publishing history to finish loading to prevent duplicates.');
                return;
            }
            const publicationCount = publicationCountFor(focusedNetwork);
            if (!publicationCount) {
                showToast(focusedNetwork
                    ? `Drag at least one mockup to the ${platformLabels[focusedNetwork]} board.`
                    : 'Drag at least one mockup to one of the three boards.');
                return;
            }
            const payload = buildPublishPayload(focusedNetwork);
            const errors = validatePublishPayload(payload);
            if (errors.length) {
                showToast(errors[0]);
                return;
            }
            closeScheduleDialog();
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
            const schedule = document.querySelector('[data-schedule-backdrop]');
            if (schedule && !schedule.hidden) {
                closeScheduleDialog();
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
            state.schedule.mode = 'scheduled';
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
        pinterestBoardsStatus = 'loading';
        renderAll();
        try {
            const query = new URLSearchParams({ purpose: state.pinterestPurpose });
            const response = await fetch(`social_media_pinterest_boards.php?${query.toString()}`, {
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
        renderScheduledJobs();
    };

    const pinterestPurposeSelect = document.querySelector('[data-pinterest-purpose]');
    const syncPinterestEnvironmentNote = () => {
        const note = document.querySelector('[data-pinterest-sandbox-note]');
        if (!note) return;
        const environment = String(pinterestEnvironments[state.pinterestPurpose] || pinterestEnvironment);
        note.hidden = environment !== 'sandbox';
    };
    syncPinterestEnvironmentNote();
    if (pinterestPurposeSelect) {
        pinterestPurposeSelect.value = state.pinterestPurpose;
        pinterestPurposeSelect.addEventListener('change', () => {
            const purpose = String(pinterestPurposeSelect.value || '');
            if (!configuredPinterestPurposes.includes(purpose) || purpose === state.pinterestPurpose) return;
            state.pinterestPurpose = purpose;
            syncPinterestEnvironmentNote();
            Object.values(state.pinData).forEach((pin) => {
                if (pin && typeof pin === 'object') {
                    pin.board = '';
                    pin.boardName = '';
                }
            });
            state.scheduled.pinterest = {};
            saveState();
            loadPinterestBoards();
        });
    }

    const defaultMoment = new Date(Date.now() + 5 * 60 * 1000);
    const defaultSchedule = localScheduleParts(defaultMoment.toISOString());
    const defaultDate = defaultSchedule.date;
    const defaultTime = defaultSchedule.time;
    const dateInput = document.querySelector('[data-schedule-date]');
    const timeInput = document.querySelector('[data-schedule-time]');
    if (dateInput) dateInput.value = state.schedule.date || defaultDate;
    if (timeInput) timeInput.value = state.schedule.time || defaultTime;
    state.schedule.date = dateInput?.value || defaultDate;
    state.schedule.time = timeInput?.value || defaultTime;
    if (state.schedule.mode !== 'scheduled') {
        state.schedule.mode = 'now';
        state.schedule.perPublication = false;
    }

    sortCatalog();
    renderAll();
    initializeCatalogSortable();
    loadPinterestBoards();
    loadScheduledJobs();
})();

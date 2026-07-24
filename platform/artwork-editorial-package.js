(() => {
    const root = document.querySelector('[data-editorial-package]');
    if (!root) return;

    const summaryState = root.querySelector('[data-package-summary-state]');
    const checklist = root.querySelector('[data-package-checklist]');
    const scope = root.querySelector('[data-package-scope]');
    const status = root.querySelector('[data-package-status]');
    const decision = root.querySelector('[data-package-decision]');
    const actionButton = root.querySelector('[data-package-action]');
    const actionLabel = root.querySelector('[data-package-action-label]');
    const actionHelp = root.querySelector('[data-package-action-help]');
    const initialNode = root.querySelector('[data-package-initial]');
    let audit = null;
    let timer = null;
    let busy = false;

    const addChecklistItem = (label, state, href = '') => {
        const item = document.createElement('li');
        if (state) item.classList.add(`is-${state}`);
        if (href) {
            const link = document.createElement('a');
            link.href = href;
            link.textContent = label;
            item.appendChild(link);
        } else {
            item.textContent = label;
        }
        checklist.appendChild(item);
    };

    const addScopeItem = (label, value) => {
        const row = document.createElement('div');
        const text = document.createElement('span');
        const count = document.createElement('strong');
        text.textContent = label;
        count.textContent = String(value);
        row.append(text, count);
        scope.appendChild(row);
    };

    const setStatus = (title, message, progress = null, errors = []) => {
        status.replaceChildren();
        status.classList.add('is-visible');
        const heading = document.createElement('strong');
        heading.textContent = title;
        const copy = document.createElement('p');
        copy.textContent = message;
        status.append(heading, copy);
        if (progress !== null) {
            const track = document.createElement('div');
            const fill = document.createElement('span');
            track.className = 'editorial-package-progress';
            fill.style.width = `${Math.max(0, Math.min(100, progress))}%`;
            track.appendChild(fill);
            status.appendChild(track);
        }
        if (errors.length) {
            const list = document.createElement('ul');
            list.className = 'editorial-package-errors';
            errors.forEach((entry) => {
                const item = document.createElement('li');
                item.textContent = `${entry.entity_type} #${entry.entity_id}: ${entry.error || 'Preparation failed.'}`;
                list.appendChild(item);
            });
            status.appendChild(list);
        }
    };

    const render = (nextAudit) => {
        audit = nextAudit;
        checklist.replaceChildren();
        scope.replaceChildren();
        status.replaceChildren();
        status.classList.remove('is-visible');
        decision.hidden = true;

        addChecklistItem(
            audit.profile_ready ? 'Artist profile available' : 'Complete the artist profile',
            audit.profile_ready ? 'ready' : '',
            audit.profile_ready ? '' : 'artist_profile.php'
        );
        addChecklistItem(
            audit.title_ready ? `Final title: ${audit.title}` : 'Confirm the artwork title',
            audit.title_ready ? 'ready' : ''
        );
        if (audit.series_optional) {
            addChecklistItem('No series assigned · optional', 'optional', 'series.php');
        } else {
            const seriesTitle = audit.series?.title || 'Assigned series';
            addChecklistItem(
                audit.series?.context_ready ? `Series context: ${seriesTitle}` : `Add instructions to ${seriesTitle}`,
                audit.series?.context_ready ? 'ready' : '',
                audit.series?.context_ready ? '' : 'series.php'
            );
        }
        addChecklistItem(
            audit.mockups_total > 0
                ? `${audit.mockups_total} mockup${audit.mockups_total === 1 ? '' : 's'} available`
                : 'Create or import at least one mockup',
            audit.mockups_total > 0 ? 'ready' : ''
        );

        const pending = audit.editorial_pending || {};
        addScopeItem('Series text', pending.series || 0);
        addScopeItem('Artwork text', pending.artwork || 0);
        addScopeItem('Mockup texts', pending.mockups || 0);

        const pkg = audit.package;
        const active = pkg && ['queued', 'processing'].includes(pkg.status);
        const retryable = pkg && ['failed', 'partial'].includes(pkg.status);
        if (active) {
            const counts = pkg.counts || {};
            const total = Math.max(1, Number(counts.total || 0));
            const completed = Number(counts.completed || 0);
            const progress = Math.round((completed / total) * 100);
            summaryState.textContent = 'In progress';
            setStatus(
                pkg.stage_label ? `Preparing ${pkg.stage_label.toLowerCase()}` : 'Preparing editorial package',
                `${completed} of ${total} editorial items completed. You can leave this page while the work continues.`,
                progress
            );
        } else if (retryable) {
            summaryState.textContent = `${Number(pkg.counts?.failed || 0)} failed`;
            setStatus(
                pkg.status === 'partial' ? 'Package completed with exceptions' : 'Preparation stopped',
                pkg.error || 'Some editorial items need another attempt.',
                null,
                Array.isArray(pkg.failed_items) ? pkg.failed_items : []
            );
            decision.hidden = false;
            actionButton.dataset.action = 'retry_failed';
            actionLabel.textContent = 'Retry Failed Content';
            actionHelp.textContent = 'Only failed items are retried; completed editorial drafts remain unchanged.';
        } else if (Number(pending.total || 0) === 0) {
            summaryState.textContent = 'Complete';
            setStatus(
                'Editorial package complete',
                'The series, artwork and current mockups have complete Spanish and international English drafts.'
            );
        } else if (!audit.prerequisites_ready) {
            summaryState.textContent = `${Number(pending.total || 0)} pending`;
            setStatus(
                'Complete the checklist first',
                'Editorial preparation stays optional and will not interrupt artwork or mockup creation.'
            );
        } else if (audit.can_start) {
            summaryState.textContent = `${Number(pending.total || 0)} pending`;
            decision.hidden = false;
            actionButton.dataset.action = 'start';
            actionLabel.textContent = 'Prepare Editorial Package';
            actionHelp.textContent = `One order will prepare ${Number(pending.total || 0)} pending editorial item${Number(pending.total || 0) === 1 ? '' : 's'}.`;
        }

        schedule(active);
    };

    const showError = (message) => {
        summaryState.textContent = 'Needs attention';
        setStatus('Editorial preparation unavailable', message || 'Please try again.');
    };

    const request = async (action, packageId = 0) => {
        const body = new URLSearchParams({
            csrf: root.dataset.csrf || '',
            artwork_id: root.dataset.artworkId || '',
            action
        });
        if (packageId > 0) body.set('package_id', String(packageId));
        const response = await fetch(root.dataset.endpoint || 'artwork_editorial_package.php', {
            method: 'POST',
            body,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const result = await response.json().catch(() => ({}));
        if (!response.ok || !result.ok || !result.audit) {
            throw new Error(result.error || 'Editorial preparation could not be updated.');
        }
        render(result.audit);
    };

    const schedule = (active) => {
        if (timer) window.clearTimeout(timer);
        timer = active ? window.setTimeout(() => request('status').catch((error) => showError(error.message)), 4000) : null;
    };

    actionButton?.addEventListener('click', async () => {
        if (busy || !audit) return;
        busy = true;
        actionButton.disabled = true;
        const original = actionLabel.textContent;
        actionLabel.textContent = 'Starting…';
        try {
            const action = actionButton.dataset.action || 'start';
            await request(action, Number(audit.package?.id || 0));
        } catch (error) {
            showError(error instanceof Error ? error.message : 'Editorial preparation could not be started.');
            actionLabel.textContent = original;
        } finally {
            busy = false;
            actionButton.disabled = false;
        }
    });

    try {
        const initial = JSON.parse(initialNode?.textContent || '{}');
        if (initial.ok && initial.audit) {
            render(initial.audit);
        } else {
            showError(initial.error || 'Editorial preparation could not be checked.');
        }
    } catch (error) {
        showError('Editorial preparation could not be checked.');
    }
})();

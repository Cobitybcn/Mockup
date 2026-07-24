(() => {
    const editor = document.querySelector('[data-bilingual-editor]');
    if (!editor) return;

    const endpoint = editor.dataset.endpoint || 'bilingual_editorial.php';
    const entityType = editor.dataset.entityType || '';
    const entityId = editor.dataset.entityId || '';
    const csrf = editor.dataset.csrf || '';
    const state = editor.querySelector('[data-bilingual-save-state]');
    const adaptationButton = editor.querySelector('[data-editorial-adapt]');
    const generationButton = editor.querySelector('[data-editorial-generate]');
    const refreshButton = editor.querySelector('[data-editorial-refresh]');
    const useProposalButton = editor.querySelector('[data-editorial-use-proposal]');
    const proposalPanel = editor.querySelector('[data-editorial-proposal]');
    const proposalFields = editor.querySelector('[data-editorial-proposal-fields]');
    const proposalState = editor.querySelector('[data-editorial-proposal-state]');
    const spanishPublicationButton = editor.querySelector('[data-spanish-publication]');
    const keywordResearchPanel = editor.querySelector('#series-keyword-research');
    const keywordImportForm = editor.querySelector('[data-series-keyword-import]');
    const keywordSelectionForm = editor.querySelector('[data-series-keyword-selection]');
    const keywordState = editor.querySelector('[data-series-keyword-state]');
    const timers = new Map();
    let activeProposal = null;
    let activeProposalLocale = '';
    let comparisonFrame = 0;

    const syncComparisonRows = () => {
        comparisonFrame = 0;
        const spread = Array.from(editor.querySelectorAll('.series-bilingual-spread')).find(
            (candidate) => candidate.querySelector('[data-editorial-field]')
        );
        if (!spread) return;
        const sections = Array.from(spread.querySelectorAll('.series-bilingual-field'));
        const copies = Array.from(spread.querySelectorAll('.series-bilingual-copy[data-editorial-field]'));
        sections.forEach((section) => {
            section.style.minHeight = '';
        });
        copies.forEach((copy) => {
            copy.style.minHeight = '';
        });
        spread.querySelectorAll('.series-search-architecture').forEach((details) => {
            details.style.marginTop = '';
        });
        if (window.matchMedia('(max-width: 800px)').matches || spread.getBoundingClientRect().width === 0) return;

        const spanishFields = Array.from(spread.querySelectorAll('[data-editorial-locale="es"][data-editorial-field]'));
        const englishFields = Array.from(spread.querySelectorAll('[data-editorial-locale="en"][data-editorial-field]'));
        spanishFields.forEach((spanishField) => {
            const path = spanishField.dataset.editorialField || '';
            if (!path) return;
            const englishField = englishFields.find(
                (candidate) => candidate.dataset.editorialField === path
            );
            const spanishSection = spanishField.closest('.series-bilingual-field');
            const englishSection = englishField?.closest('.series-bilingual-field');
            if (!spanishSection || !englishSection) return;
            const copyHeight = Math.ceil(Math.max(
                spanishField.scrollHeight,
                englishField.scrollHeight,
                spanishField.getBoundingClientRect().height,
                englishField.getBoundingClientRect().height
            ));
            spanishField.style.minHeight = `${copyHeight}px`;
            englishField.style.minHeight = `${copyHeight}px`;
        });

        const architectures = Array.from(spread.querySelectorAll('.series-search-architecture'));
        if (architectures.length === 2) {
            const firstTop = architectures[0].getBoundingClientRect().top;
            const secondTop = architectures[1].getBoundingClientRect().top;
            const delta = Math.round(firstTop - secondTop);
            if (delta > 0) architectures[1].style.marginTop = `${18 + delta}px`;
            if (delta < 0) architectures[0].style.marginTop = `${18 + Math.abs(delta)}px`;
        }
    };

    const queueComparisonRows = () => {
        if (comparisonFrame) cancelAnimationFrame(comparisonFrame);
        comparisonFrame = requestAnimationFrame(syncComparisonRows);
    };

    editor.querySelectorAll('[data-series-direction-form]').forEach((form) => {
        form.addEventListener('submit', () => {
            form.querySelectorAll('[data-series-direction-copy]').forEach((surface) => {
                const field = surface.dataset.seriesDirectionCopy || '';
                const input = field ? form.querySelector(`[name="${field}"]`) : null;
                if (input) input.value = (surface.innerText || '').trim();
            });
        });
    });

    const setState = (message, kind = '') => {
        if (!state) return;
        state.textContent = message;
        state.dataset.kind = kind;
    };

    const markSpanishPublicationStale = () => {
        if (!spanishPublicationButton || spanishPublicationButton.dataset.action !== 'unpublish_spanish') return;
        spanishPublicationButton.dataset.action = 'publish_spanish';
        spanishPublicationButton.textContent = 'Actualizar español publicado';
        editor.querySelectorAll('[data-spanish-publication-state]').forEach((label) => {
            label.textContent = 'Cambios sin publicar';
        });
    };

    const assignPath = (target, path, value) => {
        const parts = path.split('.').filter(Boolean);
        let cursor = target;
        parts.forEach((part, index) => {
            if (index === parts.length - 1) cursor[part] = value;
            else cursor = cursor[part] ||= {};
        });
    };

    const valueAtPath = (target, path) => path.split('.').filter(Boolean).reduce(
        (value, part) => value && typeof value === 'object' ? value[part] : '',
        target
    );

    const fieldText = (value) => {
        if (Array.isArray(value)) return value.map(fieldText).filter(Boolean).join(', ');
        if (value && typeof value === 'object') return Object.values(value).map(fieldText).filter(Boolean).join('\n');
        return String(value || '').trim();
    };

    const localeContent = (locale) => {
        const content = {};
        editor.querySelectorAll(`[data-editorial-locale="${locale}"][data-editorial-field]`).forEach((field) => {
            // textContent remains reliable while the editorial <details> is
            // collapsed; innerText can report an empty value for hidden rows
            // and incorrectly hide the missing-language arrow.
            assignPath(content, field.dataset.editorialField || '', field.textContent.trim());
        });
        return content;
    };

    const hasMeaningfulContent = (content) => Object.values(content || {}).some(
        (value) => value && typeof value === 'object'
            ? hasMeaningfulContent(value)
            : String(value || '').trim() !== ''
    );

    const hasMissingContent = (source, target) => Object.entries(source || {}).some(([key, value]) => {
        if (value && typeof value === 'object') {
            const targetValue = target && typeof target[key] === 'object' ? target[key] : {};
            return hasMissingContent(value, targetValue);
        }
        return String(value || '').trim() !== '' && String(target?.[key] || '').trim() === '';
    });

    const updateAdaptationButton = () => {
        const spanish = localeContent('es');
        const english = localeContent('en');
        if (refreshButton) {
            refreshButton.disabled = !hasMeaningfulContent(spanish);
            refreshButton.title = refreshButton.disabled
                ? 'Primero generá o escribí el contenido español.'
                : 'Crear y guardar el inglés internacional para el website.';
        }
        if (!adaptationButton) return;
        let source = '';
        let target = '';
        const englishNeedsAdaptation = hasMissingContent(spanish, english)
            || editor.dataset.englishStatus === 'stale';
        if (hasMeaningfulContent(spanish) && englishNeedsAdaptation) {
            source = 'es';
            target = 'en';
        }
        adaptationButton.hidden = source === '';
        if (source === '') return;
        const label = 'Adaptar al inglés internacional sin traducción literal';
        adaptationButton.dataset.sourceLocale = source;
        adaptationButton.dataset.targetLocale = target;
        adaptationButton.dataset.direction = `${source}-${target}`;
        adaptationButton.setAttribute('aria-label', label);
        adaptationButton.title = label;
        const sourceShortLabel = adaptationButton.querySelector('[data-adaptation-source-short]');
        const targetShortLabel = adaptationButton.querySelector('[data-adaptation-target-short]');
        if (sourceShortLabel) sourceShortLabel.textContent = source.toUpperCase();
        if (targetShortLabel) targetShortLabel.textContent = target.toUpperCase();
        const accessibleLabel = adaptationButton.querySelector('[data-adaptation-label]');
        if (accessibleLabel) accessibleLabel.textContent = label;
    };

    const saveLocale = async (locale) => {
        const content = localeContent(locale);
        const memo = editor.querySelector(`[data-private-memo][data-editorial-locale="${locale}"]`);
        const body = new FormData();
        body.append('csrf', csrf);
        body.append('action', 'save_content');
        body.append('entity_type', entityType);
        body.append('entity_id', entityId);
        body.append('locale', locale);
        body.append('content_json', JSON.stringify(content));
        body.append('private_memo', memo ? memo.innerText.trim() : '');
        setState('Guardando…');
        const response = await fetch(endpoint, {method: 'POST', body, headers: {'Accept': 'application/json'}});
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.error || 'No se pudo guardar.');
        if (result.english_status) editor.dataset.englishStatus = result.english_status;
        if (locale === 'es' && result.english_status === 'stale') setState('Español guardado · revisar English', 'stale');
        else setState('Guardado', 'saved');
        updateAdaptationButton();
        return result;
    };

    const fillLocale = (locale, content) => {
        editor.querySelectorAll(`[data-editorial-locale="${locale}"][data-editorial-field]`).forEach((field) => {
            field.textContent = fieldText(valueAtPath(content || {}, field.dataset.editorialField || ''));
        });
        queueComparisonRows();
    };

    const renderProposal = (content, locale) => {
        activeProposal = content;
        activeProposalLocale = locale;
        if (proposalFields) {
            proposalFields.replaceChildren();
            editor.querySelectorAll(`[data-editorial-locale="${locale}"][data-editorial-field]`).forEach((field) => {
                const path = field.dataset.editorialField || '';
                if (!path || path.includes('.')) return;
                const value = fieldText(valueAtPath(content || {}, path));
                if (!value) return;
                const section = document.createElement('section');
                section.className = 'bilingual-assistant-proposal-field';
                const label = document.createElement('label');
                label.textContent = field.closest('section')?.querySelector('label')?.textContent?.trim() || path;
                const copy = document.createElement('div');
                copy.textContent = value;
                section.append(label, copy);
                proposalFields.append(section);
            });
        }
        if (proposalPanel) proposalPanel.hidden = false;
        if (useProposalButton) {
            useProposalButton.hidden = false;
            useProposalButton.textContent = locale === 'en' ? 'Usar en English' : 'Usar esta propuesta';
        }
        if (proposalState) proposalState.textContent = locale === 'en' ? 'English listo para revisar' : 'Propuesta disponible';
    };

    const assistantRequest = async (action, extra = {}) => {
        const body = new FormData();
        body.append('csrf', csrf);
        body.append('action', action);
        body.append('entity_type', entityType);
        body.append('entity_id', entityId);
        Object.entries(extra).forEach(([key, value]) => body.append(key, value));
        const response = await fetch(endpoint, {method: 'POST', body, headers: {'Accept': 'application/json'}});
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.error || 'No se pudo generar la propuesta.');
        return result;
    };

    const keywordResearchRequest = async (form) => {
        const endpointUrl = form.dataset.endpoint || 'series_keyword_research.php';
        const response = await fetch(endpointUrl, {
            method: 'POST',
            body: new FormData(form),
            headers: {'Accept': 'application/json'},
        });
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.error || 'No se pudo guardar la investigación.');
        return result;
    };

    const reopenKeywordResearch = () => {
        window.location.hash = 'series-keyword-research';
        window.location.reload();
    };

    if (window.location.hash === '#series-keyword-research' && keywordResearchPanel) {
        keywordResearchPanel.open = true;
    }

    if (keywordImportForm) {
        const locale = keywordImportForm.querySelector('[name="locale"]');
        const market = keywordImportForm.querySelector('[name="market"]');
        locale?.addEventListener('change', () => {
            if (!market) return;
            if (market.value === 'España' || market.value === 'Estados Unidos') {
                market.value = locale.value === 'en' ? 'Estados Unidos' : 'España';
            }
        });
        keywordImportForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            const button = keywordImportForm.querySelector('button[type="submit"]');
            if (button?.disabled) return;
            if (button) button.disabled = true;
            if (keywordState) keywordState.textContent = 'Importando datos de Keyword Planner…';
            try {
                const result = await keywordResearchRequest(keywordImportForm);
                if (keywordState) {
                    keywordState.textContent = `${result.total || 0} términos procesados · recargando investigación…`;
                }
                reopenKeywordResearch();
            } catch (error) {
                if (keywordState) keywordState.textContent = error.message || 'No se pudo importar la investigación.';
                if (button) button.disabled = false;
            }
        });
    }

    if (keywordSelectionForm) {
        keywordSelectionForm.addEventListener('change', (event) => {
            const checkbox = event.target.closest('input[type="checkbox"][name="selected_ids[]"]');
            checkbox?.closest('tr')?.classList.toggle('is-selected', checkbox.checked);
        });
        keywordSelectionForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            const button = keywordSelectionForm.querySelector('button[type="submit"]');
            if (button?.disabled) return;
            if (button) {
                button.disabled = true;
                button.textContent = 'Guardando…';
            }
            setState('Guardando selección de búsqueda…');
            try {
                await keywordResearchRequest(keywordSelectionForm);
                setState('Selección guardada · volver a preparar contenido', 'saved');
                reopenKeywordResearch();
            } catch (error) {
                setState(error.message || 'No se pudo guardar la selección.', 'error');
                if (button) {
                    button.disabled = false;
                    button.textContent = 'Guardar selección';
                }
            }
        });
    }

    const saveTitle = async (element) => {
        const title = element.innerText.trim();
        const previousTitle = element.dataset.savedTitle || '';
        if (!title) {
            element.textContent = previousTitle;
            setState('El título no puede quedar vacío', 'error');
            return;
        }
        if (title === previousTitle) return;
        const body = new FormData();
        body.append('csrf', csrf);
        body.append('action', 'save_title');
        body.append('entity_type', entityType);
        body.append('entity_id', entityId);
        body.append('title', title);
        setState('Guardando título…');
        const response = await fetch(endpoint, {method: 'POST', body, headers: {'Accept': 'application/json'}});
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.error || 'No se pudo guardar el título.');
        element.textContent = result.title;
        element.dataset.savedTitle = result.title;
        setState('Título guardado', 'saved');
    };

    const schedule = (key, callback) => {
        clearTimeout(timers.get(key));
        timers.set(key, setTimeout(() => callback().catch((error) => setState(error.message, 'error')), 900));
    };

    editor.addEventListener('input', (event) => {
        const field = event.target.closest('[data-editorial-locale]');
        if (field) {
            schedule(`locale:${field.dataset.editorialLocale}`, () => saveLocale(field.dataset.editorialLocale));
            if (field.dataset.editorialLocale === 'es') markSpanishPublicationStale();
            updateAdaptationButton();
            queueComparisonRows();
        }
    });

    editor.querySelectorAll('[data-universal-title]').forEach((title) => {
        title.dataset.savedTitle = title.innerText.trim();
    });

    editor.addEventListener('focusout', (event) => {
        const title = event.target.closest('[data-universal-title]');
        if (title) saveTitle(title).catch((error) => setState(error.message, 'error'));
    });

    editor.addEventListener('toggle', (event) => {
        if (event.target.matches('details')) {
            updateAdaptationButton();
            queueComparisonRows();
        }
    }, true);

    window.addEventListener('resize', queueComparisonRows, {passive: true});
    window.addEventListener('load', queueComparisonRows, {once: true});
    document.fonts?.ready.then(queueComparisonRows);
    queueComparisonRows();
    setTimeout(queueComparisonRows, 120);
    setTimeout(queueComparisonRows, 500);

    editor.addEventListener('keydown', (event) => {
        const title = event.target.closest('[data-universal-title]');
        if (title && event.key === 'Enter') {
            event.preventDefault();
            title.blur();
        }
    });

    editor.addEventListener('click', async (event) => {
        const generate = event.target.closest('[data-editorial-generate]');
        if (generate && !generate.disabled) {
            event.preventDefault();
            generate.disabled = true;
            const originalLabel = generate.textContent;
            generate.textContent = 'Preparando…';
            setState('Preparando contenido español…');
            try {
                const memo = editor.querySelector('[data-private-memo][data-editorial-locale="es"]');
                if (entityType === 'series') {
                    const result = await assistantRequest('prepare_bilingual_series', {
                        current_content_json: JSON.stringify(localeContent('es')),
                        private_memo: memo ? memo.innerText.trim() : '',
                    });
                    fillLocale('es', result.spanish_content || {});
                    fillLocale('en', result.english_content || {});
                } else {
                    const result = await assistantRequest('generate_spanish_draft', {
                        current_content_json: JSON.stringify(localeContent('es')),
                        private_memo: memo ? memo.innerText.trim() : '',
                    });
                    fillLocale('es', result.content || {});
                    await saveLocale('es');
                    const englishResult = await assistantRequest('adapt_missing', {
                        source_locale: 'es',
                        target_locale: 'en',
                    });
                    fillLocale('en', englishResult.content || {});
                    await assistantRequest('publish_spanish');
                }
                activeProposal = null;
                activeProposalLocale = '';
                if (proposalPanel) proposalPanel.hidden = true;
                if (useProposalButton) useProposalButton.hidden = true;
                if (proposalState) proposalState.textContent = 'Contenido preparado';
                setState('Contenido y website preparados', 'saved');
            } catch (error) {
                setState(error.message || 'No se pudo preparar el contenido.', 'error');
            } finally {
                generate.disabled = false;
                generate.textContent = originalLabel;
            }
            return;
        }

        const refresh = event.target.closest('[data-editorial-refresh]');
        if (refresh && !refresh.disabled) {
            event.preventDefault();
            refresh.disabled = true;
            const originalLabel = refresh.textContent;
            refresh.textContent = 'Preparando…';
            setState('Preparando website…');
            try {
                clearTimeout(timers.get('locale:es'));
                await saveLocale('es');
                const result = await assistantRequest('adapt_missing', {
                    source_locale: 'es',
                    target_locale: 'en',
                });
                fillLocale('en', result.content || {});
                activeProposal = null;
                activeProposalLocale = '';
                if (proposalPanel) proposalPanel.hidden = true;
                if (useProposalButton) useProposalButton.hidden = true;
                setState('Website preparado', 'saved');
            } catch (error) {
                setState(error.message || 'No se pudo preparar el website.', 'error');
            } finally {
                refresh.disabled = false;
                refresh.textContent = originalLabel;
            }
            return;
        }

        const useProposal = event.target.closest('[data-editorial-use-proposal]');
        if (useProposal && !useProposal.disabled && activeProposal && activeProposalLocale) {
            event.preventDefault();
            useProposal.disabled = true;
            setState(activeProposalLocale === 'en' ? 'Aplicando English…' : 'Aplicando propuesta…');
            try {
                fillLocale(activeProposalLocale, activeProposal);
                await saveLocale(activeProposalLocale);
                if (proposalPanel) proposalPanel.hidden = true;
                useProposal.hidden = true;
                if (proposalState) proposalState.textContent = 'Aplicada · revisar campos';
                activeProposal = null;
                activeProposalLocale = '';
                setState('Propuesta aplicada · revisar', 'saved');
            } catch (error) {
                setState(error.message || 'No se pudo aplicar la propuesta.', 'error');
            } finally {
                useProposal.disabled = false;
            }
            return;
        }

        const adapt = event.target.closest('[data-editorial-adapt]');
        if (adapt && !adapt.disabled) {
            event.preventDefault();
            const sourceLocale = adapt.dataset.sourceLocale || '';
            const targetLocale = adapt.dataset.targetLocale || '';
            if (!sourceLocale || !targetLocale) return;
            adapt.disabled = true;
            clearTimeout(timers.get(`locale:${sourceLocale}`));
            setState(sourceLocale === 'es' ? 'Adaptando al inglés…' : 'Adaptando al español…');
            try {
                await saveLocale(sourceLocale);
                setState(sourceLocale === 'es' ? 'Adaptando al inglés…' : 'Adaptando al español…');
                const body = new FormData();
                body.append('csrf', csrf);
                body.append('action', 'adapt_missing');
                body.append('entity_type', entityType);
                body.append('entity_id', entityId);
                body.append('source_locale', sourceLocale);
                body.append('target_locale', targetLocale);
                const response = await fetch(endpoint, {method: 'POST', body, headers: {'Accept': 'application/json'}});
                const result = await response.json();
                if (!response.ok || !result.ok) throw new Error(result.error || 'No se pudo adaptar el contenido.');
                fillLocale(targetLocale, result.content || {});
                if (result.english_status) editor.dataset.englishStatus = result.english_status;
                setState(targetLocale === 'en' ? 'Inglés adaptado · revisar' : 'Español adaptado · revisar', 'saved');
                updateAdaptationButton();
            } catch (error) {
                setState(error.message || 'No se pudo adaptar el contenido.', 'error');
            } finally {
                adapt.disabled = false;
            }
            return;
        }

        const button = event.target.closest('[data-spanish-publication]');
        if (!button || button.disabled) return;
        event.preventDefault();
        const publish = button.dataset.action !== 'unpublish_spanish';
        const body = new FormData();
        body.append('csrf', csrf);
        body.append('action', publish ? 'publish_spanish' : 'unpublish_spanish');
        body.append('entity_type', entityType);
        body.append('entity_id', entityId);
        button.disabled = true;
        setState(publish ? 'Publicando español…' : 'Retirando español…');
        try {
            if (publish) {
                clearTimeout(timers.get('locale:es'));
                await saveLocale('es');
                setState('Publicando español…');
            }
            const response = await fetch(endpoint, {method: 'POST', body, headers: {'Accept': 'application/json'}});
            const result = await response.json();
            if (!response.ok || !result.ok) throw new Error(result.error || 'No se pudo actualizar la publicación.');
            const isPublished = Boolean(result.is_published);
            const hasUnpublishedChanges = Boolean(result.has_unpublished_changes);
            editor.querySelectorAll('[data-spanish-publication-state]').forEach((label) => {
                label.textContent = !isPublished ? 'Borrador privado' : (hasUnpublishedChanges ? 'Cambios sin publicar' : 'Español publicado');
            });
            button.dataset.action = !isPublished || hasUnpublishedChanges ? 'publish_spanish' : 'unpublish_spanish';
            button.textContent = !isPublished ? 'Publicar español' : (hasUnpublishedChanges ? 'Actualizar español publicado' : 'Retirar español');
            setState(isPublished ? 'Español publicado' : 'Español retirado', 'saved');
        } catch (error) {
            setState(error.message || 'No se pudo actualizar la publicación.', 'error');
        } finally {
            button.disabled = false;
        }
    });

    updateAdaptationButton();
})();

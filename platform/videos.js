(function () {
    'use strict';

    const carousel = document.querySelector('[data-video-carousel]');
    const arrows = Array.from(document.querySelectorAll('[data-video-carousel-arrow]'));
    const modal = document.querySelector('[data-video-modal]');
    const player = modal?.querySelector('[data-video-modal-player]');
    const modalTitle = modal?.querySelector('[data-video-modal-title]');
    const modalProject = modal?.querySelector('[data-video-modal-project]');
    const artworkFilter = document.querySelector('[data-video-filter-artwork]');
    const seriesFilter = document.querySelector('[data-video-filter-series]');
    const visibleCount = document.querySelector('[data-video-visible-count]');
    const noResults = document.querySelector('[data-video-no-results]');
    let cards = Array.from(document.querySelectorAll('[data-video-card]'));
    const uploadModal = document.querySelector('[data-final-upload-modal]');
    const uploadForm = uploadModal?.querySelector('[data-final-upload-form]');
    const uploadError = uploadModal?.querySelector('[data-final-upload-error]');

    async function postJson(endpoint, payload) {
        const response = await fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ csrf: uploadForm?.querySelector('[name="csrf"]')?.value || '', ...payload }),
        });
        const data = await response.json().catch(() => null);
        if (data === null) throw new Error(`The server answered ${response.status} without a reason. Reload the page and try again.`);
        if (!response.ok || !data.ok) throw new Error(data.error || `The request failed (${response.status}).`);
        return data;
    }

    function applyFilters() {
        const artworkId = String(artworkFilter?.value || '');
        const seriesId = String(seriesFilter?.value || '');
        let visible = 0;
        cards.forEach(card => {
            const matches = (!artworkId || card.dataset.artworkId === artworkId)
                && (!seriesId || card.dataset.seriesId === seriesId);
            card.hidden = !matches;
            if (matches) visible += 1;
        });
        if (visibleCount) visibleCount.textContent = String(visible);
        if (noResults) noResults.hidden = visible > 0;
        if (carousel) carousel.scrollLeft = 0;
        window.requestAnimationFrame(updateArrows);
    }

    artworkFilter?.addEventListener('change', applyFilters);
    seriesFilter?.addEventListener('change', applyFilters);

    function updateArrows() {
        if (!carousel || !arrows.length) return;
        const max = Math.max(0, carousel.scrollWidth - carousel.clientWidth);
        arrows.forEach(arrow => {
            const direction = Number(arrow.dataset.videoCarouselArrow || 0);
            arrow.disabled = direction < 0 ? carousel.scrollLeft <= 3 : carousel.scrollLeft >= max - 3;
        });
    }

    arrows.forEach(arrow => {
        arrow.addEventListener('click', () => {
            if (!carousel) return;
            const direction = Number(arrow.dataset.videoCarouselArrow || 0);
            carousel.scrollBy({ left: direction * Math.max(260, carousel.clientWidth * .72), behavior: 'smooth' });
        });
    });

    carousel?.addEventListener('scroll', updateArrows, { passive: true });
    window.addEventListener('resize', updateArrows);
    window.requestAnimationFrame(updateArrows);

    function openPreview(trigger) {
        if (!modal || !player) return;
        const url = String(trigger.dataset.videoPreview || '');
        if (!url) return;
        player.src = url;
        player.load();
        if (modalTitle) modalTitle.textContent = String(trigger.dataset.videoTitle || 'Video');
        if (modalProject) modalProject.textContent = String(trigger.dataset.videoProject || '');
        modal.hidden = false;
        document.body.classList.add('has-video-modal');
        modal.querySelector('[data-video-modal-close]')?.focus();
    }

    function closePreview() {
        if (!modal || !player) return;
        player.pause();
        player.removeAttribute('src');
        player.load();
        modal.hidden = true;
        document.body.classList.remove('has-video-modal');
    }

    function openFinalUpload() {
        if (!uploadModal) return;
        uploadModal.hidden = false;
        document.body.classList.add('has-video-modal');
        uploadModal.querySelector('select')?.focus();
    }

    function closeFinalUpload() {
        if (!uploadModal) return;
        uploadModal.hidden = true;
        document.body.classList.remove('has-video-modal');
        if (uploadError) uploadError.hidden = true;
    }

    uploadForm?.addEventListener('submit', async event => {
        event.preventDefault();
        const submit = uploadForm.querySelector('[type="submit"]');
        if (submit) { submit.disabled = true; submit.textContent = 'Subiendo…'; }
        if (uploadError) uploadError.hidden = true;
        try {
            const form = new FormData(uploadForm);
            const file = form.get('video');

            // Cloud Run refuses a request over 32 MiB, which a finished video
            // passes easily. Ask for a place in the bucket and put it there
            // directly; the server then only needs the key.
            if (file instanceof File && file.size > 0) {
                const destination = await postJson('video_final_upload_url.php', {
                    projectId: form.get('projectId'),
                    fileName: file.name,
                    contentType: file.type || 'video/mp4',
                    bytes: file.size,
                });
                if (destination.uploadUrl) {
                    if (submit) submit.textContent = 'Subiendo al almacenamiento…';
                    const put = await fetch(destination.uploadUrl, {
                        method: 'PUT',
                        body: file,
                        headers: { 'Content-Type': destination.contentType },
                    });
                    if (!put.ok) throw new Error(`El almacenamiento rechazó el archivo (${put.status}).`);
                    form.delete('video');
                    form.set('objectKey', destination.objectKey);
                    form.set('originalName', file.name);
                    if (submit) submit.textContent = 'Registrando…';
                }
            }

            // RequestSecurity guards this endpoint and answers in plain text
            // unless the request states it wants JSON — without this its reason
            // arrives as an unreadable body.
            const response = await fetch('video_final_upload.php', {
                method: 'POST',
                body: form,
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const payload = await response.json().catch(() => null);
            if (payload === null) {
                // A non-JSON body means the request never reached the handler —
                // say so with the status instead of a message that explains nothing.
                throw new Error(`The server answered ${response.status} without a reason. Reload the page and try again.`);
            }
            if (!response.ok || !payload.ok) throw new Error(payload.error || `The upload failed (${response.status}).`);
            window.location.reload();
        } catch (error) {
            if (uploadError) {
                uploadError.textContent = error instanceof Error ? error.message : 'The final video could not be uploaded.';
                uploadError.hidden = false;
            }
        } finally {
            if (submit) { submit.disabled = false; submit.textContent = 'Upload video'; }
        }
    });

    document.querySelectorAll('[data-delete-generation]').forEach(button => {
        button.addEventListener('click', async () => {
            const generationId = Number(button.dataset.deleteGeneration || 0);
            const label = String(button.dataset.generationLabel || 'este video');
            const active = button.dataset.generationActive === '1';
            const warning = active
                ? `\n\nEste es el resultado ACTUAL de una secuencia. Esa secuencia quedará sin video generado.`
                : '';
            if (!generationId || !window.confirm(`¿Eliminar definitivamente “${label}”?\n\nSe borrarán el MP4, la miniatura y sus referencias. Esta acción no se puede deshacer.${warning}`)) return;
            const card = button.closest('[data-video-card]');
            card?.classList.add('is-deleting');
            button.disabled = true;
            try {
                const result = await postJson('video_api.php', { action:'generation_delete', generationId });
                cards = cards.filter(candidate => candidate !== card);
                card?.remove();
                applyFilters();
                if (cards.length === 0) window.location.reload();
                const mb = Number(result.freedBytes || 0) / 1048576;
                if (visibleCount) visibleCount.title = mb > 0 ? `${mb.toFixed(1)} MB liberados` : 'Video eliminado del almacenamiento';
            } catch (error) {
                card?.classList.remove('is-deleting');
                button.disabled = false;
                window.alert(error instanceof Error ? error.message : 'No se pudo eliminar el video.');
            }
        });
    });

    document.querySelectorAll('[data-final-artwork-form]').forEach(form => {
        form.addEventListener('submit', async event => {
            event.preventDefault();
            const submit = form.querySelector('[type="submit"]');
            const error = form.querySelector('[data-final-artwork-error]');
            if (submit) { submit.disabled = true; submit.textContent = 'Guardando…'; }
            if (error) error.hidden = true;
            try {
                const response = await fetch('video_final_artwork.php', {
                    method: 'POST', body: new FormData(form), credentials: 'same-origin'
                });
                const payload = await response.json().catch(() => ({}));
                if (!response.ok || !payload.ok) throw new Error(payload.error || 'The artwork could not be associated.');
                window.location.reload();
            } catch (cause) {
                if (error) {
                    error.textContent = cause instanceof Error ? cause.message : 'The artwork could not be associated.';
                    error.hidden = false;
                }
            } finally {
                if (submit) { submit.disabled = false; submit.textContent = 'Save'; }
            }
        });
    });

    document.addEventListener('click', event => {
        if (event.target.closest('[data-open-final-upload]')) { event.preventDefault(); openFinalUpload(); return; }
        if (event.target.closest('[data-close-final-upload]')) { closeFinalUpload(); return; }
        const preview = event.target.closest('[data-video-preview]');
        if (preview) {
            event.preventDefault();
            openPreview(preview);
            return;
        }
        if (event.target.closest('[data-video-modal-close]')) closePreview();
    });

    document.addEventListener('keydown', event => {
        if (event.key !== 'Escape') return;
        if (modal && !modal.hidden) closePreview();
        if (uploadModal && !uploadModal.hidden) closeFinalUpload();
    });
})();

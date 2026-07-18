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
    const cards = Array.from(document.querySelectorAll('[data-video-card]'));
    const uploadModal = document.querySelector('[data-final-upload-modal]');
    const uploadForm = uploadModal?.querySelector('[data-final-upload-form]');
    const uploadError = uploadModal?.querySelector('[data-final-upload-error]');

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
            const response = await fetch('video_final_upload.php', { method: 'POST', body: new FormData(uploadForm), credentials: 'same-origin' });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok || !payload.ok) throw new Error(payload.error || 'No se pudo subir el video final.');
            window.location.reload();
        } catch (error) {
            if (uploadError) {
                uploadError.textContent = error instanceof Error ? error.message : 'No se pudo subir el video final.';
                uploadError.hidden = false;
            }
        } finally {
            if (submit) { submit.disabled = false; submit.textContent = 'Subir video'; }
        }
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
                if (!response.ok || !payload.ok) throw new Error(payload.error || 'No se pudo asociar la obra.');
                window.location.reload();
            } catch (cause) {
                if (error) {
                    error.textContent = cause instanceof Error ? cause.message : 'No se pudo asociar la obra.';
                    error.hidden = false;
                }
            } finally {
                if (submit) { submit.disabled = false; submit.textContent = 'Guardar'; }
            }
        });
    });

    document.addEventListener('click', event => {
        if (event.target.closest('[data-open-final-upload]')) { openFinalUpload(); return; }
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

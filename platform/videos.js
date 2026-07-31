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
            if (!response.ok || !payload.ok) throw new Error(payload.error || 'The final video could not be uploaded.');
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

    document.querySelectorAll('[data-final-tiktok-form]').forEach(form => {
        form.addEventListener('submit', async event => {
            event.preventDefault();
            const submit = form.querySelector('[type="submit"]');
            const error = form.querySelector('[data-final-tiktok-error]');
            if (!submit || submit.disabled) return;
            const originalLabel = submit.textContent;
            submit.disabled = true;
            submit.textContent = '…';
            if (error) error.hidden = true;
            try {
                const response = await fetch('video_tiktok_publish.php', {
                    method: 'POST', body: new FormData(form), credentials: 'same-origin'
                });
                const payload = await response.json().catch(() => ({}));
                if (!response.ok || !payload.ok) throw new Error(payload.error || 'The video could not be sent to TikTok.');
                window.location.reload();
            } catch (cause) {
                submit.disabled = false;
                submit.textContent = originalLabel;
                if (error) {
                    error.textContent = cause instanceof Error ? cause.message : 'The video could not be sent to TikTok.';
                    error.hidden = false;
                }
            }
        });
    });

    document.querySelectorAll('[data-final-tiktok-caption]').forEach(textarea => {
        const counter = textarea.closest('form')?.querySelector('[data-final-tiktok-counter]');
        const updateCounter = () => { if (counter) counter.textContent = `${textarea.value.length}/2200`; };
        textarea.addEventListener('input', updateCounter);
        updateCounter();
    });

    document.querySelectorAll('[data-final-tiktok-suggest]').forEach(button => {
        button.addEventListener('click', async () => {
            const form = button.closest('form');
            const textarea = form?.querySelector('[data-final-tiktok-caption]');
            const error = form?.querySelector('[data-final-tiktok-suggest-error]');
            if (!form || !textarea || button.disabled) return;
            const originalLabel = button.textContent;
            button.disabled = true;
            button.textContent = '…';
            if (error) error.hidden = true;
            try {
                const body = new FormData();
                body.set('csrf', form.querySelector('[name="csrf"]')?.value || '');
                body.set('exportId', form.querySelector('[name="exportId"]')?.value || '');
                const response = await fetch('video_tiktok_suggest.php', {
                    method: 'POST', body, credentials: 'same-origin'
                });
                const payload = await response.json().catch(() => ({}));
                if (!response.ok || !payload.ok) throw new Error(payload.error || 'Could not suggest a caption.');
                const copy = payload.copy || {};
                const hashtags = Array.isArray(copy.hashtags) ? copy.hashtags.join(' ') : '';
                const suggested = [copy.caption || '', hashtags].filter(Boolean).join('\n\n').slice(0, 2200);
                if (suggested) {
                    textarea.value = suggested;
                    textarea.dispatchEvent(new Event('input'));
                }
            } catch (cause) {
                if (error) {
                    error.textContent = cause instanceof Error ? cause.message : 'Could not suggest a caption.';
                    error.hidden = false;
                }
            } finally {
                button.disabled = false;
                button.textContent = originalLabel;
            }
        });
    });

    document.querySelectorAll('[data-final-tiktok-status-form]').forEach(form => {
        form.addEventListener('submit', async event => {
            event.preventDefault();
            const submit = form.querySelector('[type="submit"]');
            const result = form.querySelector('[data-final-tiktok-status-result]');
            if (!submit || submit.disabled) return;
            const originalLabel = submit.textContent;
            submit.disabled = true;
            submit.textContent = '…';
            if (result) result.hidden = true;
            try {
                const response = await fetch('video_tiktok_status.php', {
                    method: 'POST', body: new FormData(form), credentials: 'same-origin'
                });
                const payload = await response.json().catch(() => ({}));
                if (!response.ok || !payload.ok) throw new Error(payload.error || 'Could not check the TikTok status.');
                const status = payload.publication && payload.publication.status;
                if (status === 'published' || status === 'failed') {
                    window.location.reload();
                    return;
                }
                submit.disabled = false;
                submit.textContent = originalLabel;
                if (result) {
                    result.textContent = 'TikTok is still processing this video. Try again in a moment.';
                    result.hidden = false;
                }
            } catch (cause) {
                submit.disabled = false;
                submit.textContent = originalLabel;
                if (result) {
                    result.textContent = cause instanceof Error ? cause.message : 'Could not check the TikTok status.';
                    result.hidden = false;
                }
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

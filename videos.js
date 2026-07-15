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

    document.addEventListener('click', event => {
        const preview = event.target.closest('[data-video-preview]');
        if (preview) {
            event.preventDefault();
            openPreview(preview);
            return;
        }
        if (event.target.closest('[data-video-modal-close]')) closePreview();
    });

    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && modal && !modal.hidden) closePreview();
    });
})();

(function () {
    'use strict';

    function cardsForSeries(list, seriesId) {
        return Array.from(list.querySelectorAll('[data-series-artwork-id]'))
            .filter(card => card.getAttribute('data-series-id') === String(seriesId));
    }

    function applyVisibleOrder(cards, positions) {
        cards.forEach((card, index) => {
            const artworkId = card.getAttribute('data-series-artwork-id') || '';
            const fallbackPosition = cards.length - index;
            const fallbackNumber = fallbackPosition * 10;
            const serverPosition = positions && positions[artworkId] ? positions[artworkId] : null;
            const creationNumber = Number(serverPosition?.number || fallbackNumber);
            const ordinal = Number(serverPosition?.position || fallbackPosition);
            const prefix = card.getAttribute('data-series-prefix') || '';
            const identifier = String(serverPosition?.identifier || (prefix ? prefix + String(creationNumber).padStart(3, '0') : ''));

            card.querySelectorAll('[data-series-order-position]').forEach(node => {
                node.textContent = String(ordinal).padStart(2, '0');
            });
            card.querySelectorAll('[data-series-creation-id]').forEach(node => {
                node.textContent = identifier;
                node.hidden = identifier === '';
            });
            card.querySelectorAll('input[name="creation_number"]').forEach(input => {
                input.value = String(creationNumber);
            });
        });
    }

    async function saveOrder(list, seriesId, cards) {
        const body = new FormData();
        body.append('csrf', list.getAttribute('data-series-order-csrf') || '');
        body.append('series_id', String(seriesId));
        cards.forEach(card => body.append('artwork_ids[]', card.getAttribute('data-series-artwork-id') || ''));

        const response = await fetch(list.getAttribute('data-series-order-endpoint') || 'reorder_series_artworks.php', {
            method: 'POST',
            body: body,
            headers: { Accept: 'application/json' }
        });
        const data = await response.json().catch(() => ({ ok: false, error: 'The new order could not be saved.' }));
        if (!response.ok || !data.ok) {
            throw new Error(data.error || 'The new order could not be saved.');
        }
        return data.positions || {};
    }

    function initializeList(list) {
        if (typeof window.Sortable !== 'function') return;
        const sortableSeries = new Set(
            Array.from(list.querySelectorAll('[data-series-artwork-id]'))
                .map(card => card.getAttribute('data-series-id') || '')
                .filter(seriesId => seriesId !== '' && seriesId !== '0')
                .filter(seriesId => cardsForSeries(list, seriesId).length > 1)
        );
        if (!sortableSeries.size) return;

        list.classList.add('series-order-enabled');
        Array.from(list.querySelectorAll('[data-series-artwork-id]')).forEach(card => {
            card.classList.toggle('series-order-card', sortableSeries.has(card.getAttribute('data-series-id') || ''));
        });

        let saving = false;
        let orderBeforeDrag = [];
        const sortable = window.Sortable.create(list, {
            animation: 160,
            draggable: '.series-order-card',
            handle: '[data-series-drag-thumb]',
            filter: 'input, select, option, button, textarea, [data-no-series-drag]',
            preventOnFilter: false,
            ignore: '',
            delay: 160,
            delayOnTouchOnly: true,
            touchStartThreshold: 5,
            fallbackTolerance: 5,
            ghostClass: 'series-order-ghost',
            chosenClass: 'series-order-chosen',
            dragClass: 'series-order-drag',
            onStart: function () {
                orderBeforeDrag = Array.from(list.children);
            },
            onMove: function (event) {
                if (saving) return false;
                const relatedCard = event.related?.closest?.('[data-series-artwork-id]');
                if (!relatedCard) return false;
                return event.dragged.getAttribute('data-series-id') === relatedCard.getAttribute('data-series-id');
            },
            onEnd: async function (event) {
                if (event.oldDraggableIndex === event.newDraggableIndex) return;
                const seriesId = Number(event.item.getAttribute('data-series-id') || 0);
                if (seriesId <= 0) return;
                const cards = cardsForSeries(list, seriesId);
                applyVisibleOrder(cards, null);
                saving = true;
                sortable.option('disabled', true);
                try {
                    const positions = await saveOrder(list, seriesId, cards);
                    applyVisibleOrder(cards, positions);
                } catch (error) {
                    orderBeforeDrag.forEach(card => list.appendChild(card));
                    applyVisibleOrder(cardsForSeries(list, seriesId), null);
                    alert(error?.message || 'The new order could not be saved.');
                } finally {
                    orderBeforeDrag = [];
                    saving = false;
                    sortable.option('disabled', false);
                }
            }
        });
    }

    document.querySelectorAll('[data-series-order-list]').forEach(initializeList);
})();

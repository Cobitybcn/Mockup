(function () {
    'use strict';

    function seriesTiles(list) {
        return Array.from(list.querySelectorAll('[data-series-sort-id]'));
    }

    async function saveOrder(list) {
        const body = new FormData();
        body.append('csrf', list.getAttribute('data-series-sort-csrf') || '');
        seriesTiles(list).forEach(tile => {
            body.append('series_ids[]', tile.getAttribute('data-series-sort-id') || '');
        });

        const response = await fetch(list.getAttribute('data-series-sort-endpoint') || 'reorder_series.php', {
            method: 'POST',
            body: body,
            headers: { Accept: 'application/json' }
        });
        const data = await response.json().catch(() => ({ ok: false, error: 'The new series order could not be saved.' }));
        if (!response.ok || !data.ok) {
            throw new Error(data.error || 'The new series order could not be saved.');
        }
    }

    function initializeList(list) {
        if (typeof window.Sortable !== 'function' || seriesTiles(list).length < 2) return;

        let saving = false;
        let orderBeforeMove = [];
        const rememberOrder = () => {
            orderBeforeMove = Array.from(list.children);
        };
        const restoreOrder = () => {
            orderBeforeMove.forEach(child => list.appendChild(child));
        };
        const persist = async () => {
            saving = true;
            list.classList.add('series-sort-saving');
            sortable.option('disabled', true);
            try {
                await saveOrder(list);
            } catch (error) {
                restoreOrder();
                alert(error?.message || 'The new series order could not be saved.');
            } finally {
                orderBeforeMove = [];
                saving = false;
                list.classList.remove('series-sort-saving');
                sortable.option('disabled', false);
            }
        };

        list.classList.add('series-sort-enabled');
        const sortable = window.Sortable.create(list, {
            animation: 160,
            draggable: '[data-series-sort-id]',
            handle: '[data-series-drag-tile]',
            delay: 160,
            delayOnTouchOnly: true,
            touchStartThreshold: 5,
            fallbackTolerance: 5,
            ghostClass: 'series-order-ghost',
            chosenClass: 'series-order-chosen',
            dragClass: 'series-order-drag',
            onStart: rememberOrder,
            onMove: function (event) {
                if (saving) return false;
                return Boolean(event.related?.closest?.('[data-series-sort-id]'));
            },
            onEnd: function (event) {
                if (event.oldDraggableIndex === event.newDraggableIndex) return;
                persist();
            }
        });

        list.addEventListener('keydown', function (event) {
            if (!event.altKey || (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') || saving) return;
            const tile = event.target.closest('[data-series-sort-id]');
            if (!tile || !list.contains(tile)) return;
            const tiles = seriesTiles(list);
            const currentIndex = tiles.indexOf(tile);
            const targetIndex = currentIndex + (event.key === 'ArrowLeft' ? -1 : 1);
            if (currentIndex < 0 || targetIndex < 0 || targetIndex >= tiles.length) return;

            event.preventDefault();
            rememberOrder();
            if (event.key === 'ArrowLeft') {
                list.insertBefore(tile, tiles[targetIndex]);
            } else {
                list.insertBefore(tiles[targetIndex], tile);
            }
            tile.focus();
            persist();
        });
    }

    document.querySelectorAll('[data-series-sort-list]').forEach(initializeList);
})();

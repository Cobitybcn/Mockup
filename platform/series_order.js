(function () {
    'use strict';

    const list = document.querySelector('[data-series-order-grid]');
    if (!list || typeof window.Sortable !== 'function') return;

    const status = document.querySelector('[data-series-order-status]');
    let saving = false;

    function setStatus(message, isError) {
        if (!status) return;
        status.textContent = message;
        status.classList.toggle('is-error', Boolean(isError));
    }

    async function saveOrder() {
        const body = new FormData();
        body.append('csrf', list.getAttribute('data-series-order-csrf') || '');
        body.append('app_csrf', list.getAttribute('data-series-order-app-csrf') || '');
        list.querySelectorAll('[data-series-order-id]').forEach(item => {
            body.append('series_ids[]', item.getAttribute('data-series-order-id') || '');
        });

        const response = await fetch(list.getAttribute('data-series-order-endpoint') || 'reorder_series.php', {
            method: 'POST',
            body,
            headers: { Accept: 'application/json' }
        });
        const data = await response.json().catch(() => ({ ok: false, error: 'No se pudo guardar el orden.' }));
        if (!response.ok || !data.ok) {
            throw new Error(data.error || 'No se pudo guardar el orden.');
        }
    }

    window.Sortable.create(list, {
        animation: 160,
        draggable: '[data-series-order-id]',
        handle: '[data-series-order-handle]',
        filter: 'button, input, textarea, select, [data-no-series-order]',
        preventOnFilter: false,
        forceFallback: true,
        fallbackTolerance: 4,
        onStart: function () {
            setStatus('Moviendo…', false);
        },
        onEnd: async function (event) {
            if (saving || event.oldIndex === event.newIndex) {
                setStatus('', false);
                return;
            }
            saving = true;
            list.classList.add('is-saving-order');
            setStatus('Guardando orden…', false);
            try {
                await saveOrder();
                setStatus('Orden guardado', false);
            } catch (error) {
                if (event.item && event.from) {
                    const reference = event.from.children[event.oldIndex] || null;
                    event.from.insertBefore(event.item, reference);
                }
                setStatus(error instanceof Error ? error.message : 'No se pudo guardar el orden.', true);
            } finally {
                saving = false;
                list.classList.remove('is-saving-order');
            }
        }
    });
})();

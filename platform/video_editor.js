(function () {
    'use strict';
    const form = document.querySelector('[data-video-editor-form]');
    if (!form) return;
    const input = form.querySelector('[data-editor-images]');
    const list = form.querySelector('[data-editor-image-list]');
    const errorBox = form.querySelector('[data-editor-error]');
    const state = form.querySelector('[data-editor-state]');
    const submit = form.querySelector('[type="submit"]');
    const result = document.querySelector('[data-editor-result]');
    const resultVideo = result?.querySelector('[data-editor-result-video]');
    const download = result?.querySelector('[data-editor-download]');
    let pollTimer = 0;

    input?.addEventListener('change', () => {
        const files = Array.from(input.files || []);
        if (files.length > 10) {
            input.value = '';
            list.innerHTML = '';
            showError('Puedes añadir hasta 10 imágenes.');
            return;
        }
        hideError();
        list.innerHTML = files.map((file, index) => `<figure><img src="${URL.createObjectURL(file)}" alt="Imagen ${index + 1}"><span>${index + 1}</span></figure>`).join('');
    });

    function showError(message) { if (errorBox) { errorBox.textContent = message; errorBox.hidden = false; } }
    function hideError() { if (errorBox) errorBox.hidden = true; }
    function setBusy(busy, label) {
        if (submit) { submit.disabled = busy; submit.textContent = busy ? 'Procesando…' : 'Crear nueva versión'; }
        if (state) state.textContent = label;
    }

    async function poll(jobId) {
        window.clearTimeout(pollTimer);
        try {
            const response = await fetch(`video_editor_status.php?jobId=${jobId}`, { credentials: 'same-origin' });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok || !payload.ok) throw new Error(payload.error || 'No se pudo consultar la edición.');
            const job = payload.job || {};
            if (job.status === 'succeeded') {
                setBusy(false, 'Edición completada');
                if (result && resultVideo && download) {
                    result.hidden = false;
                    resultVideo.src = job.previewUrl;
                    download.href = `${job.previewUrl}&download=1`;
                    result.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
                return;
            }
            if (job.status === 'failed') throw new Error(job.error || 'Omni no pudo completar la edición.');
            setBusy(true, 'Omni está creando la nueva versión…');
            pollTimer = window.setTimeout(() => poll(jobId), 5000);
        } catch (error) {
            setBusy(false, 'No se pudo completar');
            showError(error instanceof Error ? error.message : 'No se pudo consultar la edición.');
        }
    }

    form.addEventListener('submit', async event => {
        event.preventDefault();
        hideError();
        setBusy(true, 'Enviando a Omni…');
        try {
            const response = await fetch('video_editor_start.php', { method: 'POST', body: new FormData(form), credentials: 'same-origin' });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok || !payload.ok) throw new Error(payload.error || 'No se pudo iniciar la edición.');
            poll(Number(payload.job?.id || 0));
        } catch (error) {
            setBusy(false, 'Listo para editar');
            showError(error instanceof Error ? error.message : 'No se pudo iniciar la edición.');
        }
    });
})();

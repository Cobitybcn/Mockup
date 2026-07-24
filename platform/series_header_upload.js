(() => {
    document.querySelectorAll('[data-series-header-upload]').forEach((form) => {
        const input = form.querySelector('[data-series-header-file]');
        const dropzone = form.querySelector('[data-series-header-dropzone]')
            || document.querySelector('[data-series-header-dropzone]');
        const label = dropzone?.querySelector('[data-series-header-label]')
            || document.querySelector('[data-series-header-label]');
        const status = form.querySelector('[data-series-header-status]');
        if (!input || !dropzone || !label) return;

        const beginUpload = (file, fromDrop) => {
            if (!file) return;
            if (!/^image\/(jpeg|png|webp)$/i.test(file.type)) {
                if (status) status.textContent = 'Elige una imagen JPG, PNG o WebP.';
                return;
            }
            if (file.size > 15 * 1024 * 1024) {
                if (status) status.textContent = 'La imagen debe pesar 15 MB o menos.';
                return;
            }
            if (fromDrop) {
                try {
                    const transfer = new DataTransfer();
                    transfer.items.add(file);
                    input.files = transfer.files;
                } catch (error) {
                    if (status) status.textContent = 'Haz clic en la portada para seleccionar el archivo.';
                    return;
                }
            }
            form.setAttribute('aria-busy', 'true');
            dropzone.classList.remove('is-dragging');
            label.textContent = 'Subiendo…';
            if (status) status.textContent = file.name;
            form.requestSubmit();
        };

        input.addEventListener('change', () => {
            beginUpload(input.files?.[0], false);
        });
        dropzone.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter' && event.key !== ' ') return;
            event.preventDefault();
            input.click();
        });
        ['dragenter', 'dragover'].forEach((eventName) => {
            dropzone.addEventListener(eventName, (event) => {
                event.preventDefault();
                dropzone.classList.add('is-dragging');
            });
        });
        dropzone.addEventListener('dragleave', () => {
            dropzone.classList.remove('is-dragging');
        });
        dropzone.addEventListener('drop', (event) => {
            event.preventDefault();
            beginUpload(event.dataTransfer?.files[0], true);
        });
    });
})();

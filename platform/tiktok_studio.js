(function () {
    'use strict';

    const pageCsrf = document.body.dataset.csrf || '';

    async function postForm(url, body) {
        const response = await fetch(url, { method: 'POST', body, credentials: 'same-origin' });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok || !payload.ok) throw new Error(payload.error || 'The request could not be completed.');
        return payload;
    }

    document.querySelectorAll('[data-tstudio-caption]').forEach(textarea => {
        const counter = textarea.closest('form')?.querySelector('[data-tstudio-counter]');
        const update = () => { if (counter) counter.textContent = `${textarea.value.length}/2200`; };
        textarea.addEventListener('input', update);
        update();
    });

    document.querySelectorAll('[data-tstudio-suggest]').forEach(button => {
        button.addEventListener('click', async () => {
            const form = button.closest('form');
            const textarea = form?.querySelector('[data-tstudio-caption]');
            if (!form || !textarea || button.disabled) return;
            const originalLabel = button.textContent;
            button.disabled = true;
            button.textContent = '…';
            try {
                const body = new FormData();
                body.set('csrf', form.querySelector('[name="csrf"]')?.value || pageCsrf);
                body.set('exportId', button.dataset.exportId || '');
                const payload = await postForm('video_tiktok_suggest.php', body);
                const copy = payload.copy || {};
                const hashtags = Array.isArray(copy.hashtags) ? copy.hashtags.join(' ') : '';
                const suggested = [copy.caption || '', hashtags].filter(Boolean).join('\n\n').slice(0, 2200);
                if (suggested) {
                    textarea.value = suggested;
                    textarea.dispatchEvent(new Event('input'));
                }
            } catch (cause) {
                window.alert(cause instanceof Error ? cause.message : 'Could not suggest a caption.');
            } finally {
                button.disabled = false;
                button.textContent = originalLabel;
            }
        });
    });

    document.querySelectorAll('[data-tstudio-assign-form]').forEach(form => {
        form.addEventListener('submit', async event => {
            event.preventDefault();
            try {
                await postForm('tiktok_board_assign.php', new FormData(form));
                window.location.reload();
            } catch (cause) {
                window.alert(cause instanceof Error ? cause.message : 'Could not add the video to that date.');
            }
        });
    });

    document.querySelectorAll('[data-tstudio-unassign-form]').forEach(form => {
        form.addEventListener('submit', async event => {
            event.preventDefault();
            if (!window.confirm('Remove this video from its board?')) return;
            try {
                await postForm('tiktok_board_unassign.php', new FormData(form));
                window.location.reload();
            } catch (cause) {
                window.alert(cause instanceof Error ? cause.message : 'Could not remove the video from the board.');
            }
        });
    });

    document.querySelectorAll('[data-tstudio-schedule-form]').forEach(form => {
        form.addEventListener('submit', async event => {
            event.preventDefault();
            const error = form.querySelector('[data-tstudio-schedule-error]');
            if (error) error.hidden = true;
            const body = new FormData(form);
            body.set('timezone', Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC');
            try {
                await postForm('tiktok_video_schedule.php', body);
                window.location.reload();
            } catch (cause) {
                if (error) {
                    error.textContent = cause instanceof Error ? cause.message : 'Could not schedule this video.';
                    error.hidden = false;
                }
            }
        });
    });

    document.querySelectorAll('[data-tstudio-status-form]').forEach(form => {
        form.addEventListener('submit', async event => {
            event.preventDefault();
            try {
                await postForm('video_tiktok_status.php', new FormData(form));
                window.location.reload();
            } catch (cause) {
                window.alert(cause instanceof Error ? cause.message : 'Could not check the TikTok status.');
            }
        });
    });

    const confirmationLabels = {
        cancel: 'Cancel this scheduled TikTok publication?',
        publish_now: 'Publish this video to TikTok right now?',
        retry: 'Queue this failed publication again?',
    };

    document.querySelectorAll('[data-tstudio-manage]').forEach(button => {
        button.addEventListener('click', async () => {
            const action = button.dataset.action || '';
            if (!window.confirm(confirmationLabels[action] || 'Confirm this action?')) return;
            const body = new FormData();
            body.set('csrf', pageCsrf);
            body.set('jobId', button.dataset.jobId || '');
            body.set('action', action);
            body.set('confirmation', button.dataset.confirmation || '');
            body.set('timezone', Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC');
            try {
                await postForm('video_tiktok_schedule_manage.php', body);
                window.location.reload();
            } catch (cause) {
                window.alert(cause instanceof Error ? cause.message : 'Could not complete that action.');
            }
        });
    });
})();

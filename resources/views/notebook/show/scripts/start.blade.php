{{--
    Notebook script, the sync handler the background meeting sync calls, and
    the first load. Part of the DOMContentLoaded handler opened in
    notebook/show.blade.php: it shares that scope (app, el, base, the
    elements, constants and state in setup).
--}}
            // The background meeting sync announces itself with this key.
            app.registerSyncHandler('notebook-show', async ({ background = false } = {}) => {
                await load({ quiet: true, background });
                renderMessages();
            });

            const ready = await app.ensureAuthenticated();

            if (!ready) {
                return;
            }

            await load();

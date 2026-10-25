{{--
    Notebook script, the Ganti judul and Hapus (Kosongkan) buttons in the
    header. Part of the DOMContentLoaded handler opened in
    notebook/show.blade.php: it shares that scope (app, el, base, the
    elements, constants and state in setup).
--}}
            el('notebook-rename').addEventListener('click', async () => {
                const title = window.prompt('Judul baru notebook', state.notebook?.title || '');

                if (!title || title.trim() === '' || title.trim() === state.notebook?.title) {
                    return;
                }

                try {
                    const payload = await app.request(base, { method: 'PATCH', json: { title: title.trim() } });

                    state.notebook = payload.data.notebook;
                    renderHeader();
                } catch (error) {
                    app.showNotice(app.describeError(error), 'error');
                }
            });

            el('notebook-delete').addEventListener('click', async () => {
                const course = isCourseNotebook();
                const question = course
                    ? 'Kosongkan notebook mata kuliah ini? Semua pertemuan, sumber, percakapan, dan catatannya dihapus; notebooknya tetap ada. Berkas arsip materi tidak ikut terhapus.'
                    : 'Hapus notebook ini beserta percakapan dan unggahannya? Berkas arsip materi tidak ikut terhapus.';

                if (!window.confirm(question)) {
                    return;
                }

                try {
                    const payload = await app.request(base, { method: 'DELETE' });

                    if (payload?.data?.emptied) {
                        state.excluded.clear();
                        app.showNotice(payload.message || 'Notebook dikosongkan.', 'success');
                        await load();

                        return;
                    }

                    app.storeNotice('Notebook dihapus.', 'success');
                    window.location.href = '/notebook';
                } catch (error) {
                    app.showNotice(app.describeError(error), 'error');
                }
            });


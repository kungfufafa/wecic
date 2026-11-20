{{--
    Notebook script, the consent to send Notebook content to Google Gemini:
    agreeing (POST /api/ai-consent) and withdrawing it (DELETE). Part of the
    DOMContentLoaded handler opened in notebook/show.blade.php: it shares
    that scope (app, el, base, the elements, constants and state in setup).
--}}
            const saveAiConsent = async (button, method) => {
                await app.withSubmitLoading(button, async () => {
                    try {
                        const payload = await app.request('/api/ai-consent', { method, json: method === 'POST' ? { accept: true } : {} });

                        state.aiConsent = Boolean(payload?.data?.consent?.granted);
                        renderAiConsent();
                        app.showNotice(payload?.message || 'Persetujuan AI disimpan.', 'success');
                        await load({ quiet: true });
                    } catch (error) {
                        app.showNotice(app.describeError(error, 'Persetujuan AI belum tersimpan.'), 'error');
                    }
                });
            };

            el('notebook-ai-consent-grant').addEventListener('click', (event) => saveAiConsent(event.currentTarget, 'POST'));

            el('notebook-ai-consent-withdraw').addEventListener('click', (event) => {
                if (!window.confirm('Tarik persetujuan? Tanya jawab, studio, dan pembacaan PDF atau gambar berhenti sampai kamu menyetujuinya lagi.')) {
                    return;
                }

                saveAiConsent(event.currentTarget, 'DELETE');
            });


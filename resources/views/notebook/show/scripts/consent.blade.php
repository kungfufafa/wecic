{{--
    Notebook script, the consent to send Notebook content to the AI provider(s):
    agreeing (POST /api/ai-consent) and withdrawing it (DELETE). Part of the
    DOMContentLoaded handler opened in notebook/show.blade.php: it shares
    that scope (app, el, base, the elements, constants and state in setup).
--}}
            const saveAiConsent = async (button, method) => {
                await app.withSubmitLoading(button, async () => {
                    try {
                        const payload = await app.request('/api/ai-consent', { method, json: method === 'POST' ? { accept: true, consent_key: state.aiConsentKey } : {} });

                        state.aiConsent = Boolean(payload?.data?.consent?.granted);
                        setAiConsent({ ai_consent: payload?.data?.consent });
                        renderAiConsent();
                        app.showNotice(payload?.message || 'Persetujuan AI disimpan.', 'success');
                        await load({ quiet: true });
                    } catch (error) {
                        app.showNotice(app.describeError(error, 'Persetujuan AI belum tersimpan.'), error?.status === 409 ? 'warning' : 'error');

                        // The providers changed since this page was rendered: show the consent text for the current ones.
                        if (error?.status === 409) {
                            window.setTimeout(() => window.location.reload(), 2500);
                        }
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


@extends('layouts.app')

@section('title', 'Ulasan harian')
@section('page_id', 'notebook-review')
@section('hide_sync_refresh', '1')

{{--
    Ulasan harian: the cards saved from flashcards and missed quiz questions
    of every notebook, one at a time (GET /api/notebooks/review). The
    student reveals the answer, then grades it (Lagi, Sulit, Bisa, Mudah;
    keys 1 to 4 outside text fields) and the server schedules the card.
    No AI and no daily AI budget.
--}}
@section('content')
    <x-page-hero :back="route('notebook.index')" back-label="Notebook" title="Ulasan harian" subtitle="Kartu dari flashcard dan soal yang salah di semua notebookmu, muncul lagi tepat sebelum lupa. Tanpa AI dan tanpa kuota." />

    <div class="mx-auto flex w-full max-w-2xl min-w-0 flex-col gap-4">
        <p class="text-sm text-ink-muted tabular-nums" id="review-progress" role="status" aria-live="polite">Memuat kartu…</p>

        <section class="flex min-w-0 flex-col gap-4 rounded-xl border border-border bg-panel p-4 sm:p-6" id="review-card" aria-labelledby="review-card-front" aria-busy="true">
            <x-skeleton class="h-4 w-1/3" />
            <x-skeleton class="h-6 w-3/4" />
            <x-skeleton class="h-11 w-40" />
        </section>

        <div class="flex min-w-0 flex-wrap items-center justify-between gap-2 rounded-lg border border-border bg-surface/60 px-3 py-2 text-sm text-ink-muted" id="review-undo" role="status" hidden>
            <span id="review-undo-text">Kartu ditahan dan tidak muncul di ulasan.</span>
            <x-button variant="secondary" size="sm" data-review-undo>Batalkan</x-button>
        </div>

        <p class="hidden text-xs text-ink-subtle sm:block" id="review-keys">Tombol keyboard: Spasi untuk melihat jawaban, lalu 1 Lagi, 2 Sulit, 3 Bisa, 4 Mudah.</p>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            const app = window.portalApp;
            const cardHost = document.getElementById('review-card');
            const progress = document.getElementById('review-progress');
            const undoBar = document.getElementById('review-undo');
            const GRADES = [
                { grade: 'lagi', label: 'Lagi', key: '1' },
                { grade: 'sulit', label: 'Sulit', key: '2' },
                { grade: 'bisa', label: 'Bisa', key: '3' },
                { grade: 'mudah', label: 'Mudah', key: '4' },
            ];
            // `held`: the card just held, until Batalkan or the next grade.
            const state = { cards: [], counts: null, nextDueAt: null, revealed: false, editing: false, busy: false, held: null };

            // "10 mnt", "3 jam", "1 hari", "2 bln": when a grade brings the card back.
            const formatInterval = (minutes) => {
                const value = Math.max(0, Number(minutes) || 0);

                if (value < 60) {
                    return `${Math.max(1, Math.round(value))} mnt`;
                }

                if (value < 1440) {
                    return `${Math.round(value / 60)} jam`;
                }

                const days = Math.round(value / 1440);

                return days < 31 ? `${days} hari` : `${Math.round(days / 30)} bln`;
            };

            // Keys grade the card only outside text fields and without modifiers.
            const isTypingTarget = (element) => Boolean(element && (element.isContentEditable
                || ['INPUT', 'TEXTAREA', 'SELECT'].includes(element.tagName)));

            const text = (value) => app.escapeHtml(String(value ?? '')).replace(/\n/g, '<br>');

            const citationList = (card) => {
                const citations = Array.isArray(card.citations) ? card.citations : [];

                if (!citations.length) {
                    return '';
                }

                return `
                    <ul class="flex flex-col gap-1 border-t border-border-light pt-3 text-xs text-ink-subtle" aria-label="Rujukan">
                        ${citations.map((citation) => `<li><span class="font-medium text-ink-muted">[${Number(citation.n)}]</span> ${app.escapeHtml(citation.source_title || 'Sumber')}${citation.page ? `, hlm. ${Number(citation.page)}` : ''}${citation.excerpt ? ` · “${app.escapeHtml(String(citation.excerpt).slice(0, 160))}”` : ''}</li>`).join('')}
                    </ul>
                `;
            };

            const renderProgress = () => {
                if (!state.counts) {
                    return;
                }

                const left = state.cards.length;
                const fresh = state.cards.filter((card) => card.is_new).length;

                progress.textContent = left
                    ? `Sisa ${left} kartu hari ini${fresh ? ` · ${fresh} baru` : ''}`
                    : 'Tidak ada kartu yang menunggu.';
            };

            const renderDone = () => {
                const next = state.nextDueAt && !Number.isNaN(Date.parse(state.nextDueAt)) ? `dalam ${formatInterval((Date.parse(state.nextDueAt) - Date.now()) / 60000)}` : null;
                const total = state.counts?.total ?? 0;
                const held = Math.max(0, Number(state.counts?.suspended) || 0);
                const empty = total === 0;
                const allHeld = !empty && held >= total;
                const title = empty ? 'Belum ada kartu ulasan' : (allHeld ? 'Semua kartu sedang ditahan' : 'Selesai untuk sekarang');
                const heldLine = held ? ` ${held} kartu sedang ditahan dan tidak muncul sampai diaktifkan lagi.` : '';
                const body = empty
                    ? 'Buka notebook, lalu pilih Simpan ke ulasan di flashcard atau Simpan yang salah ke ulasan setelah kuis.'
                    : app.escapeHtml(allHeld
                        ? `${held} kartu sedang ditahan, jadi tidak ada yang muncul di ulasan.`
                        : `${next ? `Kartu berikutnya muncul ${next}.` : 'Tidak ada kartu lain yang terjadwal.'}${heldLine}`);

                cardHost.innerHTML = `
                    <div class="flex flex-col items-center gap-3 py-6 text-center focus:outline-none" id="review-done" tabindex="-1">
                        <span class="${app.ui.emptyIcon}" aria-hidden="true">${app.renderIcon('notebook', 'lg')}</span>
                        <h2 class="text-base font-semibold text-ink" id="review-card-front">${title}</h2>
                        <p class="max-w-md text-sm text-ink-muted">${body}</p>
                        <div class="flex flex-wrap justify-center gap-2">
                            ${held ? `<button type="button" class="${app.ui.button.primarySm}" data-review-resume>Aktifkan lagi kartu yang ditahan</button>` : ''}
                            ${empty ? '' : `<button type="button" class="${app.ui.button.secondarySm}" data-review-reload>Muat ulang</button>`}
                            <a href="/notebook" class="${app.ui.button.ghostSm}">Kembali ke Notebook</a>
                        </div>
                    </div>
                `;
            };

            const showUndo = (card) => {
                state.held = card;
                undoBar.hidden = !card;
            };

            const renderEditor = (card) => `
                <form class="flex flex-col gap-3" data-review-edit-form>
                    <label class="flex flex-col gap-1.5">
                        <span class="text-sm font-medium text-ink">Sisi depan</span>
                        <textarea class="${app.ui.textarea}" name="front" rows="3" maxlength="2000" required>${app.escapeHtml(card.front)}</textarea>
                    </label>
                    <label class="flex flex-col gap-1.5">
                        <span class="text-sm font-medium text-ink">Sisi belakang</span>
                        <textarea class="${app.ui.textarea}" name="back" rows="5" maxlength="2000" required>${app.escapeHtml(card.back)}</textarea>
                    </label>
                    <div class="flex flex-wrap gap-2">
                        <button type="submit" class="${app.ui.button.primarySm}">Simpan</button>
                        <button type="button" class="${app.ui.button.ghostSm}" data-review-edit-cancel>Batal</button>
                    </div>
                </form>
            `;

            const renderCard = (focusSelector = null) => {
                cardHost.removeAttribute('aria-busy');
                renderProgress();

                const card = state.cards[0];

                if (!card) {
                    renderDone();
                    document.getElementById('review-done')?.focus();

                    return;
                }

                const meta = [
                    card.notebook_title,
                    card.meeting_number ? `Pertemuan ${Number(card.meeting_number)}` : null,
                    card.topic ? `Topik: ${card.topic}` : null,
                ].filter(Boolean).join(' · ');

                cardHost.innerHTML = `
                    <div class="flex min-w-0 flex-wrap items-center gap-2 text-xs text-ink-subtle">
                        ${card.is_new ? app.renderBadge('Baru', 'brand') : ''}
                        ${card.lapses ? app.renderBadge(`Lupa ${Number(card.lapses)}×`, 'warning') : ''}
                        <span class="min-w-0 truncate">${app.escapeHtml(meta)}</span>
                    </div>
                    ${state.editing ? renderEditor(card) : `
                        <h2 class="break-words text-base font-medium leading-relaxed text-ink focus:outline-none sm:text-lg" id="review-card-front" tabindex="-1">${text(card.front)}</h2>
                        ${state.revealed ? `
                            <div class="flex flex-col gap-3 border-t border-border-light pt-4 focus:outline-none" id="review-card-back" tabindex="-1">
                                <p class="break-words text-sm leading-relaxed text-ink-muted">${text(card.back)}</p>
                                ${citationList(card)}
                            </div>
                            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4" role="group" aria-label="Seberapa ingat kamu?">
                                ${GRADES.map(({ grade, label, key }) => `
                                    <button type="button" class="${grade === 'bisa' ? app.ui.button.primarySm : app.ui.button.secondarySm} flex-col gap-0! py-1.5 leading-tight" data-review-grade="${grade}" aria-keyshortcuts="${key}" ${state.busy ? 'disabled aria-disabled="true"' : ''}>
                                        <span>${label}</span>
                                        <span class="text-xs font-normal opacity-80">${formatInterval(card.next?.[grade])}</span>
                                    </button>
                                `).join('')}
                            </div>
                        ` : `
                            <div><button type="button" class="${app.ui.button.primarySm}" data-review-reveal aria-keyshortcuts="Space">Tampilkan jawaban</button></div>
                        `}
                        <div class="flex flex-wrap gap-2 border-t border-border-light pt-3">
                            <button type="button" class="${app.ui.button.ghostSm} -ms-3" data-review-edit>Ubah kartu</button>
                            <button type="button" class="${app.ui.button.ghostSm}" data-review-suspend>Tahan kartu</button>
                            <a href="/notebook/${Number(card.notebook_id)}" class="${app.ui.button.ghostSm}">Buka notebook</a>
                        </div>
                    `}
                `;

                if (focusSelector) {
                    cardHost.querySelector(focusSelector)?.focus?.();
                }
            };

            const load = async () => {
                cardHost.setAttribute('aria-busy', 'true');

                try {
                    const payload = await app.request('/api/notebooks/review?limit=50');

                    if (!payload) {
                        return;
                    }

                    state.cards = Array.isArray(payload.data?.cards) ? payload.data.cards : [];
                    state.counts = payload.data?.counts || null;
                    state.nextDueAt = payload.data?.next_due_at || null;
                    state.revealed = false;
                    state.editing = false;
                    renderCard();
                } catch (error) {
                    cardHost.removeAttribute('aria-busy');
                    progress.textContent = '';
                    app.mountErrorState(cardHost, 'Ulasan gagal dimuat.', () => load(), { subtitle: app.describeError(error) });
                }
            };

            const reveal = () => {
                if (!state.cards.length || state.revealed || state.editing) {
                    return;
                }

                state.revealed = true;
                renderCard('[data-review-grade="bisa"]');
                app.announce(`Jawaban: ${state.cards[0].back}`);
            };

            // The server schedules the card; it leaves today's queue here and comes back when it is due.
            const grade = async (value) => {
                const card = state.cards[0];

                if (!card || !state.revealed || state.busy || state.editing) {
                    return;
                }

                state.busy = true;
                renderCard();

                try {
                    const payload = await app.request(`/api/notebooks/review/${card.id}`, { method: 'POST', json: { grade: value } });
                    const label = GRADES.find((item) => item.grade === value)?.label || value;

                    state.cards.shift();
                    state.counts = payload?.data?.counts || state.counts;
                    state.revealed = false;
                    state.busy = false;
                    showUndo(null);
                    app.announce(`${label}. Muncul lagi dalam ${formatInterval(card.next?.[value])}.`);

                    if (!state.cards.length) {
                        await load();

                        return;
                    }

                    renderCard('#review-card-front');
                } catch (error) {
                    state.busy = false;
                    renderCard('[data-review-grade="bisa"]');
                    app.showNotice(app.describeError(error, 'Ulasan belum tersimpan.'), 'error');
                }
            };

            const updateCard = async (changes, success) => {
                const card = state.cards[0];

                try {
                    const payload = await app.request(`/api/notebooks/review/${card.id}`, { method: 'PATCH', json: changes });

                    state.counts = payload?.data?.counts || state.counts;
                    success(payload?.data?.card || card);
                    app.showNotice(payload?.message || 'Kartu disimpan.', 'success');
                } catch (error) {
                    app.showNotice(app.describeError(error, 'Kartu gagal diperbarui.'), 'error');
                }
            };

            cardHost.addEventListener('click', async (event) => {
                const target = event.target instanceof Element ? event.target : null;
                const gradeButton = target?.closest('[data-review-grade]');

                if (target?.closest('[data-review-reveal]')) {
                    reveal();
                } else if (gradeButton && !gradeButton.disabled) {
                    await grade(gradeButton.dataset.reviewGrade);
                } else if (target?.closest('[data-review-reload]')) {
                    await load();
                } else if (target?.closest('[data-review-edit]')) {
                    state.editing = true;
                    renderCard('textarea[name="front"]');
                } else if (target?.closest('[data-review-edit-cancel]')) {
                    state.editing = false;
                    renderCard('[data-review-edit]');
                } else if (target?.closest('[data-review-suspend]')) {
                    const button = target.closest('[data-review-suspend]');
                    const held = state.cards[0];

                    await app.withSubmitLoading(button, () => updateCard({ suspended: true }, () => {
                        state.cards.shift();
                        state.revealed = false;
                        showUndo(held);
                        renderCard('#review-card-front');
                    }));
                } else if (target?.closest('[data-review-resume]')) {
                    const button = target.closest('[data-review-resume]');

                    await app.withSubmitLoading(button, async () => {
                        try {
                            const payload = await app.request('/api/notebooks/review/resume', { method: 'POST' });

                            showUndo(null);
                            app.showNotice(payload?.message || 'Kartu kembali ke ulasan.', 'success');
                            await load();
                        } catch (error) {
                            app.showNotice(app.describeError(error, 'Kartu gagal diaktifkan lagi.'), 'error');
                        }
                    });
                }
            });

            // Batalkan: the card just held comes back to the front of the queue, as it was.
            undoBar.addEventListener('click', async (event) => {
                const button = event.target instanceof Element ? event.target.closest('[data-review-undo]') : null;
                const card = state.held;

                if (!button || !card) {
                    return;
                }

                await app.withSubmitLoading(button, async () => {
                    try {
                        const payload = await app.request(`/api/notebooks/review/${card.id}`, { method: 'PATCH', json: { suspended: false } });

                        state.counts = payload?.data?.counts || state.counts;
                        state.cards.unshift({ ...card, suspended: false });
                        state.revealed = false;
                        state.editing = false;
                        showUndo(null);
                        renderCard('#review-card-front');
                        app.announce('Kartu kembali ke ulasan.');
                    } catch (error) {
                        app.showNotice(app.describeError(error, 'Kartu gagal dikembalikan.'), 'error');
                    }
                });
            });

            cardHost.addEventListener('submit', async (event) => {
                const form = event.target.closest('[data-review-edit-form]');

                if (!form) {
                    return;
                }

                event.preventDefault();

                const front = form.elements.front.value.trim();
                const back = form.elements.back.value.trim();

                if (!front || !back) {
                    app.showNotice('Sisi depan dan belakang kartu tidak boleh kosong.', 'warning');

                    return;
                }

                await app.withSubmitLoading(form.querySelector('button[type="submit"]'), () => updateCard({ front, back }, (saved) => {
                    state.cards[0] = { ...state.cards[0], ...saved };
                    state.editing = false;
                    renderCard('[data-review-edit]');
                }));
            });

            document.addEventListener('keydown', async (event) => {
                if (event.defaultPrevented || event.ctrlKey || event.metaKey || event.altKey || isTypingTarget(event.target)) {
                    return;
                }

                const choice = GRADES.find((item) => item.key === event.key);

                if (choice && state.revealed) {
                    event.preventDefault();
                    await grade(choice.grade);
                } else if (event.key === ' ' && !state.revealed && !(event.target instanceof Element && event.target.closest('button, a'))) {
                    event.preventDefault();
                    reveal();
                }
            });

            const ready = await app.ensureAuthenticated();

            if (!ready) {
                return;
            }

            await load();
        });
    </script>
@endpush

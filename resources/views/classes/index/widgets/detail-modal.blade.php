{{--
    The class detail modal, filled by scripts/modal when a class is opened.
--}}
    <div class="mycic-modal hidden" tabindex="-1" aria-hidden="true" id="class-detail-modal" role="dialog" aria-modal="true" aria-labelledby="modal-class-title">
        <div class="mycic-modal-backdrop" id="class-modal-backdrop"></div>
        <div class="mycic-modal-panel max-w-lg">
            <div class="mycic-modal-header">
                <div class="min-w-0 flex-1 pr-2">
                    <div class="mb-1 flex items-center gap-2">
                        <span class="rounded bg-brand/10 px-2 py-0.5 text-xs font-bold text-brand dark:bg-brand/20" id="modal-class-code">-</span>
                        <span class="rounded bg-surface px-2 py-0.5 text-xs font-medium text-ink-subtle" id="modal-class-day">-</span>
                    </div>
                    <h2 class="text-subtitle font-bold text-ink leading-snug" id="modal-class-title">-</h2>
                </div>
                <button type="button" class="mycic-modal-close" id="class-modal-close" aria-label="Tutup">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6l-12 12" /><path d="M6 6l12 12" /></svg>
                </button>
            </div>
            <div class="mycic-modal-body flex flex-col gap-4">
                <div class="grid grid-cols-2 gap-3">
                    <div class="rounded-lg border border-border bg-surface/50 p-3">
                        <div class="text-caption font-semibold uppercase text-ink-subtle">Waktu Kuliah</div>
                        <div class="font-bold text-ink mt-0.5 text-sm" id="modal-class-time">-</div>
                        <div class="text-xs text-ink-subtle mt-0.5" id="modal-class-duration">-</div>
                    </div>
                    <div class="rounded-lg border border-border bg-surface/50 p-3">
                        <div class="text-caption font-semibold uppercase text-ink-subtle">Ruangan & Kelas</div>
                        <div class="font-bold text-ink mt-0.5 text-sm" id="modal-class-room">-</div>
                        <div class="text-xs text-ink-subtle mt-0.5" id="modal-class-group">-</div>
                    </div>
                </div>

                <div class="rounded-lg border border-border bg-surface/50 p-3">
                    <div class="text-caption font-semibold uppercase text-ink-subtle">Dosen Pengampu</div>
                    <div class="font-bold text-ink mt-0.5 text-sm" id="modal-class-lecturer">-</div>
                    <div class="text-xs text-ink-subtle mt-1.5 flex flex-col gap-1" id="modal-class-contacts"></div>
                </div>

                <div class="flex items-center gap-2 pt-2 border-t border-border-light">
                    <x-button href="#" size="sm" class="flex-1 text-center justify-center" id="modal-btn-open-class">Buka Ruang Kelas</x-button>
                    <x-button variant="secondary" size="sm" id="modal-btn-toggle-meeting">Lihat pertemuan</x-button>
                </div>

                <div class="hidden" id="modal-meeting-panel"></div>
            </div>
        </div>
    </div>

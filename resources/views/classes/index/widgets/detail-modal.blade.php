{{--
    The class detail modal, filled by scripts/modal when a class is opened.
--}}
    <div class="mycic-modal hidden" tabindex="-1" aria-hidden="true" id="class-detail-modal" role="dialog" aria-modal="true" aria-labelledby="modal-class-title">
        <div class="mycic-modal-backdrop" id="class-modal-backdrop"></div>
        <div class="mycic-modal-panel max-w-lg">
            <div class="mycic-modal-header">
                <div class="min-w-0 flex-1 pr-2">
                    <div class="mb-1 flex items-center gap-2">
                        <span class="mycic-badge-brand font-mono" id="modal-class-code">-</span>
                        <span class="mycic-badge-neutral" id="modal-class-day">-</span>
                    </div>
                    <h2 class="mycic-modal-title" id="modal-class-title">-</h2>
                </div>
                <button type="button" class="mycic-modal-close" id="class-modal-close" aria-label="Tutup">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6l-12 12" /><path d="M6 6l12 12" /></svg>
                </button>
            </div>
            <div class="mycic-modal-body flex flex-col gap-4">
                <div class="divide-y divide-border-light border-y border-border-light">
                    <div class="grid grid-cols-2 divide-x divide-border-light">
                        <div class="py-3 pe-3">
                            <div class="text-caption font-medium text-ink-subtle">Waktu kuliah</div>
                            <div class="mt-1 font-mono text-sm font-medium text-ink tabular-nums" id="modal-class-time">-</div>
                            <div class="mt-0.5 text-caption text-ink-subtle" id="modal-class-duration">-</div>
                        </div>
                        <div class="py-3 ps-3">
                            <div class="text-caption font-medium text-ink-subtle">Ruangan &amp; kelas</div>
                            <div class="mt-1 text-sm font-medium text-ink" id="modal-class-room">-</div>
                            <div class="mt-0.5 text-caption text-ink-subtle" id="modal-class-group">-</div>
                        </div>
                    </div>

                    <div class="py-3">
                        <div class="text-caption font-medium text-ink-subtle">Dosen pengampu</div>
                        <div class="mt-1 text-sm font-medium text-ink" id="modal-class-lecturer">-</div>
                        <div class="mt-2 flex flex-col gap-1 text-caption text-ink-subtle" id="modal-class-contacts"></div>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <x-button href="#" size="sm" class="flex-1" id="modal-btn-open-class">Buka Ruang Kelas</x-button>
                    <x-button variant="secondary" size="sm" id="modal-btn-toggle-meeting">Lihat pertemuan</x-button>
                </div>

                <div class="hidden" id="modal-meeting-panel"></div>
            </div>
        </div>
    </div>

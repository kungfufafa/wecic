{{--
    The class detail modal, filled by scripts/modal when a class is opened.
    The two action buttons sit in wrappers ([data-modal-open-wrap],
    [data-modal-meeting-wrap]) that the script hides: a button is
    inline-flex, which would beat `hidden` on the button itself.
--}}
    <x-modal id="class-detail-modal" role="dialog" aria-modal="true" aria-labelledby="modal-class-title">
        <x-modal.backdrop id="class-modal-backdrop" />
        <x-modal.panel size="lg" data-modal-panel>
            <x-modal.header>
                <div class="min-w-0 flex-1">
                    <p class="mb-1 flex flex-wrap items-center gap-x-1.5 text-xs text-ink-muted">
                        <span class="font-mono" id="modal-class-code">-</span>
                        <span class="before:me-1.5 before:content-['·']" id="modal-class-day">-</span>
                    </p>
                    <x-modal.title id="modal-class-title">-</x-modal.title>
                </div>
                <x-modal.close id="class-modal-close" />
            </x-modal.header>
            <x-modal.body class="flex flex-col gap-4">
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="rounded-lg bg-surface p-3">
                        <div class="text-xs font-medium text-ink-subtle">Waktu kuliah</div>
                        <div class="mt-1 text-sm font-medium text-ink tabular-nums" id="modal-class-time">-</div>
                        <div class="mt-0.5 text-xs text-ink-subtle" id="modal-class-duration">-</div>
                    </div>
                    <div class="rounded-lg bg-surface p-3">
                        <div class="text-xs font-medium text-ink-subtle">Ruangan &amp; kelas</div>
                        <div class="mt-1 text-sm font-medium text-ink" id="modal-class-room">-</div>
                        <div class="mt-0.5 text-xs text-ink-subtle" id="modal-class-group">-</div>
                    </div>
                    <div class="rounded-lg bg-surface p-3 sm:col-span-2">
                        <div class="text-xs font-medium text-ink-subtle">Dosen pengampu</div>
                        <div class="mt-1 text-sm font-medium text-ink" id="modal-class-lecturer">-</div>
                        <div class="mt-2 flex flex-col gap-1 text-xs text-ink-subtle" id="modal-class-contacts"></div>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <div class="flex-1" data-modal-open-wrap>
                        <x-button href="#" size="sm" class="w-full" id="modal-btn-open-class">Buka Ruang Kelas</x-button>
                    </div>
                    <div data-modal-meeting-wrap>
                        <x-button variant="secondary" size="sm" id="modal-btn-toggle-meeting">Lihat pertemuan</x-button>
                    </div>
                </div>

                <div class="hidden" id="modal-meeting-panel"></div>
            </x-modal.body>
        </x-modal.panel>
    </x-modal>

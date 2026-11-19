{{--
    The "Riwayat & DPA" tab: the KRS history beside a sticky aside with the
    DPA contact (first on a phone). Loading skeletons stand in until the
    script fills them.
--}}
    <section
        id="krs-panel-riwayat"
        role="tabpanel"
        aria-labelledby="krs-tab-riwayat"
        class="print:hidden"
        @if ($activeTab !== 'riwayat') hidden @endif
    >
        <x-split>
            <div class="min-w-0" id="krs-history">
                <x-card aria-busy="true">
                    <x-card.header>
                        <span class="sr-only">Memuat riwayat KRS…</span>
                        <x-skeleton class="h-5 w-32" />
                    </x-card.header>
                    <x-list>
                        @for ($i = 0; $i < 3; $i++)
                            <x-list-row flush>
                                <div class="flex flex-col gap-1.5">
                                    <x-skeleton class="h-4 w-1/2" />
                                    <x-skeleton class="h-3 w-1/3" />
                                </div>
                            </x-list-row>
                        @endfor
                    </x-list>
                </x-card>
            </div>

            <x-slot:aside class="max-lg:order-first" aria-label="Dosen pembimbing akademik">
                <div class="min-w-0" id="krs-dpa">
                    <x-card aria-busy="true">
                        <x-card.body class="flex flex-col gap-3">
                            <span class="sr-only">Memuat kontak DPA…</span>
                            <div class="flex items-center gap-3">
                                <x-skeleton class="size-10 shrink-0 rounded-full!" />
                                <x-skeleton class="h-4 w-1/2" />
                            </div>
                            <x-skeleton class="h-11 w-full" />
                            <x-skeleton class="h-11 w-full" />
                        </x-card.body>
                    </x-card>
                </div>
            </x-slot:aside>
        </x-split>
    </section>

{{--
    The "Semester ini" tab: the status card (the page's focal point) and the
    courses, beside a sticky aside with the period schedule and the notes.
    Loading skeletons stand in until the script fills them.
--}}
    <section
        id="krs-panel-semester"
        role="tabpanel"
        aria-labelledby="krs-tab-semester"
        @if ($activeTab !== 'semester') hidden @endif
    >
        <x-split>
            <div id="krs-status" class="print:hidden">
                <x-card aria-busy="true">
                    <x-card.body class="flex flex-col gap-4">
                        <span class="sr-only">Memuat status KRS…</span>
                        <div class="flex items-center gap-3">
                            <x-skeleton class="size-11 shrink-0 rounded-xl!" />
                            <div class="flex flex-1 flex-col gap-1.5">
                                <x-skeleton class="h-3.5 w-1/3" />
                                <x-skeleton class="h-6 w-2/3" />
                            </div>
                        </div>
                        <x-skeleton class="h-14 w-full" />
                        <x-skeleton class="h-3.5 w-1/2" />
                    </x-card.body>
                </x-card>
            </div>

            <div id="krs-courses">
                <x-card aria-busy="true">
                    <x-card.header>
                        <x-skeleton class="h-5 w-48" />
                        <x-skeleton class="h-5 w-24" />
                    </x-card.header>
                    <x-list>
                        @for ($i = 0; $i < 4; $i++)
                            <x-list-row flush>
                                <x-slot:leading><x-skeleton class="size-11 rounded-lg!" /></x-slot:leading>
                                <div class="flex flex-col gap-1.5">
                                    <x-skeleton class="h-3 w-16" />
                                    <x-skeleton class="h-4 w-40 sm:w-56" />
                                </div>
                            </x-list-row>
                        @endfor
                    </x-list>
                </x-card>
            </div>

            <x-slot:aside class="print:hidden" aria-label="Jadwal dan catatan KRS">
                <div id="krs-schedule">
                    <x-card aria-busy="true">
                        <x-card.body class="flex flex-col gap-3">
                            <x-skeleton class="h-4 w-1/2" />
                            <x-skeleton class="h-3.5 w-3/4" />
                        </x-card.body>
                    </x-card>
                </div>
                <div id="krs-notes" class="empty:hidden"></div>
            </x-slot:aside>
        </x-split>
    </section>

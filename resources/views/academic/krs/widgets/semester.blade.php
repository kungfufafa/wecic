{{--
    The "Semester ini" tab: loading skeletons for the status card, the
    courses and the schedule until the script fills them.
--}}
    <section
        id="krs-panel-semester"
        role="tabpanel"
        aria-labelledby="krs-tab-semester"
        class="flex flex-col gap-4 lg:grid lg:grid-cols-12 lg:items-start lg:gap-5"
        @if ($activeTab !== 'semester') hidden @endif
    >
        <div class="flex min-w-0 flex-col gap-4 lg:col-span-8">
            <div id="krs-status" class="print:hidden">
                <div class="mycic-card" aria-busy="true">
                    <div class="mycic-card-body flex flex-col gap-3">
                        <span class="sr-only">Memuat status KRS…</span>
                        <div class="mycic-skeleton h-3.5 w-1/3"></div>
                        <div class="mycic-skeleton h-6 w-2/3"></div>
                        <div class="mycic-skeleton h-14 w-full"></div>
                        <div class="mycic-skeleton h-3.5 w-1/2"></div>
                    </div>
                </div>
            </div>

            <div id="krs-courses">
                <div class="mycic-card" aria-busy="true">
                    <div class="mycic-card-header">
                        <div class="mycic-skeleton h-5 w-48"></div>
                    </div>
                    <div class="mycic-card-body flex flex-col divide-y divide-border-light">
                        @for ($i = 0; $i < 4; $i++)
                            <x-list-row>
                                <div class="flex flex-col gap-1.5">
                                    <div class="mycic-skeleton h-3 w-16"></div>
                                    <div class="mycic-skeleton h-4 w-40 sm:w-56"></div>
                                </div>
                                <div class="mycic-skeleton h-5 w-14"></div>
                            </x-list-row>
                        @endfor
                    </div>
                </div>
            </div>
        </div>

        <aside class="flex min-w-0 flex-col gap-4 print:hidden lg:sticky lg:top-20 lg:col-span-4" aria-label="Jadwal dan catatan KRS">
            <div id="krs-schedule">
                <div class="mycic-card" aria-busy="true">
                    <div class="mycic-card-body flex flex-col gap-3">
                        <div class="mycic-skeleton h-4 w-1/2"></div>
                        <div class="mycic-skeleton h-3.5 w-3/4"></div>
                    </div>
                </div>
            </div>
            <div id="krs-notes"></div>
        </aside>
    </section>

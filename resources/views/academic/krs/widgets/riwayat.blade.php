{{--
    The "Riwayat & DPA" tab: loading skeletons for the DPA contact and the
    KRS history until the script fills them.
--}}
    <section
        id="krs-panel-riwayat"
        role="tabpanel"
        aria-labelledby="krs-tab-riwayat"
        class="flex flex-col gap-4 print:hidden lg:grid lg:grid-cols-12 lg:items-start lg:gap-5"
        @if ($activeTab !== 'riwayat') hidden @endif
    >
        <div class="min-w-0 lg:order-2 lg:col-span-4" id="krs-dpa">
            <div class="mycic-card" aria-busy="true">
                <div class="mycic-card-body flex flex-col gap-3">
                    <span class="sr-only">Memuat kontak DPA…</span>
                    <div class="mycic-skeleton h-4 w-1/2"></div>
                    <div class="mycic-skeleton h-11 w-full"></div>
                    <div class="mycic-skeleton h-11 w-full"></div>
                </div>
            </div>
        </div>

        <div class="min-w-0 lg:order-1 lg:col-span-8" id="krs-history">
            <div class="mycic-card" aria-busy="true">
                <div class="mycic-card-body flex flex-col gap-3">
                    <span class="sr-only">Memuat riwayat KRS…</span>
                    @for ($i = 0; $i < 3; $i++)
                        <div class="flex items-center gap-3">
                            <div class="mycic-skeleton h-10 w-10 shrink-0 rounded-full"></div>
                            <div class="flex flex-1 flex-col gap-1.5">
                                <div class="mycic-skeleton h-4 w-1/2"></div>
                                <div class="mycic-skeleton h-3 w-1/3"></div>
                            </div>
                        </div>
                    @endfor
                </div>
            </div>
        </div>
    </section>

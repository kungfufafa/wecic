{{--
    Beranda script, the page header: the salutation and today's date, the
    summary of the day (classes, pending tasks) and the academic period
    (K1, K2), each a muted item of the line under the greeting. Part of the DOMContentLoaded handler opened in
    dashboard.blade.php: it shares that scope (app, esc, the constants and
    state in setup).
--}}
            // ---- Hero: greeting, summary of the day, academic period (K1, K2) ----------------

            const periodHost = document.getElementById('dashboard-period');
            const heroSalutationNode = document.querySelector('[data-dashboard-salutation]');
            const heroDateNode = document.getElementById('dashboard-hero-date');
            const heroSummaryNode = document.getElementById('dashboard-hero-summary');
            // Plain muted items of the header's meta line, each after a dot.
            const HERO_PERIOD_ITEM = "before:me-1.5 before:content-['·'] max-sm:first:before:hidden";
            const HERO_SKELETON = 'h-4 w-40 animate-pulse rounded-md bg-surface';

            const partOfDay = () => {
                const hour = new Date().getHours();

                if (hour < 11) {
                    return 'pagi';
                }

                if (hour < 15) {
                    return 'siang';
                }

                return hour < 18 ? 'sore' : 'malam';
            };

            const renderHeroGreeting = () => {
                heroSalutationNode.textContent = `Selamat ${partOfDay()}`;
                heroDateNode.textContent = new Date().toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
            };

            /**
             * "2 kuliah hari ini · 3 tugas belum dikumpulkan", from the schedule and the
             * task list last rendered. An older day's schedule says nothing about today.
             */
            const renderHeroSummary = () => {
                const parts = [];
                const summary = latestClassesSummary;

                if (summary && (!summary.date || summary.date === todayIso())) {
                    const classCount = (summary.today_classes || []).length;

                    parts.push(classCount ? `${classCount} kuliah hari ini` : 'Tidak ada kuliah hari ini');
                }

                if (latestAssignmentsPayload) {
                    parts.push(latestAssignments.length ? `${latestAssignments.length} tugas belum dikumpulkan` : 'Tidak ada tugas tertunda');
                }

                heroSummaryNode.textContent = parts.join(' · ');
                heroSummaryNode.classList.toggle('hidden', !parts.length);
            };

            renderHeroGreeting();

            const renderPeriod = (context, pendingSources) => {
                const period = context?.academic_period || null;
                const termLabel = period?.term ? app.capitalize(period.term) : null;
                const periodLabel = [period?.academic_year ? `T.A ${period.academic_year}` : null, termLabel].filter(Boolean).join(' · ');
                const chips = [];

                if (periodLabel) {
                    chips.push({ label: periodLabel, title: '' });
                }

                if (context?.semester_label) {
                    chips.push({
                        label: context.semester_label,
                        title: context.semester_is_estimate ? 'Dihitung dari angkatan, bisa berbeda dengan data portal.' : '',
                    });
                }

                if (!chips.length && context?.summary_label) {
                    chips.push({ label: context.summary_label, title: '' });
                }

                renderHeroGreeting();
                periodHost.removeAttribute('aria-busy');

                if (chips.length) {
                    periodHost.innerHTML = chips.map((chip) => `
                        <li class="${HERO_PERIOD_ITEM}"${chip.title ? ` title="${esc(chip.title)}"` : ''}>${esc(chip.label)}</li>
                    `).join('');
                    periodHost.classList.remove('hidden');

                    return;
                }

                if (pendingSources.includes('portal_context')) {
                    periodHost.innerHTML = `
                        <li class="${HERO_SKELETON}" aria-hidden="true" data-slot="skeleton"></li>
                        <li class="sr-only">Periode akademik sedang diambil dari portal.</li>
                    `;
                    periodHost.classList.remove('hidden');

                    return;
                }

                // Secondary data: hidden rather than shown as an error (F01).
                periodHost.innerHTML = '';
                periodHost.classList.add('hidden');
            };


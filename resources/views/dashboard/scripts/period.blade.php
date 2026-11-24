{{--
    Beranda script, the page header: the salutation, today's date, and the
    academic period (K1, K2), each a muted item of the line under the
    greeting. Part of the DOMContentLoaded handler opened in
    dashboard.blade.php: it shares that scope (app, esc, the constants and
    state in setup).
--}}
            // ---- Hero: greeting and academic period (K1, K2) ----------------

            const periodHost = document.getElementById('dashboard-period');
            const heroSalutationNode = document.querySelector('[data-dashboard-salutation]');
            const heroDateNode = document.getElementById('dashboard-hero-date');
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


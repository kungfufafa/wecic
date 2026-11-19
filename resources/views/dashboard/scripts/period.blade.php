{{--
    Beranda script, the academic period chips in the hero (K1, K2). Part
    of the DOMContentLoaded handler opened in dashboard.blade.php: it
    shares that scope (app, esc, the constants and state in setup).
--}}
            // ---- Hero: academic period chip (K1, K2) ----------------------------------------

            const periodHost = document.getElementById('dashboard-period');
            const periodChipClass = 'inline-flex items-center rounded-md border border-border bg-panel px-2.5 py-1 text-xs font-medium text-ink-muted';

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

                periodHost.removeAttribute('aria-busy');

                if (chips.length) {
                    periodHost.innerHTML = chips.map((chip) => `
                        <li class="${periodChipClass}"${chip.title ? ` title="${esc(chip.title)}"` : ''}>${esc(chip.label)}</li>
                    `).join('');
                    periodHost.classList.remove('hidden');

                    return;
                }

                if (pendingSources.includes('portal_context')) {
                    periodHost.innerHTML = `
                        <li class="mycic-skeleton h-7 w-44 rounded-md" aria-hidden="true"></li>
                        <li class="sr-only">Periode akademik sedang diambil dari portal.</li>
                    `;
                    periodHost.classList.remove('hidden');

                    return;
                }

                // Secondary data: hidden rather than shown as an error (F01).
                periodHost.innerHTML = '';
                periodHost.classList.add('hidden');
            };


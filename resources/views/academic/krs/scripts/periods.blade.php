{{--
    KRS script, the latest KRS and semester, and the KRS periods recomputed
    for today with the ones that matter for a status. Part of the
    DOMContentLoaded handler opened in academic/krs.blade.php: it shares
    that scope (app, esc, hosts, state and the helpers in setup).
--}}
            const latestKrsId = () => state.krs?.insights?.latest_krs_id ?? state.advising?.latest_krs_id ?? null;

            const currentSemesterNumber = () => {
                const number = state.advising?.latest_semester_number;

                return Number.isInteger(number) ? number : null;
            };

            /**
             * Periods with open/upcoming/past recomputed for today, so an old
             * snapshot never shows a countdown that already ran out.
             */
            const livePeriods = () => (state.krs?.insights?.periods || state.krs?.periods || [])
                .filter((period) => period?.start && period?.end)
                .map((period) => {
                    const untilStart = app.daysUntil(period.start);
                    const untilEnd = app.daysUntil(period.end);

                    return {
                        ...period,
                        label: period.label || 'Periode KRS',
                        isOpen: untilStart !== null && untilEnd !== null && untilStart <= 0 && untilEnd >= 0,
                        isUpcoming: untilStart !== null && untilStart > 0,
                        isPast: untilEnd !== null && untilEnd < 0,
                        daysLeft: untilEnd,
                        daysUntilStart: untilStart,
                    };
                });

            const pickPeriods = (statusCode) => {
                const periods = livePeriods();
                const order = relevantKinds[statusCode] || relevantKinds.unknown;
                const open = periods.filter((period) => period.isOpen);
                let active = null;

                for (const kind of order) {
                    const candidates = open.filter((period) => period.kind === kind).sort((left, right) => String(left.end).localeCompare(String(right.end)));

                    if (candidates.length) {
                        active = candidates[0];
                        break;
                    }
                }

                const upcoming = periods.filter((period) => period.isUpcoming).sort((left, right) => String(left.start).localeCompare(String(right.start)))[0] || null;
                const past = periods.filter((period) => period.isPast).sort((left, right) => String(right.end).localeCompare(String(left.end)))[0] || null;

                return { periods, active: active || open[0] || null, upcoming, past };
            };


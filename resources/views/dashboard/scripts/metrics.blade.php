{{--
    Beranda script, the "Ringkasan studi" tiles: IPK, SKS lulus, SKS
    semester ini, Tagihan and SKKM (K11). Part of the DOMContentLoaded
    handler opened in dashboard.blade.php: it shares that scope (app, esc,
    the constants and state in setup).
--}}
            // ---- Metric tiles (K11): IPK · SKS lulus · SKS semester ini · Tagihan --------------

            const parsePositiveInt = (value) => {
                const number = Number.parseInt(String(value ?? '').replace(/\D/g, ''), 10);

                return Number.isFinite(number) && number > 0 ? number : null;
            };

            const compactAmount = new Intl.NumberFormat('id-ID', { notation: 'compact', maximumFractionDigits: 2 });

            const formatPoints = (value) => new Intl.NumberFormat('id-ID', { maximumFractionDigits: 1 }).format(Number(value));

            const clampPercent = (value) => Math.max(0, Math.min(100, Number(value) || 0));

            const notReady = (status, { pending, unavailable, failed, unavailableNote = 'Belum terbaca' }) => {
                if (status === 'pending') {
                    return { value: '–', note: 'Sedang diambil…', title: pending, pending: true };
                }

                if (status === 'failed') {
                    return { value: '–', note: 'Gagal dibaca', title: failed };
                }

                return { value: '–', note: unavailableNote, title: unavailable };
            };

            const describeIpk = (metric) => {
                if (metric?.status === 'ready' && Number.isFinite(Number(metric.value)) && metric.value !== null) {
                    return {
                        value: Number(metric.value).toFixed(2),
                        note: metric.source === 'computed' ? 'Dihitung dari KHS' : 'IPK resmi portal',
                        title: metric.predicate_estimate ? `Perkiraan predikat: ${metric.predicate_estimate}` : '',
                    };
                }

                // The KHS was read and holds no grade yet (a new student).
                if (metric?.status === 'empty') {
                    return { value: '–', note: 'Belum ada nilai', title: 'Belum ada nilai di KHS. IPK muncul setelah nilai pertama keluar.' };
                }

                // Fase 0.7: an IPK that is missing is unread, not "no grades yet".
                return notReady(metric?.status, {
                    pending: 'IPK sedang diambil dari portal.',
                    unavailable: 'IPK belum bisa dibaca dari portal.',
                    failed: 'IPK belum bisa dibaca dari portal. Coba sinkronkan lagi.',
                });
            };

            const describeSksPassed = (metric) => {
                if (metric?.status === 'ready' && Number.isInteger(metric.value)) {
                    const target = Number.isInteger(metric.target_sks) ? metric.target_sks : null;
                    const percent = metric.progress_percent !== null && metric.progress_percent !== undefined
                        ? clampPercent(metric.progress_percent)
                        : (target ? clampPercent((metric.value / target) * 100) : null);

                    return {
                        value: String(metric.value),
                        note: target ? `dari ${target} SKS${percent !== null ? ` · ${Math.round(percent)}%` : ''}` : 'SKS terkumpul',
                        title: [
                            Number.isInteger(metric.remaining_sks) ? `Sisa ${metric.remaining_sks} SKS lagi untuk lulus.` : '',
                            metric.conversion_sks > 0 ? `Termasuk ${metric.conversion_sks} SKS konversi.` : '',
                        ].filter(Boolean).join(' '),
                        progress: percent,
                    };
                }

                return notReady(metric?.status, {
                    pending: 'SKS lulus sedang diambil dari portal.',
                    unavailable: 'SKS lulus belum bisa dibaca dari portal.',
                    failed: 'SKS lulus belum bisa dibaca dari portal. Coba sinkronkan lagi.',
                });
            };

            const describeSksCurrent = (metric, data) => {
                if (metric?.status === 'ready' && Number.isInteger(metric.value)) {
                    return {
                        value: String(metric.value),
                        note: Number.isInteger(metric.courses) ? `${metric.courses} MK` : 'SKS di KRS',
                        title: 'Total SKS di KRS semester ini.',
                    };
                }

                // Until the KRS is read, the class list of this semester is the next best source.
                const credits = parsePositiveInt(data?.dashboard_overview?.total_credits);
                const courses = parsePositiveInt(data?.classes_summary?.total_classes ?? data?.dashboard_overview?.total_courses);

                if (credits !== null && metric?.status !== 'ready') {
                    return {
                        value: String(credits),
                        note: `${courses !== null ? `${courses} MK · ` : ''}dari jadwal kelas`,
                        title: 'Dihitung dari daftar kelas karena KRS belum terbaca.',
                    };
                }

                return notReady(metric?.status, {
                    pending: 'KRS sedang diambil dari portal.',
                    unavailable: 'KRS semester ini belum bisa dibaca dari portal.',
                    failed: 'KRS belum bisa dibaca dari portal. Coba sinkronkan lagi.',
                });
            };

            const describeBills = (metric) => {
                if (metric?.status === 'clear') {
                    return { value: 'Lunas', note: 'Tidak ada tagihan aktif', tone: 'text-success', title: '' };
                }

                if (metric?.status === 'outstanding') {
                    const amount = app.parseAmount(metric.total_amount ?? metric.total_amount_label);
                    const fullLabel = metric.total_amount_label || app.formatRupiah(metric.total_amount, { fallback: '' });
                    // "Rp 12,5 jt" fits a half-width tile; the exact amount stays readable.
                    const shortLabel = amount !== null && amount >= 1000000 ? `Rp ${compactAmount.format(amount)}` : fullLabel;

                    return {
                        value: shortLabel || 'Ada',
                        srValue: shortLabel !== fullLabel ? fullLabel : '',
                        note: Number.isInteger(metric.count) && metric.count > 0
                            ? `${metric.count} tagihan belum lunas`
                            : (fullLabel ? 'Belum lunas' : 'Nominal belum terbaca'),
                        tone: 'text-danger',
                        title: fullLabel ? `Total ${fullLabel} belum lunas.` : 'Ada tagihan yang belum lunas.',
                    };
                }

                if (metric?.status === 'unknown') {
                    // An unreadable bill table is never shown as a zero amount (F34).
                    return { value: '–', note: 'Status tidak terbaca', title: 'Status tagihan tidak terbaca dari portal.' };
                }

                return notReady(metric?.status, {
                    pending: 'Tagihan sedang diambil dari portal.',
                    unavailable: 'Status tagihan tidak terbaca dari portal.',
                    failed: 'Tagihan belum bisa dibaca dari portal. Coba sinkronkan lagi.',
                });
            };

            const describeSkkm = (metric) => {
                if (metric?.status === 'ready' && metric.valid_points !== null && metric.valid_points !== undefined) {
                    const required = metric.required_points ?? null;
                    const percent = metric.progress_percent !== null && metric.progress_percent !== undefined
                        ? clampPercent(metric.progress_percent)
                        : (required ? clampPercent((Number(metric.valid_points) / Number(required)) * 100) : null);
                    let note = 'Target poin belum diketahui';

                    if (metric.is_met === true) {
                        note = 'Syarat lulus terpenuhi';
                    } else if (metric.remaining_points !== null && metric.remaining_points !== undefined && Number(metric.remaining_points) > 0) {
                        note = `Kurang ${formatPoints(metric.remaining_points)} poin untuk syarat lulus`;
                    } else if (required) {
                        note = `${Math.round(percent ?? 0)}% dari target`;
                    }

                    return {
                        value: required ? `${formatPoints(metric.valid_points)}/${formatPoints(required)} poin` : `${formatPoints(metric.valid_points)} poin`,
                        note,
                        tone: metric.is_met === true ? 'text-success' : null,
                        title: 'Poin SKKM yang sudah divalidasi.',
                        progress: percent,
                        progressTone: metric.is_met === true ? 'success' : 'brand',
                    };
                }

                return notReady(metric?.status, {
                    pending: 'Poin SKKM sedang diambil dari SIREKA.',
                    unavailable: 'SIREKA tidak bisa diakses, jadi poin SKKM belum terbaca.',
                    unavailableNote: 'SIREKA belum bisa dibaca',
                    failed: 'Poin SKKM belum bisa dibaca. Coba sinkronkan lagi.',
                });
            };

            const renderProgressTrack = (percent, tone = 'brand') => `
                <span class="mycic-progress-track h-1.5">
                    ${percent !== null && percent > 0 ? `<span class="mycic-progress-fill ${app.fillToneClass(tone)}" style="width: ${percent.toFixed(1)}%"></span>` : ''}
                </span>
            `;

            const setMetric = (key, { value = '–', srValue = '', note = '', title = '', tone = null, pending = false, progress = null, progressTone = 'brand' } = {}) => {
                const tile = document.querySelector(`[data-dashboard-metric="${key}"]`);

                if (!tile) {
                    return;
                }

                const valueNode = tile.querySelector('[data-metric-value]');
                const noteNode = tile.querySelector('[data-metric-note]');
                const barNode = tile.querySelector('[data-metric-bar]');
                const text = String(value ?? '–');

                if (text === '–') {
                    valueNode.innerHTML = '<span aria-hidden="true">–</span><span class="sr-only">belum ada data</span>';
                } else if (srValue) {
                    valueNode.innerHTML = `<span aria-hidden="true">${esc(text)}</span><span class="sr-only">${esc(srValue)}</span>`;
                } else {
                    valueNode.textContent = text;
                }
                valueNode.classList.remove(...METRIC_VALUE_TONES);

                if (tone) {
                    valueNode.classList.add(tone);
                }

                // A long value ("Rp 750.000") must fit a half-width tile at 375px and in the lg aside.
                if (valueNode.classList.contains('mycic-metric-value')) {
                    const isLong = text.length > 8;

                    METRIC_LONG_VALUE_CLASSES.forEach((className) => valueNode.classList.toggle(className, isLong));
                }

                noteNode.innerHTML = pending
                    ? `<span class="mycic-spinner h-3 w-3 shrink-0" aria-hidden="true"></span><span>${esc(note)}</span>`
                    : `<span>${esc(note)}</span>`;

                if (barNode) {
                    barNode.innerHTML = renderProgressTrack(progress, progressTone);
                }

                if (title) {
                    tile.title = title;
                } else {
                    tile.removeAttribute('title');
                }

                tile.removeAttribute('aria-busy');
            };

            const renderMetrics = (data) => {
                const metrics = data?.metrics;

                // A device copy from before the metrics existed: keep the skeletons.
                if (!metrics || typeof metrics !== 'object') {
                    return;
                }

                setMetric('ipk', describeIpk(metrics.ipk));
                setMetric('sks_passed', describeSksPassed(metrics.sks_passed));
                setMetric('sks_current', describeSksCurrent(metrics.sks_current, data));
                setMetric('bills', describeBills(metrics.bills));
                setMetric('skkm', describeSkkm(metrics.skkm));
            };

            const markMetricsUnavailable = () => {
                ['ipk', 'sks_passed', 'sks_current', 'bills', 'skkm'].forEach((key) => {
                    const tile = document.querySelector(`[data-dashboard-metric="${key}"]`);

                    if (tile?.hasAttribute('aria-busy')) {
                        setMetric(key, { value: '–', note: 'Gagal dimuat', title: 'Ringkasan belum bisa dimuat. Coba sinkronkan lagi.' });
                    }
                });
            };


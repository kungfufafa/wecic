{{--
    KRS script, the DPA's contact and the WhatsApp message drafted for it.
    Part of the DOMContentLoaded handler opened in academic/krs.blade.php:
    it shares that scope (app, esc, hosts, state and the helpers in setup).
--}}
            const hasContact = (dpa) => Boolean(dpa && ['name', 'phone', 'whatsapp', 'email'].some((key) => dpa[key]));

            const currentDpa = () => {
                const candidates = [state.advising?.dpa, state.krs?.insights?.dpa, state.krs?.dpa];

                return candidates.find(hasContact) || null;
            };

            const student = () => ({ ...(state.advising?.student || {}), ...(state.krs?.student || {}) });

            const greeting = () => {
                const hour = new Date().getHours();

                if (hour < 11) {
                    return 'Selamat pagi';
                }

                if (hour < 15) {
                    return 'Selamat siang';
                }

                return hour < 18 ? 'Selamat sore' : 'Selamat malam';
            };

            const dpaContact = (statusCode) => {
                const dpa = currentDpa();

                if (!dpa) {
                    return null;
                }

                const me = student();
                const identity = [me.name ? `Saya ${me.name}` : 'Saya', me.nim ? `NIM ${me.nim}` : ''].filter(Boolean).join(', ');
                const academicPeriod = state.krs?.academic_period;
                const termWord = academicPeriod?.term === 'ganjil' ? 'Ganjil' : (academicPeriod?.term === 'genap' ? 'Genap' : '');
                const period = academicPeriod?.academic_year
                    ? `T.A ${academicPeriod.academic_year}${termWord ? ` semester ${termWord}` : ''}`
                    : 'semester ini';
                const dpaName = String(dpa.name || '').replace(/[\s.]+$/, '');
                const purpose = {
                    pending_dpa: `Saya sudah mengajukan KRS ${period} dan mohon kesediaan Bapak/Ibu untuk memvalidasinya.`,
                    not_submitted: `Saya ingin berkonsultasi tentang pengisian KRS ${period}.`,
                }[statusCode] || `Saya ingin berkonsultasi tentang KRS ${period}.`;

                return {
                    name: dpa.name || 'Dosen Pembimbing Akademik',
                    role: 'Dosen Pembimbing Akademik (DPA)',
                    email: dpa.email || '',
                    phone: dpa.phone || '',
                    whatsapp: dpa.whatsapp || '',
                    message: `${greeting()} Bapak/Ibu ${dpaName}. ${identity}, mahasiswa bimbingan akademik Bapak/Ibu. ${purpose} Terima kasih.`.replace(/\s+/g, ' ').trim(),
                };
            };


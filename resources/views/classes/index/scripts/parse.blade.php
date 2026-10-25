{{--
    Jadwal & Kelas script, the course colour palettes, the text and time
    helpers, and GET /api/class parsed into classes grouped by day with the
    free hours between them (parseClassItem, processDayGroups). Part of the
    DOMContentLoaded handler opened in classes/index.blade.php: it shares
    that scope (app, the elements, constants and state in setup).
--}}
            const COURSE_PALETTES = [
                {
                    bg: 'bg-blue-500/10 dark:bg-blue-500/20',
                    border: 'border-blue-500/30 dark:border-blue-400/30',
                    accent: 'border-l-blue-600 dark:border-l-blue-400',
                    badge: 'bg-blue-600 text-white dark:bg-blue-500 dark:text-white',
                    timeBadge: 'bg-blue-500/15 text-blue-800 dark:text-blue-200',
                    dot: 'bg-blue-600 dark:bg-blue-400',
                    ring: 'hover:ring-blue-500/30',
                },
                {
                    bg: 'bg-emerald-500/10 dark:bg-emerald-500/20',
                    border: 'border-emerald-500/30 dark:border-emerald-400/30',
                    accent: 'border-l-emerald-600 dark:border-l-emerald-400',
                    badge: 'bg-emerald-600 text-white dark:bg-emerald-500 dark:text-white',
                    timeBadge: 'bg-emerald-500/15 text-emerald-800 dark:text-emerald-200',
                    dot: 'bg-emerald-600 dark:bg-emerald-400',
                    ring: 'hover:ring-emerald-500/30',
                },
                {
                    bg: 'bg-violet-500/10 dark:bg-violet-500/20',
                    border: 'border-violet-500/30 dark:border-violet-400/30',
                    accent: 'border-l-violet-600 dark:border-l-violet-400',
                    badge: 'bg-violet-600 text-white dark:bg-violet-500 dark:text-white',
                    timeBadge: 'bg-violet-500/15 text-violet-800 dark:text-violet-200',
                    dot: 'bg-violet-600 dark:bg-violet-400',
                    ring: 'hover:ring-violet-500/30',
                },
                {
                    bg: 'bg-amber-500/10 dark:bg-amber-500/20',
                    border: 'border-amber-500/30 dark:border-amber-400/30',
                    accent: 'border-l-amber-600 dark:border-l-amber-400',
                    badge: 'bg-amber-600 text-white dark:bg-amber-500 dark:text-white',
                    timeBadge: 'bg-amber-500/15 text-amber-900 dark:text-amber-200',
                    dot: 'bg-amber-600 dark:bg-amber-400',
                    ring: 'hover:ring-amber-500/30',
                },
                {
                    bg: 'bg-rose-500/10 dark:bg-rose-500/20',
                    border: 'border-rose-500/30 dark:border-rose-400/30',
                    accent: 'border-l-rose-600 dark:border-l-rose-400',
                    badge: 'bg-rose-600 text-white dark:bg-rose-500 dark:text-white',
                    timeBadge: 'bg-rose-500/15 text-rose-800 dark:text-rose-200',
                    dot: 'bg-rose-600 dark:bg-rose-400',
                    ring: 'hover:ring-rose-500/30',
                },
                {
                    bg: 'bg-teal-500/10 dark:bg-teal-500/20',
                    border: 'border-teal-500/30 dark:border-teal-400/30',
                    accent: 'border-l-teal-600 dark:border-l-teal-400',
                    badge: 'bg-teal-600 text-white dark:bg-teal-500 dark:text-white',
                    timeBadge: 'bg-teal-500/15 text-teal-800 dark:text-teal-200',
                    dot: 'bg-teal-600 dark:bg-teal-400',
                    ring: 'hover:ring-teal-500/30',
                },
                {
                    bg: 'bg-indigo-500/10 dark:bg-indigo-500/20',
                    border: 'border-indigo-500/30 dark:border-indigo-400/30',
                    accent: 'border-l-indigo-600 dark:border-l-indigo-400',
                    badge: 'bg-indigo-600 text-white dark:bg-indigo-500 dark:text-white',
                    timeBadge: 'bg-indigo-500/15 text-indigo-800 dark:text-indigo-200',
                    dot: 'bg-indigo-600 dark:bg-indigo-400',
                    ring: 'hover:ring-indigo-500/30',
                },
            ];

            const cleanText = (str) => {
                if (!str) return '';
                return String(str)
                    .replace(/&nbsp;/gi, ' ')
                    .replace(/&amp;/gi, '&')
                    .replace(/&quot;/gi, '"')
                    .replace(/&#039;/gi, "'")
                    .replace(/&lt;/gi, '<')
                    .replace(/&gt;/gi, '>')
                    .replace(/\s+/g, ' ')
                    .trim();
            };

            const normalizeDay = (rawDay) => {
                if (!rawDay) return null;
                const clean = String(rawDay).trim().toLowerCase();
                if (clean.includes('senin') || clean.includes('monday')) return 'Senin';
                if (clean.includes('selasa') || clean.includes('tuesday')) return 'Selasa';
                if (clean.includes('rabu') || clean.includes('wednesday')) return 'Rabu';
                if (clean.includes('kamis') || clean.includes('thursday')) return 'Kamis';
                if (clean.includes('jumat') || clean.includes("jum'at") || clean.includes('friday')) return 'Jumat';
                if (clean.includes('sabtu') || clean.includes('saturday')) return 'Sabtu';
                if (clean.includes('minggu') || clean.includes('ahad') || clean.includes('sunday')) return 'Minggu';
                return null;
            };

            const parseTimeToMinutes = (str) => {
                if (!str) return null;
                const match = String(str).match(/(\d{1,2}):(\d{2})/);
                if (!match) return null;
                return parseInt(match[1], 10) * 60 + parseInt(match[2], 10);
            };

            const formatMinutesToTime = (minutes) => {
                const h = Math.floor(minutes / 60).toString().padStart(2, '0');
                const m = (minutes % 60).toString().padStart(2, '0');
                return `${h}:${m}`;
            };

            const formatDuration = (minutes) => {
                const h = Math.floor(minutes / 60);
                const m = minutes % 60;
                if (h > 0 && m > 0) return `${h} jam ${m} menit`;
                if (h > 0) return `${h} jam`;
                return `${m} menit`;
            };

            const getCoursePalette = (code, index) => {
                if (!code) return COURSE_PALETTES[index % COURSE_PALETTES.length];
                let hash = 0;
                for (let i = 0; i < code.length; i++) {
                    hash = (hash << 5) - hash + code.charCodeAt(i);
                    hash |= 0;
                }
                return COURSE_PALETTES[Math.abs(hash) % COURSE_PALETTES.length];
            };

            const parseClassItem = (klass, index) => {
                const params = klass.join_link?.params || {};
                const courseInfo = klass.course_info || {};
                const lecturerInfo = klass.lecturer_info || {};
                const schedule = klass.schedule || {};
                const classRoomInfo = klass.class_room_info || {};

                const rawSchedule = schedule.raw || schedule.formatted_time || schedule.time_range || schedule.time || '';
                const day = normalizeDay(schedule.day || rawSchedule);

                let startMin = parseTimeToMinutes(schedule.start_time);
                let endMin = parseTimeToMinutes(schedule.end_time);

                if (startMin === null || endMin === null) {
                    const timeMatch = (schedule.time_range || schedule.formatted_time || schedule.time || rawSchedule).match(/(\d{1,2}:\d{2})(?::\d{2})?\s*(?:-|sd|s\/d|sampai)\s*(\d{1,2}:\d{2})(?::\d{2})?/i);
                    if (timeMatch) {
                        if (startMin === null) startMin = parseTimeToMinutes(timeMatch[1]);
                        if (endMin === null) endMin = parseTimeToMinutes(timeMatch[2]);
                    }
                }

                if (startMin !== null && endMin === null) {
                    endMin = startMin + 90;
                }

                const startTimeFormatted = startMin !== null ? formatMinutesToTime(startMin) : '-';
                const endTimeFormatted = endMin !== null ? formatMinutesToTime(endMin) : '-';
                const durationMinutes = startMin !== null && endMin !== null ? Math.max(endMin - startMin, 30) : 90;
                const formattedTime = schedule.formatted_time || (startMin !== null && endMin !== null ? `${startTimeFormatted} - ${endTimeFormatted}` : rawSchedule || '-');

                const classUrl = app.buildClassUrl(params.id, params.dosen_id, params.course_id);
                const detailApiUrl = params.id && params.dosen_id && params.course_id
                    ? `/api/class/${params.id}?dosen_id=${params.dosen_id}&course_id=${params.course_id}`
                    : null;

                const code = cleanText(courseInfo.code || '');
                const rawName = cleanText(courseInfo.name || 'Tanpa judul');
                const roomClean = cleanText(classRoomInfo.room || courseInfo.room || '');
                const classGroupClean = cleanText(classRoomInfo.class_name || courseInfo.class_name || '');
                const lecturerClean = cleanText(lecturerInfo.name || 'Dosen tidak tertera');

                const palette = getCoursePalette(code || rawName || `class-${index}`, index);

                return {
                    id: index,
                    courseCode: code,
                    courseName: rawName,
                    lecturerName: lecturerClean,
                    lecturerContact: lecturerInfo.contact || null,
                    lecturerEmail: lecturerInfo.email || null,
                    roomName: roomClean || 'Ruang belum ditentukan',
                    className: classGroupClean && classGroupClean !== '-' ? classGroupClean : 'Kelas Reguler',
                    day,
                    startMin,
                    endMin,
                    startTimeFormatted,
                    endTimeFormatted,
                    durationMinutes,
                    formattedTime,
                    classUrl,
                    detailApiUrl,
                    palette,
                    original: klass,
                };
            };

            const processDayGroups = (parsedClasses) => {
                const dayMap = {};
                DAYS_LIST.forEach((d) => {
                    dayMap[d] = {
                        day: d,
                        classes: [],
                        gaps: [],
                        totalMinutes: 0,
                    };
                });

                const unscheduled = [];

                parsedClasses.forEach((c) => {
                    if (c.day && dayMap[c.day]) {
                        dayMap[c.day].classes.push(c);
                    } else {
                        unscheduled.push(c);
                    }
                });

                DAYS_LIST.forEach((d) => {
                    const group = dayMap[d];
                    group.classes.sort((a, b) => {
                        const aStart = a.startMin !== null ? a.startMin : 9999;
                        const bStart = b.startMin !== null ? b.startMin : 9999;
                        return aStart - bStart;
                    });

                    let totalDuration = 0;
                    group.classes.forEach((c) => {
                        if (c.startMin !== null && c.endMin !== null) {
                            totalDuration += Math.max(c.endMin - c.startMin, 0);
                        }
                    });
                    group.totalMinutes = totalDuration;

                    // Compute gaps between classes
                    const gaps = [];
                    for (let i = 0; i < group.classes.length - 1; i++) {
                        const cur = group.classes[i];
                        const next = group.classes[i + 1];

                        if (cur.endMin !== null && next.startMin !== null && next.startMin > cur.endMin) {
                            const gapDuration = next.startMin - cur.endMin;
                            if (gapDuration >= 15) {
                                gaps.push({
                                    startMin: cur.endMin,
                                    endMin: next.startMin,
                                    startTime: formatMinutesToTime(cur.endMin),
                                    endTime: formatMinutesToTime(next.startMin),
                                    durationMinutes: gapDuration,
                                    formattedDuration: formatDuration(gapDuration),
                                    beforeClass: cur.courseName,
                                    afterClass: next.courseName,
                                });
                            }
                        }
                    }
                    group.gaps = gaps;
                });

                return { dayMap, unscheduled };
            };


{{--
    Jadwal & Kelas script, the 4-tone course palette, text/time helpers,
    live-class check, and GET /api/class parsed into classes grouped by day
    with the free hours between them (parseClassItem, processDayGroups).
    Part of the DOMContentLoaded handler opened in classes/index.blade.php:
    it shares that scope (app, the elements, constants and state in setup).
--}}
            const COURSE_PALETTES = Object.freeze([
                Object.freeze({
                    bg: 'bg-surface-tint/70 dark:bg-surface',
                    border: 'border-brand/25',
                    accentBorder: 'border-s-[3px] border-s-brand',
                    dot: 'bg-brand',
                    badge: app.ui.badge.brand,
                }),
                Object.freeze({
                    bg: 'bg-info-soft/70 dark:bg-surface',
                    border: 'border-info/25',
                    accentBorder: 'border-s-[3px] border-s-info',
                    dot: 'bg-info',
                    badge: app.ui.badge.info,
                }),
                Object.freeze({
                    bg: 'bg-success-soft/70 dark:bg-surface',
                    border: 'border-success/25',
                    accentBorder: 'border-s-[3px] border-s-success',
                    dot: 'bg-success',
                    badge: app.ui.badge.success,
                }),
                Object.freeze({
                    bg: 'bg-warning-soft/70 dark:bg-surface',
                    border: 'border-warning/25',
                    accentBorder: 'border-s-[3px] border-s-warning',
                    dot: 'bg-warning',
                    badge: app.ui.badge.warning,
                }),
            ]);

            const coursePaletteFor = (key, fallbackIndex = 0) => {
                const text = String(key || '').trim();

                if (!text) {
                    return COURSE_PALETTES[fallbackIndex % COURSE_PALETTES.length];
                }

                let hash = 0;
                for (let i = 0; i < text.length; i++) {
                    hash = (hash * 31 + text.charCodeAt(i)) | 0;
                }

                return COURSE_PALETTES[Math.abs(hash) % COURSE_PALETTES.length];
            };

            const isClassOngoingNow = (c) => {
                if (!c || c.day !== TODAY_DAY || c.startMin === null || c.endMin === null) {
                    return false;
                }

                const now = new Date();
                const minutesNow = now.getHours() * 60 + now.getMinutes();

                return minutesNow >= c.startMin && minutesNow <= c.endMin;
            };

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
                    palette: coursePaletteFor(code || rawName, index),
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


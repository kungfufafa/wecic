{{--
    Pertemuan script, reading the portal's meeting detail: the assignment
    upload parameters, the meeting number, the course name and topic, the
    outline, file sizes, and the meeting day. Part of the DOMContentLoaded
    handler opened in meetings/show.blade.php: it shares that scope (app,
    meetingId, coursesId, endpoint and the constants in setup).
--}}
            const parseAssignmentParams = (href) => {
                if (!href) {
                    return null;
                }

                const query = href.includes('?') ? href.split('?')[1] : href;
                const params = new URLSearchParams(query);

                if (!params.get('courses_id') || !params.get('id') || !params.get('pertemuan')) {
                    return null;
                }

                return {
                    courses_id: params.get('courses_id'),
                    id: params.get('id'),
                    pertemuan: params.get('pertemuan'),
                };
            };

            const inferMeetingNumber = (materials) => {
                return materials.assignment_topic?.match(/(\d+)/)?.[1]
                    || materials.main_topic?.match(/Pertemuan\s+(\d+)/i)?.[1]
                    || null;
            };

            const deriveAssignmentParams = (assignmentInfo, materials) => {
                const directParams = parseAssignmentParams(assignmentInfo.upload_link);

                if (directParams) {
                    return directParams;
                }

                const hasAssignmentContent = Boolean(
                    materials.assignment_topic
                    || materials.assignment_instructions
                    || materials.assignment_material_iframe
                    || String(assignmentInfo.upload_text || '').trim()
                    || String(assignmentInfo.deadline || '').trim()
                );
                // The server resolves the meeting number from "Pertemuan N";
                // the topic regex is only a fallback.
                const inferredMeetingNumber = assignmentInfo.meeting_number || inferMeetingNumber(materials);

                if (!hasAssignmentContent || !coursesId || !meetingId || !inferredMeetingNumber) {
                    return null;
                }

                return {
                    courses_id: String(coursesId),
                    id: String(meetingId),
                    pertemuan: String(inferredMeetingNumber),
                };
            };

            const extractCourseName = (breadcrumb) => {
                if (!breadcrumb) {
                    return null;
                }

                const match = breadcrumb.match(/Kelas\s+([^>]+?)(?:\s*>|$)/i);
                if (match && match[1]) {
                    return match[1].trim();
                }

                return null;
            };

            const cleanTopic = (topic) => {
                if (!topic) {
                    return '';
                }

                return String(topic)
                    .replace(/^(?:Materi\s+Pertemuan\s+\d+\s*)?(?:(?:Pokok\s+Bahasan|Materi|Topik)\s*:\s*)?/i, '')
                    .trim();
            };

            const parseOutlineItems = (text) => {
                if (!text) {
                    return [];
                }

                const clean = text.replace(/&amp;/g, '&');
                if (/\d+\.\s*/.test(clean)) {
                    const parts = clean.split(/(?=\d+\.\s*)/).map(s => s.trim()).filter(Boolean);
                    if (parts.length > 1) {
                        return parts.map(p => {
                            const match = p.match(/^(\d+)\.\s*(.*)$/s);
                            if (match) {
                                return { num: match[1], text: match[2].trim() };
                            }
                            return { num: '', text: p };
                        });
                    }
                }

                const lines = clean.split(/\r?\n/).map(s => s.trim()).filter(Boolean);
                if (lines.length > 1) {
                    return lines.map((l, i) => ({ num: String(i + 1), text: l }));
                }

                return [{ num: '', text: clean }];
            };

            const formatBytes = (bytes) => {
                if (!bytes || bytes === 0) return '0 B';
                const k = 1024;
                const sizes = ['B', 'KB', 'MB', 'GB'];
                const i = Math.floor(Math.log(bytes) / Math.log(k));
                return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
            };

            /**
             * The meeting day as `YYYY-MM-DD`, from "Senin, 20 April 2026",
             * "20-04-2026" or an ISO date; null when it cannot be read.
             */
            const meetingDayIso = (value) => {
                const text = String(value || '').trim();
                let match = text.match(/(\d{4})-(\d{2})-(\d{2})/);

                if (match) {
                    return `${match[1]}-${match[2]}-${match[3]}`;
                }

                match = text.match(/(\d{1,2})\s+([A-Za-z]+)\s+(\d{4})/);
                const month = match ? MONTHS[match[2].toLowerCase()] : undefined;

                if (month) {
                    return `${match[3]}-${String(month).padStart(2, '0')}-${match[1].padStart(2, '0')}`;
                }

                match = text.match(/(\d{1,2})[-/](\d{1,2})[-/](\d{4})/);

                return match ? `${match[3]}-${match[2].padStart(2, '0')}-${match[1].padStart(2, '0')}` : null;
            };


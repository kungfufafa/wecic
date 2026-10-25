{{--
    Pertemuan script, render(): the payload's signature check and the
    meeting, attendance and assignment state derived from it; the hero, the
    content and the action cards are its parts in scripts/render. Part of
    the DOMContentLoaded handler opened in meetings/show.blade.php: it
    shares that scope (app, meetingId, coursesId, endpoint and the constants
    in setup).
--}}
            let renderedPayloadSignature = null;

            const render = (payload) => {
                const detail = payload.class_detail || {};
                const notebookLimitReached = payload?.meta?.notebook_limit_reached === true || detail.notebook_limit_reached === true;

                // The same data again (cached copy, then the network copy, then a
                // re-sync): leave the page as it is, open previews included.
                const payloadSignature = JSON.stringify([detail, notebookLimitReached, new Date().toLocaleDateString('sv-SE')]);

                if (payloadSignature === renderedPayloadSignature && document.querySelector('[data-meeting-slot]')) {
                    return;
                }

                renderedPayloadSignature = payloadSignature;
                const meetingInfo = detail.meeting_info || {};
                const lecturerInfo = detail.lecturer_info || {};
                const scheduleInfo = detail.schedule_info || {};
                const attendanceInfo = detail.attendance_info || {};
                const assignmentInfo = detail.assignment_info || {};
                const materials = detail.materials || {};
                const assignmentParams = deriveAssignmentParams(assignmentInfo, materials);
                const assignmentSubmission = assignmentInfo.submission || null;
                const assignmentFileInfo = assignmentSubmission?.file_info || null;
                const assignmentDeletePayload = assignmentInfo.delete_payload || null;
                const assignmentStatus = assignmentInfo.status || null;
                const assignmentAlreadyUploaded = assignmentStatus === 'uploaded';
                const inferredMeetingNumber = assignmentParams?.pertemuan
                    || assignmentInfo.meeting_number
                    || inferMeetingNumber(materials)
                    || (meetingInfo.breadcrumb?.match(/Pertemuan\s+(\d+)/i)?.[1])
                    || '';
                const attendanceStatusText = String(attendanceInfo.status || '').trim();

                // Attendance is only taken on the meeting day; an older meeting's
                // form would send today's code to the wrong meeting. The page's
                // attendance is read like the server reads it: a window the portal
                // does not show ("Waktu Absensi Tidak Tersedia") is neither presence
                // nor absence.
                const meetingDay = meetingDayIso(scheduleInfo.date);
                const todayIso = new Date().toLocaleDateString('sv-SE');
                const attendanceView = app.meetingAttendanceView(attendanceInfo, meetingDay, todayIso);
                const attendanceRecorded = attendanceView === 'recorded';
                const attendanceNotOpen = attendanceView === 'not_open';
                const meetingTiming = !meetingDay ? 'unknown' : (meetingDay < todayIso ? 'past' : (meetingDay > todayIso ? 'upcoming' : 'today'));
                const attendanceState = attendanceRecorded ? 'recorded' : (['past', 'upcoming'].includes(meetingTiming) ? meetingTiming : 'open');

                const courseName = extractCourseName(meetingInfo.breadcrumb) || 'Mata Kuliah';
                const mainTopicClean = cleanTopic(materials.main_topic || materials.assignment_topic) || 'Pembahasan Pertemuan';
                const meetingLabel = inferredMeetingNumber ? `P${inferredMeetingNumber}` : 'Pertemuan';

                @include('meetings.show.scripts.render.hero')
                @include('meetings.show.scripts.render.content')
                @include('meetings.show.scripts.render.action-cards')
                @include('meetings.show.scripts.render.attendance')
                @include('meetings.show.scripts.render.assignment')
                @include('meetings.show.scripts.render.notebook')
                app.refreshOnlineRequiredState();
                consumeQuickScanRequest(attendanceState);
            };


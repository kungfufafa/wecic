import type { ApiEnvelope } from '@/api/types';

/** Where an attendance code goes: the meeting by its course and module ids. */
export type AttendancePayload = { courseid?: string | number | null; modulesid?: string | number | null; pertemuan?: number | null };

/** A class's attendance as the server reports it; `already_marked` once the portal recorded it. */
export type AttendanceStatus = { already_marked?: boolean; status?: string | null; label?: string | null; time?: string | null };

export type TodayAttendanceClass = {
  class_id?: string | null;
  meeting_id?: string | null;
  meeting_number?: number | null;
  course_code?: string | null;
  course_name?: string | null;
  lecturer_name?: string | null;
  schedule?: { day?: string | null; time?: string | null; start_time?: string | null; end_time?: string | null };
  today_material?: { title?: string | null; date?: string | null; time?: string | null; meeting_number?: number | null; is_today?: boolean } | null;
  attendance_target_reliable?: boolean;
  active_now?: boolean;
  attendance_status?: AttendanceStatus & { window?: 'not_open' | 'closed' | null };
  attendance_payload?: AttendancePayload | null;
  assignment_payload?: { courses_id?: string | null; id?: string | null; pertemuan?: number | null } | null;
};

export type TodayAttendanceResponse = ApiEnvelope & {
  data: { date?: string; day?: string; current_time?: string; classes: TodayAttendanceClass[] };
};

export type AttendanceResponse = ApiEnvelope & {
  /** `attendance`: the class's status right after a recorded attendance, so a screen updates without asking the portal again. */
  data?: { status?: 'marked' | 'already_marked' | string; attendance?: AttendanceStatus | null } | null;
};

export type PortalFileLink = { href?: string | null; text?: string | null };

export type MeetingDetail = {
  content_type?: string;
  found?: boolean;
  class_url?: string | null;
  meeting_info?: { title?: string | null; breadcrumb?: string | null; class_ref?: { id?: string; dosen_id?: string; course_id?: string } | null };
  lecturer_info?: { name?: string | null };
  schedule_info?: { date?: string | null; start_time?: string | null; attendance_time?: string | null };
  rps_info?: { file_link?: string | null; file_text?: string | null };
  attendance_info?: { status?: string | null; time?: string | null; state?: string | null };
  assignment_info?: {
    deadline?: string | null;
    upload_link?: string | null;
    upload_text?: string | null;
    status_text?: string | null;
    meeting_number?: string | number | null;
    status?: 'uploaded' | 'not_uploaded' | string | null;
    submission?: { no?: string; date?: string | null; meeting?: string | null; file_info?: PortalFileLink | null; score?: string | null } | null;
    delete_payload?: { id: string | number; idx: string | number; pertemuan: string | number } | null;
  };
  conformity_info?: { description?: string | null };
  materials?: {
    main_topic?: string | null;
    main_description?: string | null;
    main_material_iframe?: string | null;
    assignment_topic?: string | null;
    assignment_instructions?: string | null;
    assignment_material_iframe?: string | null;
  };
  instructions?: { deadline_text?: string | null; conformity_note?: string | null };
};

export type MeetingResponse = ApiEnvelope & {
  class_detail: MeetingDetail;
  meta?: { meeting_id?: string; courses_id?: string };
};

export type ActionResponse = ApiEnvelope & { data?: { status?: string; operation_id?: string } | null };

export type ExamDetail = {
  exam_id?: string | number;
  exam_type?: string | null;
  title?: string | null;
  description?: string | null;
  status?: 'uploaded' | 'not_uploaded' | string | null;
  alerts?: unknown[];
  requirements?: unknown[];
  related_links?: { href?: string | null; text?: string | null; label?: string | null; url?: string | null }[];
  submissions?: Record<string, unknown>[];
  latest_submission?: Record<string, unknown> | null;
  form?: {
    available?: boolean;
    available_via_direct_submit?: boolean;
    file_field?: string | null;
    fields?: Record<string, unknown>;
    editable_fields?: string[];
  } | null;
  course?: { code?: string | null; name?: string | null; schedule?: string | null; lecturer?: string | null; status?: string | null } | null;
};

export type ExamDetailResponse = ApiEnvelope & { data: { exam_detail: ExamDetail } };

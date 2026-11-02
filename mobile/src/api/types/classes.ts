import type { ApiEnvelope } from '@/api/types';

/** A portal link: the href the portal printed and its text. */
export type PortalLink = { href?: string | null; text?: string | null };

export type MeetingAttendance = {
  meeting_number?: number | null;
  status?: string | null;
  details?: {
    button_text?: string | null;
    button_title?: string | null;
    attendance_date?: string | null;
    attendance_time?: string | null;
  } | null;
};

export type CourseMaterial = {
  title?: string | null;
  meeting_number?: number | null;
  date?: string | null;
  time?: string | null;
  description?: string | null;
  join_link?: PortalLink | null;
};

export type AssignmentSubmission = {
  no?: string | null;
  date?: string | null;
  meeting?: string | null;
  file_info?: PortalLink | null;
  score?: string | null;
};

/** `GET class/{id}`: one class page of the portal. */
export type ClassDetailResponse = ApiEnvelope & {
  class_detail?: {
    found?: boolean;
    course_info?: { name?: string | null; code?: string | null; credits?: string | null; class_name?: string | null; rps_link?: string | null };
    lecturer_info?: { name?: string | null; nidn?: string | null; contact?: string | null; email?: string | null };
    attendance_info?: {
      student_name?: string | null;
      meetings?: MeetingAttendance[];
      summary?: {
        total_meetings?: number;
        present?: number;
        late?: number;
        absent?: number;
        permit?: number;
        sick?: number;
        attendance_rate?: number | null;
        upcoming?: number;
        held_meetings?: number;
      };
    };
    assignment_history?: { assignments?: AssignmentSubmission[]; average_score?: string | null };
    course_materials?: CourseMaterial[];
  };
};

export type PendingAssignment = {
  meeting_id?: string | null;
  meeting_number?: number | null;
  course_code?: string | null;
  course_name?: string | null;
  lecturer_name?: string | null;
  material_title?: string | null;
  material_date?: string | null;
  deadline?: string | null;
  deadline_date?: string | null;
  meeting_url?: string | null;
  assignment_file_url?: string | null;
  can_submit_directly?: boolean;
  assignment_payload?: { courses_id?: string | null; id?: string | null; pertemuan?: number | null } | null;
};

export type PendingAssignmentsResponse = ApiEnvelope & { data: { assignments?: PendingAssignment[] } };

export type ExamListItem = {
  id?: string | number | null;
  course_code?: string | null;
  course_name?: string | null;
  schedule?: string | null;
  status?: string | null;
  upload_url?: string | null;
  lecturer?: string | null;
  can_upload?: boolean;
};

export type ExamListResponse = ApiEnvelope & { data: { exams?: ExamListItem[] } };

export type NotebookItem = {
  id: number;
  title: string;
  kind: 'course' | 'free' | string;
  course_key?: string | null;
  course_name?: string | null;
  course_code?: string | null;
  lecturer_name?: string | null;
  class_url?: string | null;
  sources_count?: number;
  meetings_count?: number;
  messages_count?: number;
  created_at?: string | null;
  updated_at?: string | null;
};

export type NotebooksResponse = ApiEnvelope & {
  data: {
    notebooks: NotebookItem[];
    ai_available?: boolean;
    ai_status?: string | null;
    course_notebooks?: boolean;
    studio_kinds?: Record<string, string>;
  };
};

export type NotebookSource = {
  id: number;
  kind?: string | null;
  title?: string | null;
  meeting_id?: string | null;
  meeting_number?: number | null;
  status?: string | null;
  error?: string | null;
  char_count?: number | null;
  page_count?: number | null;
  waiting?: boolean;
  stalled?: boolean;
};

export type NotebookMessage = {
  id: number;
  role: 'user' | 'assistant' | string;
  kind: string;
  content: string;
  data?: unknown;
  pinned?: boolean;
  created_at?: string | null;
};

export type NotebookMeeting = {
  meeting_id: string;
  courses_id?: string | null;
  meeting_number?: number | null;
  title?: string | null;
  date?: string | null;
  time?: string | null;
  description?: string | null;
  added?: boolean;
};

export type NotebookDetailResponse = ApiEnvelope & {
  data: {
    notebook: NotebookItem;
    sources?: NotebookSource[];
    messages?: NotebookMessage[];
    meetings?: { available?: boolean; meetings?: NotebookMeeting[] } | null;
    source_limit?: number | null;
    ai_available?: boolean;
    ai_status?: string | null;
    studio_kinds?: Record<string, string>;
  };
};

export type NotebookAskResponse = ApiEnvelope & { data: { question: NotebookMessage; answer: NotebookMessage } };
export type NotebookStudioResponse = ApiEnvelope & { data: { message: NotebookMessage } };
export type NotebookCreateResponse = ApiEnvelope & { data: { notebook: NotebookItem; created?: boolean } };

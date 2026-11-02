/**
 * Shapes of the academic endpoints the app reads: KRS and advising,
 * grades and transcript, SKKM and thesis. Only the fields the screens use
 * are typed; every one is optional because the portal decides what it holds.
 */

import type { ApiEnvelope } from '@/api/types';

export type KrsStatus = { code?: string | null; label?: string | null; raw_text?: string | null };

export type Dpa = {
  name?: string | null;
  nidn?: string | null;
  phone?: string | null;
  whatsapp?: string | null;
  email?: string | null;
};

export type KrsPeriod = {
  kind?: string | null;
  label?: string | null;
  start?: string | null;
  end?: string | null;
  is_open?: boolean;
  is_upcoming?: boolean;
  is_past?: boolean;
  days_left?: number | null;
  days_until_start?: number | null;
};

export type TrackerStep = { key?: string; label?: string | null; state?: string | null };

export type KrsCourse = {
  code?: string | null;
  name?: string | null;
  sks?: number | null;
  semester?: number | string | null;
  status?: string | null;
  is_retake?: boolean;
};

export type KrsTotals = { courses?: number | null; sks?: number | null };

export type Notice = { text?: string | null; dates?: string[]; is_future?: boolean; kind?: string | null };

export type KrsResponse = ApiEnvelope & {
  data?: {
    found?: boolean;
    academic_period?: { label?: string | null } | null;
    student?: { name?: string | null; nim?: string | null; program?: string | null } | null;
    period?: { start?: string | null; end?: string | null } | null;
    notice?: Notice | null;
    notice_text?: string | null;
    status?: KrsStatus | null;
    dpa?: Dpa | null;
    courses?: KrsCourse[];
    totals?: KrsTotals | null;
    insights?: {
      status?: KrsStatus | null;
      periods?: KrsPeriod[];
      active_period?: KrsPeriod | null;
      next_period?: KrsPeriod | null;
      is_period_open?: boolean;
      dpa?: Dpa | null;
      tracker?: TrackerStep[];
      study_load?: KrsTotals | null;
    } | null;
  };
};

export type KrsHistoryEntry = {
  krs_id?: number | null;
  academic_year?: string | null;
  date?: string | null;
  semester?: number | string | null;
  semester_is_regular?: boolean;
  semester_label?: string | null;
};

export type AdvisingResponse = ApiEnvelope & {
  data?: {
    found?: boolean;
    dpa?: Dpa | null;
    krs_history?: KrsHistoryEntry[];
    latest_krs_id?: number | null;
  };
};

export type KrsDetailResponse = ApiEnvelope & {
  data?: {
    found?: boolean;
    krs_id?: number | null;
    header?: {
      academic_year?: string | null;
      semester?: number | string | null;
      semester_label?: string | null;
      date?: string | null;
    } | null;
    counts_as_study_load?: boolean;
    status?: KrsStatus | null;
    courses?: KrsCourse[];
    totals?: KrsTotals | null;
    navigation?: { newer_krs_id?: number | null; older_krs_id?: number | null; is_latest?: boolean } | null;
  };
};

export type GradeAttempt = { semester?: number | string | null; letter?: string | null; weight?: number | null };

export type GradedCourse = {
  code?: string | null;
  name?: string | null;
  sks?: number | null;
  letter?: string | null;
  weight?: number | null;
  quality_points?: number | null;
  semester?: number | string | null;
  attempt_count?: number | null;
  attempts?: GradeAttempt[];
};

export type KhsCourse = GradedCourse & {
  components?: Record<string, number | string | null>;
  score?: number | null;
  is_graded?: boolean;
};

export type KhsSemester = {
  label?: string | null;
  semester?: number | string | null;
  is_regular?: boolean;
  status?: string | null;
  columns?: string[];
  courses?: KhsCourse[];
  course_count?: number | null;
  graded_count?: number | null;
  total_sks?: number | null;
  total_quality_points?: number | null;
  ips?: number | null;
};

export type IpsPoint = {
  semester?: number | string | null;
  label?: string | null;
  ips?: number | null;
  sks?: number | null;
  is_complete?: boolean;
};

export type LatestIps = { semester?: number | string | null; ips?: number | null; previous_ips?: number | null; delta?: number | null };

export type GraduationTarget = {
  predicate?: string | null;
  target_ipk?: number | null;
  required_ips?: number | null;
  achievable?: boolean;
  already_reached?: boolean;
};

export type StudyProgress = {
  target_sks?: number | null;
  earned_sks?: number | null;
  remaining_sks?: number | null;
  progress_percent?: number | null;
  average_sks_per_semester?: number | null;
  projected_semesters_remaining?: number | null;
  graduation_targets?: GraduationTarget[];
};

export type LetterCounts = Record<string, number>;

export type LowGrades = {
  d_courses?: GradedCourse[];
  e_courses?: GradedCourse[];
  low_grade_courses?: GradedCourse[];
  repeated_courses?: GradedCourse[];
};

export type GradesResponse = ApiEnvelope & {
  data?: {
    found?: boolean;
    semesters?: KhsSemester[];
    summary?: {
      official?: { ipk?: number | null; sks_total?: number | null; quality_points_total?: number | null; letter_counts?: LetterCounts } | null;
      computed?: {
        ipk?: number | null;
        letter_counts?: LetterCounts;
        ips_series?: IpsPoint[];
        latest_ips?: LatestIps | null;
      } & LowGrades | null;
      ipk?: number | null;
      sks_passed?: number | null;
      quality_points_total?: number | null;
      progress?: StudyProgress | null;
      predicate_estimate?: string | null;
    } | null;
  };
};

export type TranscriptResponse = ApiEnvelope & {
  data?: {
    summary?: {
      ipk?: number | null;
      sks_passed?: number | null;
      sks_total?: number | null;
      quality_points_total?: number | null;
      predicate_estimate?: string | null;
    } | null;
    progress?: StudyProgress | null;
    current_load?: { sks?: number | null; courses?: number | null; graded_courses?: number | null } | null;
    transcript?: GradedCourse[];
    letter_counts?: LetterCounts;
    low_grades?: LowGrades | null;
    is_partial?: boolean;
  };
};

export type SkkmEntry = {
  no?: number | null;
  name?: string | null;
  activity?: string | null;
  level?: string | null;
  type?: string | null;
  points?: number | null;
  points_label?: string | null;
  status?: string | null;
  status_label?: string | null;
  has_attachment?: boolean;
  attachment_path?: string | null;
};

export type SkkmResponse = ApiEnvelope & {
  data?: {
    found?: boolean;
    graduation_status?: { text?: string | null; is_met?: boolean } | null;
    entries?: SkkmEntry[];
    summary?: {
      valid_points?: number | null;
      pending_points?: number | null;
      invalid_points?: number | null;
      potential_points?: number | null;
      remaining_points?: number | null;
      progress_percent?: number | null;
      points_by_activity?: { activity?: string | null; entries?: number | null; valid_points?: number | null; pending_points?: number | null; invalid_points?: number | null }[];
    } | null;
    stats?: { required_points?: number | null } | null;
    notice?: Notice | null;
    message?: string | null;
    guide_path?: string | null;
    guide_year?: number | null;
    add_available?: boolean;
  };
};

export type ThesisResponse = ApiEnvelope & {
  data?: {
    found?: boolean;
    title?: string | null;
    student?: { jenjang?: string | null; program?: string | null } | null;
    requirements?: { thesis?: string[]; exam?: string[] } | null;
    thresholds?: { min_sks?: Record<string, number> | null; min_ipk?: number | null; max_d?: number | null; allow_e?: boolean } | null;
    documents?: { label?: string | null; path?: string | null; filename?: string | null }[];
    status?: { state?: string | null; message?: string | null; available?: boolean } | null;
    krs_hint?: { has_thesis_course?: boolean; course?: { name?: string | null; code?: string | null } | null; awaiting_status?: boolean } | null;
  };
};

export type ChecklistItem = {
  key?: string;
  kind?: string | null;
  label?: string | null;
  status?: string | null;
  current?: number | string | null;
  target?: number | string | null;
  hint?: string | null;
};

export type ThesisReadinessResponse = ApiEnvelope & {
  data?: {
    jenjang?: string | null;
    thresholds?: { min_sks?: number | null; min_ipk?: number | null; max_d?: number | null; allow_e?: boolean } | null;
    checklist_items?: ChecklistItem[];
    summary?: {
      passed?: number | null;
      failed?: number | null;
      unknown?: number | null;
      manual?: number | null;
      academic_ready?: boolean;
      progress_percent?: number | null;
    } | null;
    sks_progress?: { current?: number | null; target?: number | null; remaining?: number | null; percent?: number | null } | null;
    insights?: { warnings?: string[] } | null;
  };
};

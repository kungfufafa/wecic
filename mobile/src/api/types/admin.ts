/**
 * Shapes of the MyCIC API (`/api/v1`) behind the administration screens:
 * finance, announcements, biodata, library, archive and the outbox. Only
 * the fields the screens read are typed; portal data stays optional.
 */

import type { ApiEnvelope } from '@/api/types';

export type StageStatus = 'paid' | 'unpaid' | 'upcoming' | 'unknown';

export type PaymentStage = {
  stage: number;
  status: StageStatus;
  reason?: string | null;
  paid_at?: string | null;
  paid_at_label?: string | null;
  paid_amount?: number | null;
  installment_amount?: number | null;
  is_partial?: boolean;
  bill_amount?: number | null;
  deadline?: string | null;
};

export type StudyYearStages = {
  study_year: number;
  academic_year?: string | null;
  is_current?: boolean;
  paid_count?: number;
  unpaid_count?: number;
  upcoming_count?: number;
  unknown_count?: number;
  stages: PaymentStage[];
};

export type OutstandingBill = {
  no?: string | null;
  name?: string | null;
  amount?: number | null;
  amount_label?: string | null;
  description?: string | null;
  installment_stage?: number | null;
  study_year?: number | null;
};

export type OutstandingSummary = {
  status?: string;
  has_outstanding?: boolean;
  total_amount?: number | null;
  total_amount_label?: string | null;
  count?: number;
};

export type NextStage = {
  study_year?: number | null;
  academic_year?: string | null;
  stage?: number | null;
  status?: StageStatus;
  reason?: string | null;
  deadline?: string | null;
};

export type ActiveDeadline = {
  stage: number | null;
  date: string;
  days_left: number;
  text: string;
};

export type Payment = {
  no?: string | null;
  paid_at?: string | null;
  paid_at_label?: string | null;
  category?: string | null;
  description?: string | null;
  amount?: number | null;
  amount_label?: string | null;
  installment_stage?: number | null;
  study_year?: number | null;
  academic_year?: string | null;
};

export type FinanceSummaryResponse = ApiEnvelope & {
  data: {
    outstanding_summary?: OutstandingSummary;
    outstanding_bills?: OutstandingBill[];
    has_outstanding?: boolean;
    outstanding_total_label?: string | null;
    current_study_year?: number | null;
    current_academic_year?: string | null;
    current_year?: StudyYearStages | null;
    next_stage?: NextStage | null;
    active_deadline?: ActiveDeadline | null;
    latest_payment?: Payment | null;
    total_paid_amount?: number | null;
    portal_total_amount_label?: string | null;
    history_available?: boolean;
  };
};

export type StudyYearPayments = {
  study_year: number;
  academic_year?: string | null;
  payment_count?: number;
  total_amount?: number | null;
  total_amount_label?: string | null;
  stages_paid?: number[];
};

export type PaymentHistoryResponse = ApiEnvelope & {
  data: {
    payment_history?: Payment[];
    summary?: {
      total_records?: number;
      total_paid_amount?: number | null;
      portal_total_amount_label?: string | null;
      by_study_year?: StudyYearPayments[];
    };
    payment_stages?: StudyYearStages[];
  };
};

export type AnnouncementDeadline = {
  date?: string | null;
  start?: string | null;
  type?: string | null;
  context?: string | null;
  is_future?: boolean;
};

export type AnnouncementLink = {
  label?: string | null;
  url: string;
  is_file?: boolean;
  is_campus_file?: boolean;
  kind?: string | null;
};

export type Announcement = {
  id: string;
  title: string;
  published_date?: string | null;
  published_label?: string | null;
  publisher?: string | null;
  publisher_unit?: string | null;
  publisher_unit_short?: string | null;
  category?: string | null;
  category_label?: string | null;
  excerpt?: string | null;
  body_text?: string | null;
  links?: AnnouncementLink[];
  emails?: string[];
  deadlines?: AnnouncementDeadline[];
  steps?: { number: number; text: string }[];
};

export type PortalNotice = {
  text?: string | null;
  dates?: string[];
  is_future?: boolean;
  kind?: string | null;
};

export type UpcomingDeadline = AnnouncementDeadline & {
  announcement_id?: string | null;
  title?: string | null;
};

export type AnnouncementsResponse = ApiEnvelope & {
  data: {
    items: Announcement[];
    paging?: { has_more?: boolean; next_page?: number | null; total?: number | null; last_page?: number | null };
    notice?: PortalNotice | null;
    upcoming_deadlines?: UpcomingDeadline[];
  };
};

export type AnnouncementResponse = ApiEnvelope & { data: Announcement };

export type ProfileResponse = ApiEnvelope & {
  profile?: {
    personal_data?: Record<string, string | null>;
    contact_data?: Record<string, string | null>;
    education_data?: Record<string, string | null>;
    photo_url?: string | null;
  };
};

export type LibraryLink = {
  key: string;
  title: string;
  url: string;
  host?: string | null;
  is_internal?: boolean;
  internal_route?: string | null;
  requires_account?: boolean;
};

export type RepositorySummary = {
  status?: string | null;
  total?: number | null;
  record_count?: number | null;
  complete?: boolean;
  latest_academic_year?: string | null;
  programs?: ({ value?: string; name?: string; count?: number } | string)[];
  top_advisors?: ({ value?: string; name?: string; count?: number } | string)[];
  top_keywords?: ({ value?: string; keyword?: string; count?: number } | string)[];
};

export type LibraryResponse = ApiEnvelope & {
  data: { found?: boolean; links?: LibraryLink[]; repository?: RepositorySummary | null };
};

export type ThesisRecord = {
  record_key: string;
  academic_year?: string | null;
  program?: string | null;
  nim?: string | null;
  author?: string | null;
  title?: string | null;
  advisors?: { name: string; key?: string }[];
  advisor_1?: string | null;
  advisor_2?: string | null;
  file_path?: string | null;
};

export type Facet = { value: string; key?: string; count?: number };

export type ThesesResponse = ApiEnvelope & {
  data: { mode?: string; items: ThesisRecord[] };
  meta?: ApiEnvelope['meta'] & {
    total?: number;
    page?: number;
    last_page?: number;
    has_more?: boolean;
    sort?: string;
    index?: { partial?: boolean; total?: number | null; record_count?: number; complete?: boolean };
    facets?: { prodi?: Facet[]; ta?: Facet[]; pembimbing?: Facet[] };
  };
};

export type BackgroundSync = {
  available: boolean;
  enabled: boolean;
  active?: boolean;
  pending_login?: boolean;
  rejected?: boolean;
  interval_minutes?: number;
  credential_days?: number;
  last_synced_at?: string | null;
  last_status?: string | null;
  last_error?: string | null;
  courses_enabled?: boolean;
  courses_interval_minutes?: number;
  last_course_sync_at?: string | null;
};

export type ArchiveDataset = {
  dataset: string;
  label: string;
  versions: number;
  groups: number;
  open_suspects: number;
  last_seen_at: string | null;
};

export type ArchiveSummaryResponse = ApiEnvelope & {
  data: { datasets: ArchiveDataset[]; files?: { count: number; bytes: number }; background_sync?: BackgroundSync };
};

export type BackgroundSyncResponse = ApiEnvelope & { data: BackgroundSync };

export type ArchiveVersionStatus = 'trusted' | 'suspect' | 'rejected' | 'dismissed';

export type ArchiveVersion = {
  id: number;
  status: ArchiveVersionStatus;
  subject?: string | null;
  findings?: { rule?: string; label?: string; message?: string }[];
  first_seen_at?: string | null;
  last_seen_at?: string | null;
  seen_count?: number;
  is_current?: boolean;
};

export type ArchiveGroup = {
  params_hash: string;
  subject: string | null;
  versions: number;
  open_suspects: number;
  last_seen_at: string | null;
};

export type ArchiveVersionsResponse = ApiEnvelope & {
  data: { dataset: string; label: string; groups: ArchiveGroup[]; selected_params_hash: string | null; versions: ArchiveVersion[] };
};

export type OutboxItem = {
  id: number;
  action?: string | null;
  action_label?: string | null;
  subject?: string | null;
  url?: string | null;
  file_name?: string | null;
  deadline_at?: string | null;
  deadline_passed?: boolean;
  status?: string | null;
  status_label?: string | null;
  attempts?: number;
  last_error?: string | null;
  created_at?: string | null;
};

export type OutboxResponse = ApiEnvelope & {
  data: { items: OutboxItem[]; queued_count?: number; offline?: boolean; enabled?: boolean };
};

/** What `DELETE /account` erased, counted per kind. */
export type AccountDeletionResponse = ApiEnvelope & {
  data: Record<string, number>;
};

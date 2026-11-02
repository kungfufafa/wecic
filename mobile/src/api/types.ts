/**
 * Shapes of the MyCIC API (`/api/v1`) the app reads. The server's own
 * reference is the OpenAPI document at `/docs/api`; only the fields the app
 * uses are typed here, and portal data stays loosely typed because the
 * portal decides what it holds.
 */

export type ApiMeta = {
  timestamp?: string;
  campus_session_valid?: boolean;
  offline?: boolean;
};

/** Every answer: `status` and `message`, plus endpoint fields. */
export type ApiEnvelope = {
  status: 'success' | 'error';
  message: string;
  meta?: ApiMeta;
  errors?: Record<string, string[]>;
};

export type UserInfo = {
  name?: string | null;
  nim?: string | null;
  student_id?: string | null;
};

export type OfflineReconnect = 'automatic' | 'notify' | 'portal_back' | 'manual';

export type TokenLoginResponse = ApiEnvelope & {
  data: {
    user_info: UserInfo | null;
    expires_at: string | null;
    offline: boolean;
    offline_reconnect: OfflineReconnect | null;
    token: string;
    token_type: 'Bearer';
    channel: string;
  };
};

export type SessionResponse = ApiEnvelope & {
  data: {
    user_info: UserInfo | null;
    offline: boolean;
    offline_reconnect: OfflineReconnect | null;
  } | null;
};

export type TodayClass = {
  course_code?: string | null;
  course_name?: string | null;
  lecturer_name?: string | null;
  room?: string | null;
  schedule?: { day?: string | null; time?: string | null; start_time?: string | null; end_time?: string | null };
  active_now?: boolean;
  attendance?: {
    state?: string;
    actionable?: boolean;
    class_url?: string | null;
    meeting_id?: string | null;
    meeting_url?: string | null;
    payload?: { courseid?: string | null; modulesid?: string | null; pertemuan?: number | null } | null;
    status?: { already_marked?: boolean; label?: string | null; time?: string | null };
  };
};

export type DashboardResponse = ApiEnvelope & {
  data: {
    user_info?: UserInfo | null;
    classes_summary?: { date?: string; day?: string; today_classes?: TodayClass[] };
    pending_sources?: string[];
  };
};

export type PortalClass = {
  course_info?: { code?: string | null; name?: string | null };
  lecturer_info?: { name?: string | null };
  schedule?: { day?: string | null; start_time?: string | null; end_time?: string | null; formatted_time?: string | null };
  class_room_info?: { room?: string | null; class_name?: string | null };
  join_link?: { params?: { id?: string; dosen_id?: string; course_id?: string } };
};

export type ClassesResponse = ApiEnvelope & {
  user_info?: UserInfo | null;
  classes: PortalClass[];
};

export type NotificationUrgency = 'info' | 'warning' | 'critical';

export type PortalNotification = {
  id: number;
  type: string;
  dataset: string | null;
  title: string;
  body: string | null;
  url: string | null;
  urgency: NotificationUrgency;
  created_at: string | null;
  read_at: string | null;
  is_read: boolean;
};

export type NotificationsResponse = ApiEnvelope & {
  data: {
    items: PortalNotification[];
    unread_count: number;
    has_more: boolean;
    next_before: number | null;
  };
};

export type UnreadCountResponse = ApiEnvelope & {
  data: { unread_count: number };
};

export type MarkReadResponse = ApiEnvelope & {
  data: { updated: number; unread_count: number };
};

export type ActionItemSeverity = 'critical' | 'warning' | 'info';

export type ActionItem = {
  id: string;
  type: string;
  title: string;
  description?: string | null;
  due_at?: string | null;
  days_left?: number | null;
  severity: ActionItemSeverity | string;
  cta_label?: string | null;
  cta_url?: string | null;
  cta_external?: boolean;
};

export type ActionItemsResponse = ApiEnvelope & {
  data: { items: ActionItem[]; counts?: { total?: number; critical?: number; warning?: number; info?: number } };
};

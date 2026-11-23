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
    ai_provider?: AiProvider;
    ai_consent?: AiConsent;
    /** Ulasan harian cards waiting today; older servers send none. */
    review_due?: number;
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
  /** The source guide, written with its summary: none for a source read before guides or without the model. */
  summary?: string | null;
  topics?: string[];
  suggested_questions?: string[];
};

/** How a question is answered: directly, as a tutor (one step per turn, never solving the student's task), or in at most 5 points. */
export type NotebookAskMode = 'answer' | 'tutor' | 'ringkas';

export type NotebookDifficulty = 'campuran' | 'dasar' | 'sulit';

/** How many quiz questions or flashcards, and how hard (studio `options`). */
export type NotebookStudioOptions = { count?: number; difficulty?: NotebookDifficulty };

/** A quiz question as the studio returns it; `topic` is missing on quizzes made before it existed. */
export type NotebookQuizItem = { question?: string; options?: string[]; answer_index?: number; explanation?: string; topic?: string; citations?: number[] };

export type NotebookFlashcardItem = { front?: string; back?: string; citations?: number[] };

/** The last practice on a quiz or flashcards, as the server kept it (`context.practice`). */
export type NotebookPracticeResult = { score: number; total: number; missed: number[]; flagged?: number[]; choices?: (number | null)[]; at?: string };

/** What is left of today's AI requests. */
export type NotebookAiBudget = { limit: number; used: number; remaining: number };

/**
 * An excerpt an output cites as `[n]`. `position` (with `source_id`) opens it in its source
 * (GET notebooks/{id}/sources/{source}/chunks?around=); citations saved before it existed have neither `position`
 * nor `chunk_id`.
 */
export type NotebookCitation = {
  n: number;
  source_id?: number;
  source_title?: string;
  page?: number | null;
  position?: number;
  chunk_id?: number;
  excerpt?: string;
};

/** What an output was given (NotebookController CONTEXT_ITEM); every field is missing on older outputs. */
export type NotebookMessageContext = {
  source_ids?: number[];
  sources?: { id: number; title: string; meeting_number: number | null }[];
  total_sources?: number;
  excerpts?: number;
  chars?: number;
  partial?: boolean;
  focus?: string | null;
  /** Missing on answers from before modes existed. */
  mode?: NotebookAskMode | string | null;
  /** A quiz or flashcards carry their options. */
  options?: NotebookStudioOptions | null;
  /** The last practice on a quiz or flashcards (kept without AI). */
  practice?: NotebookPracticeResult | null;
  /** A quiz made from missed items names them. */
  practice_from?: { message_id: number; missed: number[] } | null;
  /** "Cek pemahamanku" feedback: what was checked, the question or brief, and the start of the student's answer. */
  feedback_mode?: NotebookFeedbackMode | string | null;
  feedback_prompt?: string | null;
  answer_excerpt?: string | null;
  /** A note copied from an answer ("Salin ke catatan") names it. */
  from_message_id?: number;
  /** A note made a source ("Jadikan sumber"): that text source. */
  note_source?: { id: number; title: string; at: string } | null;
  truncated?: boolean;
  provider?: string;
  model?: string;
  usage?: { input_tokens: number | null; output_tokens: number | null; thoughts_tokens: number | null };
};

/** A message as NotebookController MESSAGE_ITEM sends it. */
export type NotebookMessage = {
  id: number;
  role: 'user' | 'assistant' | string;
  kind: string;
  content: string;
  data?: unknown;
  /** The excerpts the output cites, by their number `n`. */
  citations?: NotebookCitation[];
  context?: NotebookMessageContext | null;
  pinned?: boolean;
  created_at?: string | null;
};

/** A passage of a source as the AI read it; `page` is null for sources without pages (DOCX, notes). */
export type NotebookChunk = { position: number; page: number | null; content: string };

/**
 * A window of a source's chunks (at most 40). `around` is the cited position (the server's match for an old
 * citation, or null when it found none); `has_before`/`has_after` say there is more outside the window.
 */
export type NotebookSourceChunksResponse = ApiEnvelope & {
  data: {
    source: NotebookSource;
    chunks: NotebookChunk[];
    around: number | null;
    has_before?: boolean;
    has_after?: boolean;
  };
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
    ai_provider?: AiProvider;
    ai_consent?: AiConsent;
    /** Missing on servers from before practice sets. */
    ai_budget?: NotebookAiBudget;
    /** The empty chat's starter questions (the ready sources' guide questions, else general ones); missing on older servers. */
    suggestions?: string[];
    /**
     * The sources ticked as the AI context, saved on the server (deleted ones dropped); null is every source.
     * Missing on older servers, which leave the choice to the device copy.
     */
    context_source_ids?: number[] | null;
  };
};

/** PATCH notebooks/{id}: the notebook and the saved context choice. */
export type NotebookUpdateResponse = ApiEnvelope & { data: { notebook: NotebookItem; context_source_ids?: number[] | null } };

/** POST notebooks/{id}/notes and PATCH of a note's content: the student's own note (`kind: note`). */
export type NotebookNoteResponse = ApiEnvelope & { data: { message: NotebookMessage } };

/** "Jadikan sumber": the new text source and the note that records it (`context.note_source`). */
export type NotebookNoteSourceResponse = ApiEnvelope & { data: { source: NotebookSource; message: NotebookMessage } };

/** One AI provider Notebook content goes to, and whether the server uses its free tier. */
export type AiProcessor = {
  label: string;
  free_tier: boolean;
};

/**
 * The student's consent to send Notebook content to the AI provider(s) the server uses; nothing is sent without it.
 * `provider` names them as students read them (e.g. "Google Gemini"); `free_tier` says whether one runs on its
 * free tier, under which what is sent may be used to improve the provider's products, and `processors` says which.
 * `consent_key` is what a consent given now is for, sent back with it; `past_processors` names providers that
 * received Notebook content before and no longer do. Older servers send neither.
 */
export type AiConsent = {
  granted: boolean;
  granted_at: string | null;
  withdrawn_at: string | null;
  provider: string;
  free_tier: boolean;
  consent_key?: string;
  processors?: AiProcessor[];
  past_processors?: string[];
};

/** The AI provider the server uses (`ai_provider` of the notebook payloads). */
export type AiProvider = {
  driver: string;
  label: string;
  free_tier: boolean;
  consent_key?: string;
  processors?: AiProcessor[];
};

export type AiConsentResponse = ApiEnvelope & { data: { consent: AiConsent } };

export type NotebookAskResponse = ApiEnvelope & { data: { question: NotebookMessage; answer: NotebookMessage } };
export type NotebookStudioResponse = ApiEnvelope & { data: { message: NotebookMessage; ai_budget?: NotebookAiBudget } };
/** What "Cek pemahamanku" checks (NotebookAssistant::FEEDBACK_MODES). */
export type NotebookFeedbackMode = 'practice_answer' | 'explain_concept' | 'outline_check';

export type NotebookFeedbackVerdict = 'tepat' | 'sebagian' | 'belum_tepat';

/** A point of feedback with the excerpt numbers it rests on. */
export type NotebookFeedbackPoint = { point: string; citations: number[] };

/** The `data` of a `feedback` message. There is no corrected or rewritten answer, by design. */
export type NotebookFeedback = {
  verdict: NotebookFeedbackVerdict;
  strengths: NotebookFeedbackPoint[];
  gaps: NotebookFeedbackPoint[];
  misconceptions: { claim: string; correction: string; citations: number[] }[];
  next_step: string;
  follow_up_question: string;
};

export type NotebookFeedbackResponse = ApiEnvelope & { data: { message: NotebookMessage; ai_budget?: NotebookAiBudget } };
export type NotebookCreateResponse = ApiEnvelope & { data: { notebook: NotebookItem; created?: boolean } };

/** How well the student remembered a review card (NotebookReviewScheduler::GRADES). */
export type NotebookReviewGrade = 'lagi' | 'sulit' | 'bisa' | 'mudah';

/** A card of Ulasan harian (NotebookReviewController CARD_ITEM). `next` is in minutes per grade. */
export type NotebookReviewCard = {
  id: number;
  notebook_id: number;
  notebook_title?: string | null;
  notebook_message_id?: number | null;
  front: string;
  back: string;
  citations?: NotebookCitation[];
  topic?: string | null;
  meeting_number?: number | null;
  due_at?: string | null;
  interval_days?: number;
  ease?: number;
  reps?: number;
  lapses?: number;
  last_reviewed_at?: string | null;
  suspended?: boolean;
  is_new?: boolean;
  next?: Partial<Record<NotebookReviewGrade, number>>;
};

export type NotebookReviewCounts = {
  due: number;
  reviews: number;
  new: number;
  new_today: number;
  new_limit: number;
  total: number;
  suspended: number;
};

export type NotebookReviewQueueResponse = ApiEnvelope & {
  data: { cards: NotebookReviewCard[]; counts: NotebookReviewCounts; next_due_at?: string | null };
};

export type NotebookReviewCardResponse = ApiEnvelope & { data: { card: NotebookReviewCard; counts: NotebookReviewCounts } };

export type NotebookReviewResumeResponse = ApiEnvelope & { data: { resumed: number; counts: NotebookReviewCounts } };

export type NotebookReviewImportResponse = ApiEnvelope & { data: { imported: number; skipped: number; counts: NotebookReviewCounts } };

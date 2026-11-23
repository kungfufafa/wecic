<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Notebook
    |--------------------------------------------------------------------------
    |
    | A notebook answers questions and writes study material from the sources
    | a student puts in it (archived course files, uploads, pasted text), with
    | citations to those sources. The provider is chosen under `ai`; its
    | key and model are configured under `services.{provider}`. Limits are
    | per student.
    |
    */

    'enabled' => (bool) env('NOTEBOOK_ENABLED', true),

    'ai' => [
        // Which provider answers questions and writes study material:
        // `gemini` (services.gemini), `anthropic` (services.anthropic) or
        // `openai`, any OpenAI-compatible endpoint (services.openai_compatible).
        'provider' => env('NOTEBOOK_AI_PROVIDER') ?: 'gemini',
        // Which provider reads scanned PDFs and images; unset uses
        // `provider`. `gemini` keeps transcription on Gemini while another
        // provider answers questions.
        'ingest_provider' => env('NOTEBOOK_AI_INGEST_PROVIDER') ?: null,
        // Seconds one web request may spend on every model attempt
        // together; keep it under the web server's own timeout (nginx:
        // 60 s). Unset falls back to services.gemini.request_budget.
        'request_budget' => is_numeric(env('NOTEBOOK_AI_REQUEST_BUDGET')) ? (int) env('NOTEBOOK_AI_REQUEST_BUDGET') : null,
        // Reading a PDF or image runs in a queued job, which may wait
        // longer. Unset falls back to services.gemini.ingest_budget.
        'ingest_budget' => is_numeric(env('NOTEBOOK_AI_INGEST_BUDGET')) ? (int) env('NOTEBOOK_AI_INGEST_BUDGET') : null,
    ],

    // Tokens a Gemini 2.5 model may spend thinking before it answers: 0
    // turns thinking off (where the model allows it), -1 lets the model
    // decide. Unset sends no thinkingConfig, so the model's default holds.
    // A positive budget is added to every output limit, since thinking
    // counts against it and would otherwise cut the answer short.
    'thinking_budget' => is_numeric(env('NOTEBOOK_THINKING_BUDGET')) ? (int) env('NOTEBOOK_THINKING_BUDGET') : null,

    // One notebook per class in the student's class list, made automatically
    // and filled meeting by meeting (note + course files of each meeting).
    'course_notebooks' => [
        'enabled' => (bool) env('NOTEBOOK_COURSE_NOTEBOOKS', true),
        // A meeting joins its course notebook whenever MyCIC reads it from the
        // portal (the first time its page is opened, and every re-sync).
        // Files that join on their own are read once the student opens the
        // notebook, never by a background sync.
        'auto_add_opened_meetings' => (bool) env('NOTEBOOK_AUTO_ADD_MEETINGS', true),
        // Meetings read from the portal in one "add every meeting" run.
        'max_meetings_per_sync' => (int) env('NOTEBOOK_MAX_MEETINGS_PER_SYNC', 20),
    ],

    'limits' => [
        // Questions and studio outputs per student per day; only answered
        // requests count.
        'daily_requests' => (int) env('NOTEBOOK_DAILY_REQUESTS', 60),
        // Sources read with the model (PDF/image transcription, source
        // summaries) per student per day. A course file another notebook
        // has read already is reused for free.
        'daily_source_reads' => (int) env('NOTEBOOK_DAILY_SOURCE_READS', 60),
        // Of those, reads kept for the files the student adds: course files
        // that joined a notebook on their own stop short of them and wait
        // for the next day (at most half of daily_source_reads).
        'reserved_source_reads' => (int) env('NOTEBOOK_RESERVED_SOURCE_READS', 10),
        // Readings queued at a time per notebook, so neither opening a
        // notebook nor adding many files at once floods the queue. Files
        // the student adds follow as readings finish; course files that
        // joined on their own follow while the notebook is open.
        'queued_readings' => (int) env('NOTEBOOK_QUEUED_READINGS', 4),
        'max_notebooks' => (int) env('NOTEBOOK_MAX_NOTEBOOKS', 50),
        'max_sources' => (int) env('NOTEBOOK_MAX_SOURCES', 30),
        // A course notebook holds a semester: a note plus the course files
        // of each of about 16 meetings, and the RPS.
        'max_course_sources' => (int) env('NOTEBOOK_MAX_COURSE_SOURCES', 80),
        // Kilobytes, for upload validation.
        'max_upload_kb' => (int) env('NOTEBOOK_MAX_UPLOAD_KB', 20480),
        'max_text_chars' => 200000,
        // Characters of source text sent with one question; larger notebooks
        // send the excerpts that match the question best.
        'context_chars' => (int) env('NOTEBOOK_CONTEXT_CHARS', 300000),
        'chunk_chars' => 1800,
        'history_messages' => 8,
    ],

    'uploads' => [
        'disk' => env('NOTEBOOK_UPLOAD_DISK', 'local'),
        'mimes' => ['pdf', 'docx', 'pptx', 'txt', 'md', 'png', 'jpg', 'jpeg'],
        // A PDF's own text layer is read locally first; only a PDF without
        // one (a scan) goes to the model. Off sends every PDF to a model
        // that reads PDFs, and reads the text layer only for one that does not.
        'pdf_text_layer' => (bool) env('NOTEBOOK_PDF_TEXT_LAYER', true),
        // Bytes of XML a DOCX/PPTX may unpack to; a larger one is refused
        // before it is decompressed.
        'max_unzipped_bytes' => (int) env('NOTEBOOK_MAX_UNZIPPED_BYTES', 50 * 1024 * 1024),
    ],

];

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Notebook
    |--------------------------------------------------------------------------
    |
    | A notebook answers questions and writes study material from the sources
    | a student puts in it (archived course files, uploads, pasted text), with
    | citations to those sources. The model is configured under
    | `services.gemini`. Limits are per student.
    |
    */

    'enabled' => (bool) env('NOTEBOOK_ENABLED', true),

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
        // Bytes of XML a DOCX/PPTX may unpack to; a larger one is refused
        // before it is decompressed.
        'max_unzipped_bytes' => (int) env('NOTEBOOK_MAX_UNZIPPED_BYTES', 50 * 1024 * 1024),
    ],

];

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

    // One notebook per class in the student's class list, made automatically
    // and filled meeting by meeting (note + course files of each meeting).
    'course_notebooks' => [
        'enabled' => (bool) env('NOTEBOOK_COURSE_NOTEBOOKS', true),
        // A meeting joins its course notebook whenever MyCIC reads it from the
        // portal (the first time its page is opened, and every re-sync).
        'auto_add_opened_meetings' => (bool) env('NOTEBOOK_AUTO_ADD_MEETINGS', true),
        // Meetings read from the portal in one "add every meeting" run.
        'max_meetings_per_sync' => (int) env('NOTEBOOK_MAX_MEETINGS_PER_SYNC', 20),
    ],

    'limits' => [
        // Questions and studio outputs per student per day.
        'daily_requests' => (int) env('NOTEBOOK_DAILY_REQUESTS', 60),
        'max_notebooks' => (int) env('NOTEBOOK_MAX_NOTEBOOKS', 50),
        'max_sources' => (int) env('NOTEBOOK_MAX_SOURCES', 30),
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
    ],

];

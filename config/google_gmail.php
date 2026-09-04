<?php

return [
    'api_base' => env('GOOGLE_GMAIL_API_BASE', 'https://gmail.googleapis.com/gmail/v1'),
    'max_search_results' => (int) env('GOOGLE_GMAIL_MAX_SEARCH', 15),
    'max_messages' => (int) env('GOOGLE_GMAIL_MAX_MESSAGES', 15),
    'max_thread_messages' => (int) env('GOOGLE_GMAIL_MAX_THREAD_MESSAGES', 10),
    'max_body_chars' => (int) env('GOOGLE_GMAIL_MAX_BODY', 12000),
    'max_total_thread_chars' => (int) env('GOOGLE_GMAIL_MAX_THREAD_CHARS', 20000),
    'max_snippet_chars' => (int) env('GOOGLE_GMAIL_MAX_SNIPPET', 240),
    'max_recipients' => (int) env('GOOGLE_GMAIL_MAX_RECIPIENTS', 20),
    'max_subject_chars' => (int) env('GOOGLE_GMAIL_MAX_SUBJECT', 200),
    'max_outbound_body_chars' => (int) env('GOOGLE_GMAIL_MAX_OUTBOUND_BODY', 8000),
    'max_labels' => (int) env('GOOGLE_GMAIL_MAX_LABELS', 50),
    'max_cc' => (int) env('GOOGLE_GMAIL_MAX_CC', 10),
    'body_preview_chars' => (int) env('GOOGLE_GMAIL_BODY_PREVIEW', 200),
    'timeout' => (int) env('GOOGLE_GMAIL_HTTP_TIMEOUT', 10),
    'connect_timeout' => (int) env('GOOGLE_GMAIL_HTTP_CONNECT_TIMEOUT', 5),
    'get_retries' => (int) env('GOOGLE_GMAIL_GET_RETRIES', 1),
];

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Chat attachments (M22.1)
    |--------------------------------------------------------------------------
    |
    | Single source of upload limits for Core and Workspace. Do not duplicate
    | these numbers in React or provider adapters. Frontend receives a public
    | snapshot via Inertia props.
    |
    */

    'disk' => env('CHAT_ATTACHMENTS_DISK', 'local'),

    'directory' => 'chat-attachments',

    'max_images_per_message' => (int) env('CHAT_ATTACHMENTS_MAX_IMAGES', 5),

    'max_file_size_mb' => (int) env('CHAT_ATTACHMENTS_MAX_FILE_MB', 10),

    'max_total_upload_mb' => (int) env('CHAT_ATTACHMENTS_MAX_TOTAL_MB', 25),

    'max_pixels' => (int) env('CHAT_ATTACHMENTS_MAX_PIXELS', 40_000_000),

    'allowed_mime_types' => [
        'image/png',
        'image/jpeg',
        'image/webp',
    ],

    'thumbnail' => [
        'max_width' => 320,
        'max_height' => 320,
        'quality' => 82,
    ],

    'retention_class' => 'ephemeral',

    'retention_hours' => (int) env('CHAT_ATTACHMENTS_RETENTION_HOURS', 24),

    'hard_retention_days' => (int) env('CHAT_ATTACHMENTS_HARD_RETENTION_DAYS', 7),

    'purge_batch' => (int) env('CHAT_ATTACHMENTS_PURGE_BATCH', 50),

    'summary_max_chars' => (int) env('CHAT_ATTACHMENTS_SUMMARY_CHARS', 1200),

    'summary_queue' => env('CHAT_ATTACHMENTS_SUMMARY_QUEUE', 'memory'),

    'summary_max_attempts' => (int) env('CHAT_ATTACHMENTS_SUMMARY_ATTEMPTS', 3),
];

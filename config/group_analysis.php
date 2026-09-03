<?php

return [
    'queue' => env('GROUP_ANALYSIS_QUEUE', 'analysis'),

    'max_messages_per_chunk' => (int) env('GROUP_ANALYSIS_MAX_MESSAGES_PER_CHUNK', 40),

    'max_chars_per_chunk' => (int) env('GROUP_ANALYSIS_MAX_CHARS_PER_CHUNK', 12000),

    'max_chunks_per_run' => (int) env('GROUP_ANALYSIS_MAX_CHUNKS', 8),

    'max_decisions' => (int) env('GROUP_ANALYSIS_MAX_DECISIONS', 20),

    'max_tasks' => (int) env('GROUP_ANALYSIS_MAX_TASKS', 20),

    'max_events' => (int) env('GROUP_ANALYSIS_MAX_EVENTS', 20),

    'max_range_days' => (int) env('GROUP_ANALYSIS_MAX_RANGE_DAYS', 31),

    'analysis_enabled' => (bool) env('GROUP_ANALYSIS_ENABLED', false),

    'daily_summary_enabled' => (bool) env('GROUP_ANALYSIS_DAILY_SUMMARY', false),
];

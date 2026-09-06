<?php

return [

    'queue' => env('WATCHERS_QUEUE', 'default'),

    'dispatch_limit' => (int) env('WATCHERS_DISPATCH_LIMIT', 40),

    'cadence' => [
        'gmail_seconds' => (int) env('WATCHERS_GMAIL_CADENCE', 480),
        'github_seconds' => (int) env('WATCHERS_GITHUB_CADENCE', 480),
        'calendar_seconds' => (int) env('WATCHERS_CALENDAR_CADENCE', 300),
        'internal_seconds' => (int) env('WATCHERS_INTERNAL_CADENCE', 300),
        'blocked_seconds' => (int) env('WATCHERS_BLOCKED_CADENCE', 21600),
    ],

    'limits' => [
        'max_active_external' => (int) env('WATCHERS_MAX_ACTIVE_EXTERNAL', 25),
        'max_active_internal' => (int) env('WATCHERS_MAX_ACTIVE_INTERNAL', 100),
        'max_triggers_per_day' => (int) env('WATCHERS_MAX_TRIGGERS_PER_DAY', 8),
        'max_notifications_per_day' => (int) env('WATCHERS_MAX_NOTIFICATIONS_PER_DAY', 12),
        'max_semantic_analyses_per_day' => (int) env('WATCHERS_MAX_ANALYSES_PER_DAY', 6),
        'max_observations' => (int) env('WATCHERS_MAX_OBSERVATIONS', 20),
        'auth_failure_threshold' => (int) env('WATCHERS_AUTH_FAILURE_THRESHOLD', 1),
        'permanent_failure_threshold' => (int) env('WATCHERS_PERMANENT_FAILURE_THRESHOLD', 5),
    ],

    'defaults' => [
        'cooldown_seconds' => (int) env('WATCHERS_COOLDOWN', 3600),
        'aggregation_window_seconds' => (int) env('WATCHERS_AGGREGATION_WINDOW', 300),
    ],

    'retention_days' => (int) env('WATCHERS_RETENTION_DAYS', 90),

    'max_name_chars' => 180,
    'max_summary_chars' => 400,
    'max_metadata_chars' => 1200,
];

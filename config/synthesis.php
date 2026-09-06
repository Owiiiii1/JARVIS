<?php

return [

    /*
    | Cross-source synthesis is a derived view over Knowledge, Tasks,
    | Reminders, Watchers, Projects, and conversation summaries.
    | Existing domains remain authoritative. No polling of Gmail/Calendar/GitHub.
    */

    'cache_ttl_seconds' => (int) env('SYNTHESIS_CACHE_TTL', 90),

    'inactivity_days' => (int) env('SYNTHESIS_INACTIVITY_DAYS', 7),

    'waiting_follow_up_days' => (int) env('SYNTHESIS_WAITING_FOLLOW_UP_DAYS', 3),

    'stale_after_hours' => (int) env('SYNTHESIS_STALE_AFTER_HOURS', 6),

    'deadline_risk_hours' => (int) env('SYNTHESIS_DEADLINE_RISK_HOURS', 48),

    'factpack' => [
        'max_projects' => (int) env('SYNTHESIS_MAX_PROJECTS', 5),
        'max_people' => (int) env('SYNTHESIS_MAX_PEOPLE', 10),
        'max_events' => (int) env('SYNTHESIS_MAX_EVENTS', 30),
        'max_tasks' => (int) env('SYNTHESIS_MAX_TASKS', 30),
        'max_reminders' => (int) env('SYNTHESIS_MAX_REMINDERS', 20),
        'max_watchers' => (int) env('SYNTHESIS_MAX_WATCHERS', 20),
        'max_waiting' => (int) env('SYNTHESIS_MAX_WAITING', 20),
        'max_commitments' => (int) env('SYNTHESIS_MAX_COMMITMENTS', 20),
        'max_changes' => (int) env('SYNTHESIS_MAX_CHANGES', 30),
        'max_attention' => (int) env('SYNTHESIS_MAX_ATTENTION', 12),
        'max_summaries' => (int) env('SYNTHESIS_MAX_SUMMARIES', 8),
    ],

    'context' => [
        'max_tokens' => (int) env('SYNTHESIS_CONTEXT_TOKENS', 220),
        'max_lines' => (int) env('SYNTHESIS_CONTEXT_LINES', 6),
    ],

    'narrative_max_tokens' => (int) env('SYNTHESIS_NARRATIVE_MAX_TOKENS', 400),

];

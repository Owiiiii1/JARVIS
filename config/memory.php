<?php

return [
    'queue' => env('MEMORY_QUEUE', 'memory'),

    'summary_message_threshold' => (int) env('MEMORY_SUMMARY_THRESHOLD', 20),

    'summary_initial_chunk' => (int) env('MEMORY_SUMMARY_CHUNK', 40),

    'analysis_context_messages' => (int) env('MEMORY_ANALYSIS_CONTEXT_MESSAGES', 8),

    'profile_memory_change_threshold' => (int) env('MEMORY_PROFILE_CHANGE_THRESHOLD', 5),

    'retrieval' => [
        'min_confidence' => (float) env('MEMORY_MIN_CONFIDENCE', 0.65),
        'max_memories' => (int) env('MEMORY_MAX_MEMORIES', 10),
        'fallback_memories' => (int) env('MEMORY_FALLBACK_MEMORIES', 5),
        'max_cross_chat_summaries' => (int) env('MEMORY_MAX_CROSS_CHAT_SUMMARIES', 5),
        'max_topics' => (int) env('MEMORY_MAX_TOPICS', 8),
        'candidate_limit' => (int) env('MEMORY_CANDIDATE_LIMIT', 50),
    ],

    'search' => [
        'max_snippets' => (int) env('MEMORY_SEARCH_MAX_SNIPPETS', 8),
        'max_snippet_chars' => (int) env('MEMORY_SEARCH_SNIPPET_CHARS', 400),
        'candidate_limit' => (int) env('MEMORY_SEARCH_CANDIDATE_LIMIT', 40),
    ],
];

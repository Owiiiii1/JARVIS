<?php

return [
    'max_groups' => (int) env('GROUP_SEARCH_MAX_GROUPS', 8),

    'max_knowledge_per_group' => (int) env('GROUP_SEARCH_MAX_KNOWLEDGE_PER_GROUP', 8),

    'max_raw_snippets_per_group' => (int) env('GROUP_SEARCH_MAX_RAW_PER_GROUP', 6),

    'max_total_raw_snippets' => (int) env('GROUP_SEARCH_MAX_TOTAL_RAW', 30),

    'max_source_snippets' => (int) env('GROUP_SEARCH_MAX_SOURCE_SNIPPETS', 3),

    'max_query_tokens' => (int) env('GROUP_SEARCH_MAX_QUERY_TOKENS', 12),

    'max_snippet_chars' => (int) env('GROUP_SEARCH_MAX_SNIPPET_CHARS', 220),

    'candidate_limit' => (int) env('GROUP_SEARCH_CANDIDATE_LIMIT', 80),

    'stale_after_new_messages' => (int) env('GROUP_SEARCH_STALE_AFTER_NEW_MESSAGES', 1),

    'queue_missing_analysis' => (bool) env('GROUP_SEARCH_QUEUE_MISSING_ANALYSIS', true),

    'include_archived_by_default' => (bool) env('GROUP_SEARCH_INCLUDE_ARCHIVED', false),

    'raw_unscoped_lookback_days' => (int) env('GROUP_SEARCH_RAW_LOOKBACK_DAYS', 31),

    'default_types' => ['summary', 'decision', 'task', 'event_fact'],
];

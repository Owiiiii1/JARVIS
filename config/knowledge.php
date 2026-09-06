<?php

return [

    'queue' => env('KNOWLEDGE_QUEUE', env('MEMORY_QUEUE', 'memory')),

    'extract_from_memory' => filter_var(env('KNOWLEDGE_EXTRACT_FROM_MEMORY', true), FILTER_VALIDATE_BOOL),

    'extract_from_summary' => filter_var(env('KNOWLEDGE_EXTRACT_FROM_SUMMARY', true), FILTER_VALIDATE_BOOL),

    'ingest_tool_results' => filter_var(env('KNOWLEDGE_INGEST_TOOL_RESULTS', true), FILTER_VALIDATE_BOOL),

    'max_source_chars' => (int) env('KNOWLEDGE_MAX_SOURCE_CHARS', 4000),

    'max_summary_chars' => (int) env('KNOWLEDGE_MAX_SUMMARY_CHARS', 400),

    'max_metadata_chars' => (int) env('KNOWLEDGE_MAX_METADATA_CHARS', 1200),

    'retrieval' => [
        'max_entities' => (int) env('KNOWLEDGE_CONTEXT_ENTITIES', 3),
        'max_relations_per_entity' => (int) env('KNOWLEDGE_CONTEXT_RELATIONS', 5),
        'max_events_per_entity' => (int) env('KNOWLEDGE_CONTEXT_EVENTS', 5),
        'min_confidence' => (float) env('KNOWLEDGE_CONTEXT_MIN_CONFIDENCE', 0.8),
        'search_limit' => (int) env('KNOWLEDGE_SEARCH_LIMIT', 8),
        'timeline_limit' => (int) env('KNOWLEDGE_TIMELINE_LIMIT', 8),
    ],

    'confidence' => [
        'high' => 0.8,
        'medium' => 0.5,
        'manual' => 1.0,
        'deterministic' => 0.95,
        'explicit_extraction' => 0.85,
        'inference' => 0.35,
    ],

];

<?php

return [

    'disk' => env('JARVIS_STORAGE_DISK', 'local'),

    'directory' => 'jarvis-storage',

    'queue' => env('JARVIS_STORAGE_QUEUE', 'default'),

    'max_file_size_mb' => (int) env('JARVIS_STORAGE_MAX_FILE_MB', 20),

    'max_files_per_upload' => (int) env('JARVIS_STORAGE_MAX_FILES', 8),

    'max_total_upload_mb' => (int) env('JARVIS_STORAGE_MAX_TOTAL_MB', 40),

    'max_extracted_chars_per_file' => (int) env('JARVIS_STORAGE_MAX_EXTRACTED_CHARS', 2_000_000),

    'chunk_chars' => (int) env('JARVIS_STORAGE_CHUNK_CHARS', 8000),

    'chunk_overlap_chars' => (int) env('JARVIS_STORAGE_CHUNK_OVERLAP', 400),

    'inline_turn_chars' => (int) env('JARVIS_STORAGE_INLINE_TURN_CHARS', 4000),

    'direct_preview_chars' => (int) env('JARVIS_STORAGE_PREVIEW_CHARS', 8000),

    'search_result_limit' => (int) env('JARVIS_STORAGE_SEARCH_LIMIT', 8),

    'max_chunks_per_tool_result' => (int) env('JARVIS_STORAGE_TOOL_CHUNKS', 4),

    'max_tool_chars' => (int) env('JARVIS_STORAGE_TOOL_CHARS', 6000),

    'max_excerpt_chars' => (int) env('JARVIS_STORAGE_EXCERPT_CHARS', 1200),

    'list_page_size' => (int) env('JARVIS_STORAGE_PAGE_SIZE', 30),

    'ingestion_batch' => (int) env('JARVIS_STORAGE_INGEST_BATCH', 20),

    'sync_process_max_bytes' => (int) env('JARVIS_STORAGE_SYNC_MAX_BYTES', 524288),

    'allowed_extensions' => [
        'txt', 'md', 'log', 'csv', 'tsv', 'json', 'xml', 'yaml', 'yml',
        'ini', 'conf', 'env', 'php', 'js', 'ts', 'jsx', 'tsx', 'py', 'java',
        'c', 'h', 'cpp', 'hpp', 'cs', 'go', 'rs', 'sql', 'sh', 'ps1',
        'html', 'htm', 'css', 'scss', 'less', 'vue', 'rb', 'kt', 'swift',
        'toml', 'cfg', 'properties',
    ],

    'allowed_mime_types' => [
        'text/plain',
        'text/markdown',
        'text/csv',
        'text/tab-separated-values',
        'text/html',
        'text/css',
        'text/xml',
        'text/x-log',
        'text/x-php',
        'text/x-python',
        'text/x-java-source',
        'text/x-c',
        'text/x-c++',
        'text/javascript',
        'application/javascript',
        'application/json',
        'application/xml',
        'application/x-yaml',
        'application/yaml',
        'application/x-sh',
        'application/x-httpd-php',
        'application/octet-stream',
    ],

];

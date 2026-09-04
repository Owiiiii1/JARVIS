<?php

return [

    'provider' => env('WEB_SEARCH_PROVIDER', 'tavily'),

    'timeout' => (int) env('WEB_SEARCH_TIMEOUT', 12),

    'connect_timeout' => (int) env('WEB_SEARCH_CONNECT_TIMEOUT', 5),

    'max_search_results' => (int) env('WEB_SEARCH_MAX_RESULTS', 8),

    'default_search_results' => (int) env('WEB_SEARCH_DEFAULT_RESULTS', 5),

    'max_searches_per_turn' => (int) env('WEB_SEARCH_MAX_SEARCHES', 2),

    'max_fetches_per_turn' => (int) env('WEB_SEARCH_MAX_FETCHES', 4),

    'max_page_chars' => (int) env('WEB_SEARCH_MAX_PAGE_CHARS', 8000),

    'max_total_web_chars' => (int) env('WEB_SEARCH_MAX_TOTAL_CHARS', 18000),

    'max_snippet_chars' => (int) env('WEB_SEARCH_SNIPPET_CHARS', 280),

    'max_redirects' => (int) env('WEB_SEARCH_MAX_REDIRECTS', 3),

    'max_response_bytes' => (int) env('WEB_SEARCH_MAX_RESPONSE_BYTES', 1_500_000),

    'user_agent' => env('WEB_SEARCH_USER_AGENT', 'JarvisWebResearch/1.0'),

    'allowed_schemes' => ['http', 'https'],

    'deny_private_networks' => true,

    'cache_ttl' => (int) env('WEB_SEARCH_CACHE_TTL', 0),

    'default_recency_days' => (int) env('WEB_SEARCH_RECENCY_DAYS', 0),

    'fetch_retries' => (int) env('WEB_SEARCH_FETCH_RETRIES', 1),

    'tavily' => [
        'api_key' => env('WEB_SEARCH_API_KEY', ''),
        'base_url' => env('WEB_SEARCH_TAVILY_URL', 'https://api.tavily.com'),
    ],

];

<?php

return [

    /*
    | Env / config is fallback only. Runtime tools read WebResearchSettingsService:
    | 1) persisted Admin `web_research_settings`
    | 2) these env/config values
    | 3) safe defaults, then min(value, hard ceiling)
    |
    | Secrets: Gemini reuses ai_provider_settings. Tavily Admin key is encrypted
    | on web_research_settings.tavily_api_key; WEB_SEARCH_API_KEY is fallback.
    */

    'enabled' => filter_var(env('WEB_SEARCH_ENABLED', true), FILTER_VALIDATE_BOOL),

    'provider' => env('WEB_SEARCH_PROVIDER', 'gemini_google'),

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

    'fetch_web_page_enabled' => filter_var(env('WEB_SEARCH_FETCH_ENABLED', true), FILTER_VALIDATE_BOOL),

    'floors' => [
        'max_search_results' => 1,
        'max_searches_per_turn' => 1,
        'max_fetches_per_turn' => 0,
        'max_page_chars' => 500,
        'max_total_web_chars' => 1000,
        'timeout_seconds' => 2,
    ],

    'ceilings' => [
        'max_search_results' => 20,
        'max_searches_per_turn' => 10,
        'max_fetches_per_turn' => 10,
        'max_page_chars' => 20000,
        'max_total_web_chars' => 40000,
        'timeout_seconds' => 60,
    ],

    'tavily' => [
        'api_key' => env('WEB_SEARCH_API_KEY', ''),
        'base_url' => env('WEB_SEARCH_TAVILY_URL', 'https://api.tavily.com'),
    ],

    'gemini_google' => [
        'model' => env('WEB_SEARCH_GEMINI_MODEL', ''),
    ],

];

<?php

return [
    'recent_executions_limit' => (int) env('INTEGRATIONS_RECENT_EXECUTIONS', 50),

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI'),
        'auth_url' => env('GOOGLE_AUTH_URL', 'https://accounts.google.com/o/oauth2/v2/auth'),
        'token_url' => env('GOOGLE_TOKEN_URL', 'https://oauth2.googleapis.com/token'),
        'userinfo_url' => env('GOOGLE_USERINFO_URL', 'https://openidconnect.googleapis.com/v1/userinfo'),
        'revoke_url' => env('GOOGLE_REVOKE_URL', 'https://oauth2.googleapis.com/revoke'),
        'scopes' => ['openid', 'email', 'profile'],
        'calendar_scopes' => [
            'https://www.googleapis.com/auth/calendar',
        ],
        'gmail_scopes' => [
            'https://www.googleapis.com/auth/gmail.readonly',
            'https://www.googleapis.com/auth/gmail.compose',
            'https://www.googleapis.com/auth/gmail.modify',
        ],
        'timeout' => (int) env('GOOGLE_HTTP_TIMEOUT', 10),
        'connect_timeout' => (int) env('GOOGLE_HTTP_CONNECT_TIMEOUT', 5),
        'refresh_skew_seconds' => (int) env('GOOGLE_REFRESH_SKEW', 120),
        'state_ttl_seconds' => (int) env('GOOGLE_OAUTH_STATE_TTL', 600),
    ],

    'github' => [
        'client_id' => env('GITHUB_CLIENT_ID'),
        'client_secret' => env('GITHUB_CLIENT_SECRET'),
        'redirect_uri' => env('GITHUB_REDIRECT_URI'),
        'auth_url' => env('GITHUB_AUTH_URL', 'https://github.com/login/oauth/authorize'),
        'token_url' => env('GITHUB_TOKEN_URL', 'https://github.com/login/oauth/access_token'),
        'api_base_url' => env('GITHUB_API_BASE', 'https://api.github.com'),
        'api_version' => env('GITHUB_API_VERSION', '2022-11-28'),
        'user_agent' => env('GITHUB_USER_AGENT', 'Jarvis-OwlSolutions'),
        'scopes' => ['repo', 'read:org'],
        'timeout' => (int) env('GITHUB_HTTP_TIMEOUT', 10),
        'connect_timeout' => (int) env('GITHUB_HTTP_CONNECT_TIMEOUT', 5),
        'refresh_skew_seconds' => (int) env('GITHUB_REFRESH_SKEW', 120),
        'state_ttl_seconds' => (int) env('GITHUB_OAUTH_STATE_TTL', 600),
    ],
];

<?php

return [

    /*
    | Instance-level Web Push VAPID keys. Generate once with
    | `php artisan jarvis:reminders:vapid` and persist in .env.
    | Do not mint a new keypair on every deploy.
    */

    'vapid' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'subject' => env('VAPID_SUBJECT', env('APP_URL', 'http://localhost')),
    ],

    'push' => [
        'payload_body_limit' => 120,
        'ttl_seconds' => 300,
    ],

];

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Async job retries
    |--------------------------------------------------------------------------
    |
    | Shared backoff for Memory / group analysis / attachment summary jobs.
    | Permanent failures (auth, safety, missing source) must not consume these
    | retries. Worker --timeout must stay below queue retry_after (300s).
    |
    */

    'job_tries' => 3,

    'job_backoff' => [30, 90, 180],

    /*
    |--------------------------------------------------------------------------
    | Stuck running recovery
    |--------------------------------------------------------------------------
    |
    | processing/pending domain rows older than this are eligible to mark
    | failed. Must exceed the longest job timeout plus a worker crash margin.
    | Never treat a fresh processing row as stuck.
    |
    */

    'stale_running_minutes' => 30,

    /*
    |--------------------------------------------------------------------------
    | Operational retry windows
    |--------------------------------------------------------------------------
    |
    | jarvis:*:retry-failed defaults. Commands are dry-run unless --execute.
    | Do not mass-retry production history without Owner approval.
    |
    */

    'retry_limit' => 20,

    'retry_hours' => 168,

    /*
    |--------------------------------------------------------------------------
    | Failed job retention
    |--------------------------------------------------------------------------
    |
    | Laravel `queue:prune-failed --hours=` can prune resolved queue rows
    | older than this. Domain analysis history is kept. Do not schedule prune
    | automatically; Owner runs it explicitly.
    |
    */

    'failed_jobs_retention_days' => 14,

];

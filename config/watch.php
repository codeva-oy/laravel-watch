<?php

$list = static fn (string $key, string $default): array => array_values(array_filter(array_map('trim', explode(',', (string) env($key, $default)))));

return [
    'enabled' => env('WATCH_ENABLED', true),
    'token' => env('WATCH_TOKEN'),
    // env() turns WATCH_TRANSPORT=null into a real null.
    'transport' => env('WATCH_TRANSPORT', 'agent') ?? 'null',
    'agent_address' => env('WATCH_AGENT_ADDRESS', '127.0.0.1:2408'),
    'agent_listen' => env('WATCH_AGENT_LISTEN', '127.0.0.1:2408'),
    'server_url' => env('WATCH_SERVER_URL'),
    'fallback_http' => env('WATCH_FALLBACK_HTTP', false),
    'http_queue' => env('WATCH_HTTP_QUEUE'),
    'health_secret' => env('WATCH_HEALTH_SECRET'),
    'log_level' => env('WATCH_LOG_LEVEL', 'warning'),

    'environment' => [
        // Read here as well as at runtime: some hosts only set these while
        // deploying, which is when the config gets cached.
        'deploy_id' => env('WATCH_DEPLOY', env('LARAVEL_CLOUD_DEPLOY_UUID', env('FORGE_DEPLOY_COMMIT', env('VAPOR_COMMIT_HASH')))),
        'server_name' => env('WATCH_SERVER_NAME'),
    ],

    'privacy' => [
        'capture_source_code' => env('WATCH_CAPTURE_SOURCE_CODE', true),
        'capture_payload' => env('WATCH_CAPTURE_PAYLOAD', false),
        'redact_fields' => $list('WATCH_REDACT_FIELDS', 'password,password_confirmation,_token,token,secret,card*'),
        'redact_headers' => $list('WATCH_REDACT_HEADERS', 'Authorization,Cookie,Set-Cookie,Proxy-Authorization,X-XSRF-TOKEN'),
    ],

    'sampling' => [
        'requests' => (float) env('WATCH_SAMPLE_REQUESTS', 1.0),
        'commands' => (float) env('WATCH_SAMPLE_COMMANDS', 1.0),
        'jobs' => (float) env('WATCH_SAMPLE_JOBS', 1.0),
        'tasks' => (float) env('WATCH_SAMPLE_SCHEDULED_TASKS', 1.0),
        'exceptions' => (float) env('WATCH_SAMPLE_EXCEPTIONS', 1.0),
    ],

    'ignore' => [
        'cache' => env('WATCH_IGNORE_CACHE', false),
        'mail' => env('WATCH_IGNORE_MAIL', false),
        'notifications' => env('WATCH_IGNORE_NOTIFICATIONS', false),
        'queries' => env('WATCH_IGNORE_QUERIES', false),
        'outgoing_requests' => env('WATCH_IGNORE_OUTGOING', false),
        'logs' => env('WATCH_IGNORE_LOGS', false),
    ],

    'health' => [
        'queues' => $list('WATCH_HEALTH_QUEUES', 'default'),
        'queue_size_warning' => (int) env('WATCH_HEALTH_QUEUE_WARNING', 1_000),
        'queue_size_failed' => (int) env('WATCH_HEALTH_QUEUE_FAILED', 10_000),
        'failed_jobs_warning' => (int) env('WATCH_HEALTH_FAILED_JOBS_WARNING', 1),
        'failed_jobs_failed' => (int) env('WATCH_HEALTH_FAILED_JOBS_FAILED', 100),
        'disk_percent_warning' => (int) env('WATCH_HEALTH_DISK_WARNING', 80),
        'disk_percent_failed' => (int) env('WATCH_HEALTH_DISK_FAILED', 90),
        'scheduler_minutes' => (int) env('WATCH_HEALTH_SCHEDULER_MINUTES', 2),
    ],

    'schedule' => [
        'grace_minutes' => (int) env('WATCH_SCHEDULE_GRACE', 5),
    ],

    'ingest' => [
        'buffer_size' => (int) env('WATCH_INGEST_BUFFER', 500),
    ],
];

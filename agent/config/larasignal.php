<?php

return [

    /*
    |--------------------------------------------------------------------------
    | LaraSignal Telemetry Enable Flag
    |--------------------------------------------------------------------------
    |
    | Enable or disable telemetry recording across your application.
    |
    */
    'enabled' => env('LARASIGNAL_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | LaraSignal Ingestion API Key
    |--------------------------------------------------------------------------
    |
    | The project API key generated from your LaraSignal dashboard.
    |
    */
    'key' => env('LARASIGNAL_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Ingestion Endpoint URL
    |--------------------------------------------------------------------------
    |
    | Target URL where telemetry batches are dispatched.
    |
    */
    'ingest_url' => env('LARASIGNAL_INGEST_URL', 'https://larasignal.com/api/v1/ingest/batches'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment & Release Version
    |--------------------------------------------------------------------------
    |
    | Identifies the environment (production, staging, etc.) and deployment
    | release version attached to telemetry traces.
    |
    */
    'environment' => env('LARASIGNAL_ENVIRONMENT', env('APP_ENV', 'production')),
    'release' => env('LARASIGNAL_RELEASE'),

    /*
    |--------------------------------------------------------------------------
    | Telemetry Sampling Rate
    |--------------------------------------------------------------------------
    |
    | Float between 0.0 (0%) and 1.0 (100%) determining the percentage of
    | request traces to record.
    |
    */
    'sample_rate' => (float) env('LARASIGNAL_SAMPLE_RATE', 1),

    /*
    |--------------------------------------------------------------------------
    | Asynchronous Delivery & Background Dispatching
    |--------------------------------------------------------------------------
    |
    | LaraSignal minimizes latency impact on user HTTP responses by allowing
    | you to choose how telemetry batches are delivered:
    |
    | 1. End of Request/Job Lifecycle (default, 'async' => false):
    |    Telemetry is buffered in memory and flushed asynchronously at the
    |    end of the HTTP request or queue job lifecycle after the response
    |    has been delivered to the user.
    |
    | 2. Background Batch Dispatcher ('async' => true):
    |    Telemetry is written directly to local disk spooling and processed
    |    by a dedicated background worker (`php artisan larasignal:run`),
    |    completely isolating user HTTP responses from network latency.
    |
    */
    'async' => (bool) env('LARASIGNAL_ASYNC', false),

    /*
    |--------------------------------------------------------------------------
    | Telemetry Spool Path
    |--------------------------------------------------------------------------
    |
    | Directory path used for offline buffer spooling and background batch
    | worker processing.
    |
    */
    'spool_path' => env('LARASIGNAL_SPOOL_PATH', storage_path('app/larasignal/spool')),

    /*
    |--------------------------------------------------------------------------
    | Connection & Batch Timeouts
    |--------------------------------------------------------------------------
    |
    | Network timeouts (in seconds) when dispatching telemetry to LaraSignal.
    |
    */
    'connect_timeout' => (float) env('LARASIGNAL_CONNECT_TIMEOUT', 1),
    'timeout' => (float) env('LARASIGNAL_TIMEOUT', 3),

    /*
    |--------------------------------------------------------------------------
    | Batching & Buffer Thresholds
    |--------------------------------------------------------------------------
    |
    | Controls how many telemetry events trigger an automatic batch dispatch
    | and maximum buffer size per request/job.
    |
    */
    'batch_size' => (int) env('LARASIGNAL_BATCH_SIZE', 100),
    'max_buffer' => (int) env('LARASIGNAL_MAX_BUFFER', 1000),
    'max_exception_message_length' => (int) env('LARASIGNAL_MAX_EXCEPTION_MESSAGE_LENGTH', 65536),

    /*
    |--------------------------------------------------------------------------
    | Recording Filters & Exclusions
    |--------------------------------------------------------------------------
    |
    | Configure specific routes, commands, jobs, logs, and exceptions to ignore.
    |
    */
    'ignored_routes' => ['larasignal/*', 'up', 'health'],
    'record_requests' => env('LARASIGNAL_RECORD_REQUESTS', true),
    'record_jobs' => env('LARASIGNAL_RECORD_JOBS', true),
    'record_commands' => env('LARASIGNAL_RECORD_COMMANDS', true),
    'record_scheduled_tasks' => env('LARASIGNAL_RECORD_SCHEDULED_TASKS', true),
    'record_exceptions' => env('LARASIGNAL_RECORD_EXCEPTIONS', true),
    'record_queries' => env('LARASIGNAL_RECORD_QUERIES', true),
    'record_notifications' => env('LARASIGNAL_RECORD_NOTIFICATIONS', true),
    'record_mail' => env('LARASIGNAL_RECORD_MAIL', true),
    'record_cache' => env('LARASIGNAL_RECORD_CACHE', true),
    'record_outgoing_requests' => env('LARASIGNAL_RECORD_OUTGOING_REQUESTS', true),
    'record_custom_events' => env('LARASIGNAL_RECORD_CUSTOM_EVENTS', true),
    'record_broadcasts' => env('LARASIGNAL_RECORD_BROADCASTS', true),
    'record_spans' => env('LARASIGNAL_RECORD_SPANS', true),
    'record_deployments' => env('LARASIGNAL_RECORD_DEPLOYMENTS', true),
    'record_authentication' => env('LARASIGNAL_RECORD_AUTHENTICATION', true),
    'record_security' => env('LARASIGNAL_RECORD_SECURITY', true),
    'record_queue_health' => env('LARASIGNAL_RECORD_QUEUE_HEALTH', true),
    'record_transactions' => env('LARASIGNAL_RECORD_TRANSACTIONS', true),
    'record_storage' => env('LARASIGNAL_RECORD_STORAGE', true),
    'record_runtime' => env('LARASIGNAL_RECORD_RUNTIME', true),
    'record_logs' => env('LARASIGNAL_RECORD_LOGS', true),
    'record_user' => env('LARASIGNAL_RECORD_USER', true),
    'slow_query_threshold_ms' => (int) env('LARASIGNAL_SLOW_QUERY_THRESHOLD_MS', 0),
    'slow_request_threshold_ms' => (int) env('LARASIGNAL_SLOW_REQUEST_THRESHOLD_MS', 0),
    'ignored_exceptions' => [],
    'ignored_commands' => ['larasignal:*', 'schedule:run', 'schedule:finish'],
    'ignored_jobs' => [],
    'allowed_request_fields' => [],
];

# LaraSignal Agent

Privacy-first Laravel telemetry agent for LaraSignal.

```bash
composer require larasignal/agent
```

### Interactive Installation
Run the installer command to configure settings interactively:
```bash
php artisan larasignal:install
```

### One-Line Non-Interactive Installation
You can pass configuration options directly in a single non-interactive command (ideal for automated setup scripts or CI/CD):
```bash
php artisan larasignal:install --key=ls_live_a1b2c3... --sample-rate=1.0 --record-queries=true --no-interaction
```

### Options Supported:
- `--key=`: Set the project API key (`LARASIGNAL_KEY`). Updates `.env` even if skipped.
- `--sample-rate=`: Telemetry sampling rate (`LARASIGNAL_SAMPLE_RATE`, e.g. `0.5` for 50%).
- `--record-queries=`: Enable/disable database query collection (`LARASIGNAL_RECORD_QUERIES=true|false`).
- `--ingest-url=`: Custom ingestion endpoint (`LARASIGNAL_INGEST_URL`). Default: `https://larasignal.com/api/v1/ingest/batches`.

---

### Delivery Modes & Minimizing Response Latency

LaraSignal is designed to minimize latency impact on user HTTP responses. You can choose between two delivery strategies:

1. **End-of-Lifecycle Asynchronous Flushing (Default, `LARASIGNAL_ASYNC=false`)**:
   Telemetry spans are buffered in memory and flushed asynchronously at the end of the HTTP request or queue job lifecycle (`app()->terminating()`) after the HTTP response has already been sent to the browser.

2. **Background Batch Dispatcher (`LARASIGNAL_ASYNC=true` + `php artisan larasignal:run`)**:
   Telemetry payloads are written directly to local disk spooling (`storage/app/larasignal/spool`) in 0ms and processed by a background worker process, completely isolating user HTTP responses from network ingestion latency.

Run the background worker daemon:
```bash
php artisan larasignal:run --sleep=3
```

---

### Verification & CLI Tools
- `php artisan larasignal:status`: Inspect current configuration and API key health.
- `php artisan larasignal:test`: Dispatch a test telemetry event to verify ingestion.
- `php artisan larasignal:deployment v1.2.0`: Record a deployment release event in LaraSignal.
- `php artisan larasignal:flush`: Re-send offline spooled telemetry batches.
- `php artisan larasignal:run`: Start the background telemetry worker process.
- `php artisan larasignal:help`: Display CLI help summary and environment variable reference.

---

### Features & Programmatic API

#### 1. Custom Events & Code Execution Measurement
```php
use LaraSignal\Agent\Facades\LaraSignal;

// Measure execution time of a code block
$result = LaraSignal::measure('stripe-payment', function () {
    return Stripe::charges()->create([...]);
});

// Record custom domain events
LaraSignal::event('OrderCompleted', ['order_id' => 109, 'amount' => 2500]);
```

#### 2. Global Context & Tagging
```php
// Attach contextual metadata to all subsequent events in the request/job
LaraSignal::context(['tenant_id' => $tenant->id, 'plan' => 'pro']);
LaraSignal::tag('checkout', 'high-value');

// Scope temporary context
LaraSignal::withContext(['batch' => 4], function () {
    // Events inside here inherit batch = 4
});
```

#### 3. User Identification
Authenticated users (`Auth::user()`) are automatically captured (sanitized user ID & email). You can also explicitly associate a user:
```php
LaraSignal::user($user);
```

#### 4. Automatic Log Ingestion
Laravel logs (`Log::info()`, `Log::warning()`, `Log::error()`) are automatically captured as telemetry log spans with log level and context metadata (configurable via `LARASIGNAL_RECORD_LOGS=true`).

#### 5. Storage, Runtime & Provider Metrics
Measure filesystem operations without transmitting object paths:
```php
$document = LaraSignal::storage('read', 's3', $path, fn () => Storage::disk('s3')->get($path), [
    'size_bytes' => Storage::disk('s3')->size($path),
]);
```

Report application or worker health gauges and provider-supplied WebSocket totals:
```php
LaraSignal::runtime('Application runtime', ['uptime_seconds' => 3600]);
LaraSignal::heartbeat('scheduler', 60);
LaraSignal::broadcastMetric('pusher', connections: 125, messages: 4800, attributes: ['cluster' => 'ap1']);
```

Schedule `php artisan larasignal:heartbeat scheduler --every=60` at the same interval to surface healthy and missed scheduler heartbeats in Runtime health.

Authentication, authorization denials, throttled requests, queue health, database transactions, cache outcomes, HTTP connection failures, deployments, spans, and Laravel worker lifecycle events are captured automatically.

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

### Verification & CLI Tools
- `php artisan larasignal:status`: Inspect current configuration and API key health.
- `php artisan larasignal:test`: Dispatch a test telemetry event to verify ingestion.
- `php artisan larasignal:deployment v1.2.0`: Record a deployment release event in LaraSignal.
- `php artisan larasignal:flush`: Re-send offline spooled telemetry batches.

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



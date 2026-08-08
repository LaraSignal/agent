---
name: larasignal-development
description: Use LaraSignal telemetry, performance span measurement, custom domain event tracking, global context scoping, user identification, and exception recording in Laravel applications. Trigger when modifying controllers, jobs, API integrations, payment processing, or heavy business logic requiring performance monitoring or telemetry tracing.
---

# LaraSignal Telemetry & Programmatic API Guidelines

LaraSignal is the privacy-first telemetry and APM agent for Laravel applications. Use the `LaraSignal` facade (`LaraSignal\Agent\Facades\LaraSignal`) to record performance spans, measure execution time, capture custom events, and attach contextual metadata.

## When & How to Use the Programmatic API

### 1. Code Block Performance Measurement (`LaraSignal::measure`)
Wrap external API calls (Stripe, OpenAI, Twilio, AWS S3), complex database transactions, PDF generation, or heavy business logic with `LaraSignal::measure()`:

```php
use LaraSignal\Agent\Facades\LaraSignal;

// Measures execution duration and status automatically
$payment = LaraSignal::measure('stripe.charge_customer', function () use ($amount) {
    return Stripe::charges()->create([
        'amount' => $amount,
        'currency' => 'usd',
    ]);
});
```

### 2. Custom Domain Events (`LaraSignal::event`)
Record significant business lifecycle events (e.g. order placed, user upgraded, subscription canceled, report generated):

```php
use LaraSignal\Agent\Facades\LaraSignal;

LaraSignal::event('OrderPlaced', [
    'order_id' => $order->id,
    'total_amount' => $order->total,
    'items_count' => $order->items->count(),
]);
```

### 3. Global Context & Scoped Metadata (`LaraSignal::context` / `withContext`)
Attach tenant IDs, workspace metadata, or batch IDs so all subsequent telemetry spans in the request/job automatically inherit this context:

```php
use LaraSignal\Agent\Facades\LaraSignal;

// Global context for current request/job
LaraSignal::context(['tenant_id' => $tenant->id, 'plan' => $tenant->plan]);
LaraSignal::tag('checkout', 'high-value');

// Temporarily scoped context
LaraSignal::withContext(['batch_id' => $batchId], function () {
    // Spans inside here inherit batch_id
});
```

### 4. User Identification (`LaraSignal::user`)
Authenticated users (`Auth::user()`) are captured automatically. For background jobs or custom authentication contexts, explicitly bind the user:

```php
use LaraSignal\Agent\Facades\LaraSignal;

LaraSignal::user($user);
```

### 5. Manual Exception Capture (`LaraSignal::exception`)
Report caught exceptions with custom contextual metadata:

```php
use LaraSignal\Agent\Facades\LaraSignal;

try {
    // ...
} catch (\Throwable $e) {
    LaraSignal::exception($e, ['checkout_step' => 'payment_intent']);
    throw $e;
}
```

## Best Practices
- Always use `LaraSignal::measure()` for 3rd-party network calls so slow downstream APIs are visible in performance traces.
- Use `LaraSignal::context()` early in request middleware or job handlers to tag tenant/account data.
- Sensitive fields (passwords, tokens, secrets) are redacted automatically by LaraSignal before transmission.

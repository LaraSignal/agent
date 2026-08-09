<?php

namespace LaraSignal\Agent;

use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use LaraSignal\Agent\Support\Redactor;
use Throwable;

final class Recorder
{
    /** @var array<int, array<string, mixed>> */
    private array $events = [];

    /** @var array<int, Closure> */
    private array $filters = [];

    /** @var array<string, mixed> */
    private array $context = [];

    /** @var array<int, string> */
    private array $tags = [];

    /** @var array<string, mixed>|null */
    private ?array $user = null;

    private ?string $traceId = null;

    private bool $recording = false;

    public function __construct(private readonly Client $client, private readonly Redactor $redactor) {}

    public function filter(Closure $callback): self
    {
        $this->filters[] = $callback;

        return $this;
    }

    /** @param array<string, mixed> $context */
    public function context(array $context): self
    {
        $this->context = array_merge($this->context, $context);

        return $this;
    }

    /** @param array<string, mixed> $context */
    public function withContext(array $context, Closure $callback): mixed
    {
        $previous = $this->context;
        $this->context = array_merge($this->context, $context);

        try {
            return $callback();
        } finally {
            $this->context = $previous;
        }
    }

    public function tag(string ...$tags): self
    {
        foreach ($tags as $tag) {
            if (! in_array($tag, $this->tags, true)) {
                $this->tags[] = $tag;
            }
        }

        return $this;
    }

    public function user(mixed $user): self
    {
        if ($user === null) {
            $this->user = null;

            return $this;
        }

        if (is_array($user)) {
            $this->user = $user;

            return $this;
        }

        if (is_object($user)) {
            $this->user = array_filter([
                'id' => method_exists($user, 'getKey') ? $user->getKey() : ($user->id ?? null),
                'email' => $user->email ?? null,
                'name' => $user->name ?? null,
            ]);
        }

        return $this;
    }

    /** @param array<string, mixed> $attributes */
    public function event(string $name, array $attributes = []): void
    {
        $this->record('event', $name, $attributes, status: 'completed');
    }

    public function measure(string $name, Closure $callback): mixed
    {
        $started = hrtime(true);
        $status = 'completed';

        try {
            return $callback();
        } catch (Throwable $e) {
            $status = 'failed';
            throw $e;
        } finally {
            $durationUs = (int) intdiv(hrtime(true) - $started, 1000);
            $this->record('span', $name, [], $durationUs, $status);
        }
    }

    public function startTrace(?string $traceId = null): string
    {
        return $this->traceId = $traceId ?: Str::uuid()->toString();
    }

    /** @param array<string, mixed> $attributes */
    public function record(string $type, string $name, array $attributes = [], ?int $durationUs = null, ?string $status = null, bool $force = false, ?string $severity = null): void
    {
        if ($this->recording || ! config('larasignal.enabled') || (! $force && ! $this->sampled())) {
            return;
        }

        $this->recording = true;

        try {
            if ($type === 'request') {
                $thresholdMs = (int) config('larasignal.slow_request_threshold_ms', 0);
                if ($thresholdMs > 0 && $durationUs !== null && ($durationUs / 1000) < $thresholdMs) {
                    return;
                }
            }

            foreach ($this->filters as $filter) {
                if ($filter($type, $name, $attributes, $durationUs, $status) === false) {
                    return;
                }
            }

            if (count($this->events) >= config('larasignal.max_buffer', 1000)) {
                array_shift($this->events);
            }

            $mergedAttributes = array_merge($attributes, [
                '_context' => $this->context,
                '_tags' => $this->tags,
                '_user' => $this->resolveUser(),
            ]);

            $this->events[] = [
                'id' => Str::uuid()->toString(),
                'type' => $type,
                'name' => mb_substr($name, 0, 255),
                'occurred_at' => now()->toIso8601String(),
                'trace_id' => $this->traceId ?: $this->startTrace(),
                'span_id' => Str::lower(Str::random(16)),
                'duration_us' => $durationUs,
                'status' => $status,
                'severity' => $severity,
                'release' => config('larasignal.release'),
                'attributes' => $this->redactor->redact($mergedAttributes),
            ];

            if (count($this->events) >= config('larasignal.batch_size', 100)) {
                $this->flush();
            }
        } finally {
            $this->recording = false;
        }
    }

    /** @param array<string, mixed> $context */
    public function exception(Throwable $exception, array $context = []): void
    {
        foreach (config('larasignal.ignored_exceptions', []) as $ignoredClass) {
            if ($exception instanceof $ignoredClass) {
                return;
            }
        }

        $this->record('exception', $exception->getMessage(), [
            'exception_class' => $exception::class,
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'context' => $context,
        ], status: 'failed', force: true);
    }

    public function flush(): void
    {
        if ($this->events === []) {
            return;
        }

        $events = $this->events;
        $this->events = [];
        $this->client->send($events);
    }

    public function discardPendingEvents(): void
    {
        $this->events = [];
    }

    /** @return array<string, mixed>|null */
    private function resolveUser(): ?array
    {
        if ($this->user !== null) {
            return $this->user;
        }

        if (! config('larasignal.record_user', true)) {
            return null;
        }

        try {
            if (Auth::check() && ($user = Auth::user())) {
                return array_filter([
                    'id' => $user->getKey(),
                    'email' => $user->email ?? null,
                ]);
            }
        } catch (Throwable) {
            // Ignore auth lookup errors
        }

        return null;
    }

    private function sampled(): bool
    {
        $rate = max(0, min(1, (float) config('larasignal.sample_rate', 1)));
        $trace = $this->traceId ?: $this->startTrace();

        return (hexdec(substr(hash('sha256', $trace), 0, 8)) / 0xFFFFFFFF) < $rate;
    }
}

<?php

namespace LaraSignal\Agent;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

final class Client
{
    public static bool $sending = false;

    /** @param array<int, array<string, mixed>> $events */
    public function send(array $events): void
    {
        if (self::$sending || blank(config('larasignal.key')) || blank(config('larasignal.ingest_url'))) {
            return;
        }

        self::$sending = true;

        $payload = json_encode([
            'schema_version' => '1.0',
            'batch_id' => Str::uuid()->toString(),
            'sent_at' => now()->toIso8601String(),
            'agent_version' => '1.0.1',
            'environment' => config('larasignal.environment'),
            'release' => config('larasignal.release'),
            'events' => $events,
        ], JSON_THROW_ON_ERROR);

        if (config('larasignal.async', false)) {
            $this->spool($payload);
            self::$sending = false;

            return;
        }

        try {
            Http::withToken(config('larasignal.key'))
                ->withBody(gzencode($payload, 6), 'application/json')
                ->withHeaders(['Content-Encoding' => 'gzip'])
                ->connectTimeout(config('larasignal.connect_timeout', 1))
                ->timeout(config('larasignal.timeout', 3))
                ->retry([100, 300], throw: false)
                ->post(config('larasignal.ingest_url'));
        } catch (Throwable) {
            $this->spool($payload);
        } finally {
            self::$sending = false;
        }
    }

    private function spool(string $payload): void
    {
        $path = config('larasignal.spool_path') ?: storage_path('app/larasignal/spool');

        if (! is_dir($path)) {
            @mkdir($path, 0755, true);
        }

        if (! is_dir($path) || ! is_writable($path)) {
            return;
        }

        @file_put_contents($path.'/'.Str::uuid().'.json', $payload, LOCK_EX);
    }
}

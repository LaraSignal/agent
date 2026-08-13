<?php

namespace LaraSignal\Agent\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use LaraSignal\Agent\Client;
use Throwable;

final class FlushSpoolCommand extends Command
{
    protected $signature = 'larasignal:flush';

    protected $description = 'Flush spooled LaraSignal telemetry batches to the ingestion endpoint';

    public function handle(): int
    {
        $path = config('larasignal.spool_path');

        if (! $path || ! is_dir($path)) {
            $this->info('No spool directory configured or found.');

            return self::SUCCESS;
        }

        $files = glob($path.'/*.json') ?: [];

        if ($files === []) {
            $this->info('No spooled telemetry batches found.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Flushing %d spooled telemetry batch(es)...', count($files)));
        $flushed = 0;

        foreach ($files as $file) {
            $content = file_get_contents($file);

            if (! $content) {
                @unlink($file);

                continue;
            }

            try {
                Client::$sending = true;

                $response = Http::withToken(config('larasignal.key'))
                    ->withBody(gzencode($content, 6), 'application/json')
                    ->withHeaders(['Content-Encoding' => 'gzip'])
                    ->connectTimeout(config('larasignal.connect_timeout', 2))
                    ->timeout(config('larasignal.timeout', 5))
                    ->post(config('larasignal.ingest_url'));

                if ($response->successful()) {
                    @unlink($file);
                    $flushed++;
                } else {
                    $this->warn(sprintf('Failed to send %s: HTTP %d', basename($file), $response->status()));
                }
            } catch (Throwable $e) {
                $this->error(sprintf('Error flushing %s: %s', basename($file), $e->getMessage()));
                break;
            } finally {
                Client::$sending = false;
            }
        }

        $this->info(sprintf('Successfully flushed %d batch(es).', $flushed));

        return self::SUCCESS;
    }
}

<?php

namespace LaraSignal\Agent\Console;

use Illuminate\Console\Command;

final class HelpCommand extends Command
{
    protected $signature = 'larasignal:help';

    protected $description = 'Display help information and available commands for LaraSignal';

    public function handle(): int
    {
        $this->components->info('LaraSignal Telemetry Agent - Help & Usage');
        $this->line('');
        $this->line('<fg=gray>LaraSignal is an application performance, exception, and telemetry agent for Laravel applications.</>');
        $this->line('');

        $this->components->twoColumnDetail('<fg=cyan;options=bold>Available Commands</>', '');
        $this->components->twoColumnDetail('larasignal:install', 'Install agent env settings & AI coding skill');
        $this->components->twoColumnDetail('larasignal:status', 'Show current agent configuration & connectivity status');
        $this->components->twoColumnDetail('larasignal:test', 'Send a safe test telemetry event to verify setup');
        $this->components->twoColumnDetail('larasignal:deployment [release]', 'Record a deployment release event');
        $this->components->twoColumnDetail('larasignal:flush', 'Flush spooled telemetry batches from disk');
        $this->components->twoColumnDetail('larasignal:run [--sleep=3] [--once]', 'Run background telemetry worker process');
        $this->components->twoColumnDetail('larasignal:help', 'Display this help summary');

        $this->line('');
        $this->components->twoColumnDetail('<fg=cyan;options=bold>Environment Variables (.env)</>', '');
        $this->components->twoColumnDetail('LARASIGNAL_KEY', 'API key from your LaraSignal dashboard');
        $this->components->twoColumnDetail('LARASIGNAL_SAMPLE_RATE', 'Sampling rate between 0.0 and 1.0 (default: 1)');
        $this->components->twoColumnDetail('LARASIGNAL_RECORD_QUERIES', 'Record database query telemetry (true/false)');
        $this->components->twoColumnDetail('LARASIGNAL_ASYNC', 'Enable zero-latency asynchronous disk spooling (true/false)');
        $this->components->twoColumnDetail('LARASIGNAL_INGEST_URL', 'Ingestion endpoint URL');

        return self::SUCCESS;
    }
}

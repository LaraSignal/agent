<?php

namespace LaraSignal\Agent\Console;

use Illuminate\Console\Command;
use Throwable;

final class RunCommand extends Command
{
    protected $signature = 'larasignal:run
        {--sleep=3 : The number of seconds to sleep between spool checks}
        {--once : Run the spool flusher once and exit}';

    protected $description = 'Run the LaraSignal background telemetry worker process';

    private bool $shouldQuit = false;

    public function handle(): int
    {
        $this->components->info('Starting LaraSignal background worker...');

        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, fn () => $this->shouldQuit = true);
            pcntl_signal(SIGTERM, fn () => $this->shouldQuit = true);
            pcntl_signal(SIGQUIT, fn () => $this->shouldQuit = true);
        }

        $sleep = max(1, (int) $this->option('sleep'));
        $once = (bool) $this->option('once');

        if ($once) {
            $this->call('larasignal:flush');

            return self::SUCCESS;
        }

        $this->components->info(sprintf('LaraSignal worker running (polling every %d second(s)). Press Ctrl+C to stop.', $sleep));

        while (! $this->shouldQuit) {
            try {
                $this->callSilent('larasignal:flush');
            } catch (Throwable $e) {
                $this->components->error('Error during flush: '.$e->getMessage());
            }

            $this->sleepWithSignalCheck($sleep);
        }

        $this->components->info('LaraSignal background worker stopped gracefully.');

        return self::SUCCESS;
    }

    private function sleepWithSignalCheck(int $seconds): void
    {
        for ($i = 0; $i < $seconds; $i++) {
            if ($this->shouldQuit) {
                break;
            }
            sleep(1);
        }
    }
}

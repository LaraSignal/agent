<?php

namespace LaraSignal\Agent\Console;

use Illuminate\Console\Command;
use LaraSignal\Agent\Recorder;

final class TestEventCommand extends Command
{
    protected $signature = 'larasignal:test';

    protected $description = 'Send a safe verification event';

    public function handle(Recorder $recorder): int
    {
        $recorder->record('verification', 'Agent verification', ['laravel_version' => app()->version()], status: 'completed', force: true);
        $recorder->flush();
        $this->components->info('Verification event queued for delivery.');

        return self::SUCCESS;
    }
}

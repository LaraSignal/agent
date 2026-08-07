<?php

namespace LaraSignal\Agent\Console;

use Illuminate\Console\Command;

final class StatusCommand extends Command
{
    protected $signature = 'larasignal:status';

    protected $description = 'Show the LaraSignal agent configuration status';

    public function handle(): int
    {
        $this->components->twoColumnDetail('Enabled', config('larasignal.enabled') ? 'yes' : 'no');
        $this->components->twoColumnDetail('Credential', blank(config('larasignal.key')) ? '<error>missing</error>' : 'configured');
        $this->components->twoColumnDetail('Endpoint', config('larasignal.ingest_url'));
        $this->components->twoColumnDetail('Environment', config('larasignal.environment'));
        $this->components->twoColumnDetail('Sampling', (string) config('larasignal.sample_rate'));

        return blank(config('larasignal.key')) ? self::FAILURE : self::SUCCESS;
    }
}

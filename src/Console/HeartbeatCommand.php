<?php

namespace LaraSignal\Agent\Console;

use Illuminate\Console\Command;
use LaraSignal\Agent\Recorder;

final class HeartbeatCommand extends Command
{
    protected $signature = 'larasignal:heartbeat
        {name=scheduler : The process or scheduler being monitored}
        {--every=60 : Expected interval in seconds}';

    protected $description = 'Record a process heartbeat in LaraSignal';

    public function handle(Recorder $recorder): int
    {
        $interval = max(1, (int) $this->option('every'));
        $name = (string) $this->argument('name');

        $recorder->heartbeat($name, $interval);
        $recorder->flush();

        $this->components->info(sprintf('Recorded %s heartbeat (expected every %d seconds).', $name, $interval));

        return self::SUCCESS;
    }
}

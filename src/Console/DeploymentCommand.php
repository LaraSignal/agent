<?php

namespace LaraSignal\Agent\Console;

use Illuminate\Console\Command;
use LaraSignal\Agent\Recorder;

final class DeploymentCommand extends Command
{
    protected $signature = 'larasignal:deployment
        {release? : The deployment release version, git tag, or commit SHA}';

    protected $description = 'Record a deployment release event in LaraSignal';

    public function handle(Recorder $recorder): int
    {
        $release = $this->argument('release')
            ?: config('larasignal.release')
            ?: $this->detectGitCommitHash()
            ?: now()->format('Y.m.d-His');

        $this->components->info(sprintf('Recording LaraSignal deployment for release: %s', $release));

        $recorder->record('deployment', 'Release '.$release, [
            'release' => $release,
            'environment' => config('larasignal.environment'),
            'timestamp' => now()->toIso8601String(),
        ], force: true);

        $recorder->flush();

        $this->components->info('Deployment event recorded successfully!');

        return self::SUCCESS;
    }

    private function detectGitCommitHash(): ?string
    {
        if (function_exists('exec')) {
            $output = @exec('git rev-parse HEAD 2>/dev/null');
            if ($output && is_string($output) && strlen(trim($output)) === 40) {
                return trim($output);
            }
        }

        return null;
    }
}

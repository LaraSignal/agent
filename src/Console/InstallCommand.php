<?php

namespace LaraSignal\Agent\Console;

use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

final class InstallCommand extends Command
{
    protected $signature = 'larasignal:install
        {--key= : The LaraSignal project API key}
        {--sample-rate= : Telemetry sampling rate (0.0 to 1.0)}
        {--record-queries= : Record database queries (true/false)}
        {--ingest-url= : Custom LaraSignal ingestion endpoint}';

    protected $description = 'Install the LaraSignal telemetry agent and configure environment settings';

    public function handle(): int
    {
        $this->components->info('Installing LaraSignal Agent...');

        $this->call('vendor:publish', [
            '--tag' => 'larasignal-config',
            '--force' => false,
        ]);

        $isInteractive = $this->input->isInteractive();

        // 1. API Key
        $key = $this->option('key');

        if ($key === null && $isInteractive) {
            $key = text(
                label: 'What is your LaraSignal API key?',
                placeholder: 'ls_live_...',
                default: (string) config('larasignal.key', '')
            );
        }

        $key = (string) ($key ?? '');
        $this->setEnvironmentValue('LARASIGNAL_KEY', $key);

        if ($key !== '') {
            $this->components->info('Saved LARASIGNAL_KEY to your .env file.');
        } else {
            $this->components->warn('No API key provided. Updated LARASIGNAL_KEY in .env file (set when ready).');
        }

        // 2. Sample Rate
        $sampleRateOption = $this->option('sample-rate');

        if ($sampleRateOption === null && $isInteractive) {
            $sampleRateOption = text(
                label: 'What telemetry sample rate would you like to use?',
                default: (string) config('larasignal.sample_rate', 1),
                validate: fn (string $value) => is_numeric($value) && (float) $value >= 0 && (float) $value <= 1
                    ? null
                    : 'The sample rate must be a float between 0.0 and 1.0.'
            );
        }

        if ($sampleRateOption !== null && is_numeric($sampleRateOption)) {
            $this->setEnvironmentValue('LARASIGNAL_SAMPLE_RATE', (string) (float) $sampleRateOption);
            $this->components->info(sprintf('Set LARASIGNAL_SAMPLE_RATE to %s in your .env file.', (float) $sampleRateOption));
        }

        // 3. Record Queries
        $recordQueriesOption = $this->option('record-queries');

        if ($recordQueriesOption === null && $isInteractive) {
            $recordQueriesBool = confirm(
                label: 'Do you want to record database queries?',
                default: (bool) config('larasignal.record_queries', true)
            );
        } else {
            $recordQueriesBool = filter_var($recordQueriesOption ?? 'true', FILTER_VALIDATE_BOOLEAN);
        }

        $this->setEnvironmentValue('LARASIGNAL_RECORD_QUERIES', $recordQueriesBool ? 'true' : 'false');
        $this->components->info(sprintf('Set LARASIGNAL_RECORD_QUERIES to %s in your .env file.', $recordQueriesBool ? 'true' : 'false'));

        // 4. Ingest URL (Optional)
        $ingestUrlOption = $this->option('ingest-url');

        if ($ingestUrlOption !== null && $ingestUrlOption !== '') {
            $this->setEnvironmentValue('LARASIGNAL_INGEST_URL', $ingestUrlOption);
            $this->components->info(sprintf('Set LARASIGNAL_INGEST_URL to %s in your .env file.', $ingestUrlOption));
        }

        $this->components->info('LaraSignal Agent installed and configured successfully!');

        return self::SUCCESS;
    }

    private function setEnvironmentValue(string $key, string $value): void
    {
        $envPath = base_path('.env');

        if (! file_exists($envPath)) {
            return;
        }

        $content = file_get_contents($envPath) ?: '';

        if (preg_match("/^{$key}=.*/m", $content)) {
            $content = preg_replace("/^{$key}=.*/m", "{$key}={$value}", $content);
        } else {
            $content .= PHP_EOL."{$key}={$value}".PHP_EOL;
        }

        file_put_contents($envPath, $content);
    }
}

<?php

namespace LaraSignal\Agent\Console;

use Illuminate\Console\Command;
use LaraSignal\Agent\Recorder;

final class TestEventCommand extends Command
{
    protected $signature = 'larasignal:test {--all : Send one representative event for every activity category}';

    protected $description = 'Send a safe verification event';

    public function handle(Recorder $recorder): int
    {
        $recorder->discardPendingEvents();

        if ($this->option('all')) {
            foreach ($this->activityEvents() as $event) {
                $recorder->record(
                    $event['type'],
                    $event['name'],
                    $event['attributes'] ?? [],
                    $event['duration_us'] ?? null,
                    $event['status'],
                    force: true,
                    severity: $event['severity'] ?? null,
                );
            }

            $recorder->flush();
            $this->components->info('Activity verification events queued for delivery.');

            return self::SUCCESS;
        }

        $recorder->record('verification', 'Agent verification', ['laravel_version' => app()->version()], status: 'completed', force: true);
        $recorder->flush();
        $this->components->info('Verification event queued for delivery.');

        return self::SUCCESS;
    }

    /** @return array<int, array{type: string, name: string, status: string, attributes?: array<string, mixed>, duration_us?: int, severity?: string}> */
    private function activityEvents(): array
    {
        return [
            ['type' => 'request', 'name' => 'GET /larasignal-test', 'status' => '200', 'duration_us' => 12_000, 'attributes' => ['method' => 'GET', 'route' => '/larasignal-test']],
            ['type' => 'job', 'name' => 'LaraSignalActivityTestJob', 'status' => 'completed', 'duration_us' => 8_000, 'attributes' => ['phase' => 'processed', 'attempt' => 1]],
            ['type' => 'command', 'name' => 'larasignal:activity-test', 'status' => 'completed', 'duration_us' => 5_000],
            ['type' => 'schedule', 'name' => 'LaraSignalActivityTestTask', 'status' => 'completed', 'duration_us' => 4_000],
            ['type' => 'exception', 'name' => 'LaraSignal activity test exception', 'status' => 'failed', 'severity' => 'error', 'attributes' => ['exception_class' => 'LaraSignalActivityTestException']],
            ['type' => 'query', 'name' => 'select * from larasignal_activity_test where id = ?', 'status' => 'completed', 'duration_us' => 2_000, 'attributes' => ['connection' => 'test']],
            ['type' => 'notification', 'name' => 'LaraSignalActivityTestNotification', 'status' => 'completed', 'attributes' => ['channel' => 'mail']],
            ['type' => 'mail', 'name' => 'LaraSignalActivityTestMail', 'status' => 'completed', 'duration_us' => 3_000],
            ['type' => 'cache', 'name' => 'cache.hit', 'status' => 'completed', 'duration_us' => 1_000, 'attributes' => ['key' => 'larasignal-activity-test']],
            ['type' => 'http', 'name' => 'GET example.com', 'status' => '200', 'duration_us' => 15_000, 'attributes' => ['host' => 'example.com', 'url' => 'https://example.com/larasignal-test']],
            ['type' => 'event', 'name' => 'LaraSignalActivityTestEvent', 'status' => 'completed', 'attributes' => ['source' => 'larasignal:test']],
            ['type' => 'broadcast', 'name' => 'LaraSignalActivityTestBroadcast', 'status' => 'completed', 'duration_us' => 6_000, 'attributes' => ['phase' => 'published', 'provider' => 'reverb', 'broadcast_connections' => ['reverb'], 'channels' => ['larasignal-test']]],
            ['type' => 'span', 'name' => 'LaraSignalActivityTestSpan', 'status' => 'completed', 'duration_us' => 7_000],
            ['type' => 'deployment', 'name' => 'Release test', 'status' => 'completed', 'attributes' => ['release' => 'test']],
            ['type' => 'authentication', 'name' => 'Login', 'status' => 'completed', 'attributes' => ['phase' => 'login', 'guard' => 'web']],
            ['type' => 'security', 'name' => 'Authorization denied', 'status' => 'failed', 'severity' => 'warning', 'attributes' => ['phase' => 'authorization_denied', 'ability' => 'update']],
            ['type' => 'queue', 'name' => 'default', 'status' => 'completed', 'attributes' => ['phase' => 'queued', 'connection' => 'database', 'queue' => 'default']],
            ['type' => 'transaction', 'name' => 'mysql', 'status' => 'completed', 'duration_us' => 2_000, 'attributes' => ['phase' => 'committed', 'connection' => 'mysql']],
            ['type' => 'storage', 'name' => 'write', 'status' => 'completed', 'duration_us' => 3_000, 'attributes' => ['operation' => 'write', 'disk' => 's3', 'extension' => 'pdf']],
            ['type' => 'runtime', 'name' => 'Queue worker started', 'status' => 'completed', 'attributes' => ['phase' => 'worker_started', 'memory_mb' => 32]],
            ['type' => 'log', 'name' => 'LaraSignal activity test log', 'status' => 'completed', 'severity' => 'info', 'attributes' => ['level' => 'info']],
        ];
    }
}

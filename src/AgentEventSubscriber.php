<?php

namespace LaraSignal\Agent;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

final class AgentEventSubscriber
{
    public function __construct(private readonly Recorder $recorder) {}

    public function register(): void
    {
        if (config('larasignal.record_queries', true)) {
            DB::listen(function (QueryExecuted $event) {
                $thresholdMs = (int) config('larasignal.slow_query_threshold_ms', 0);
                if ($thresholdMs > 0 && $event->time < $thresholdMs) {
                    return;
                }

                $this->recorder->record('query', $this->normalizeSql($event->sql), [
                    'connection' => $event->connectionName,
                ], (int) ($event->time * 1000), 'completed');
            });
        }

        Event::listen(JobProcessing::class, function ($event) {
            $jobName = $event->job->resolveName();
            if ($this->isIgnoredJob($jobName)) {
                return;
            }
            $this->recorder->record('job', $jobName, ['phase' => 'processing', 'attempt' => $event->job->attempts()]);
        });

        Event::listen(JobProcessed::class, function ($event) {
            $jobName = $event->job->resolveName();
            if ($this->isIgnoredJob($jobName)) {
                return;
            }
            $this->recorder->record('job', $jobName, ['phase' => 'processed', 'attempt' => $event->job->attempts()], status: 'completed');
        });

        Event::listen(JobExceptionOccurred::class, function ($event) {
            $jobName = $event->job->resolveName();
            if ($this->isIgnoredJob($jobName)) {
                return;
            }
            $this->recorder->exception($event->exception, ['job' => $jobName]);
        });

        Event::listen(MessageSent::class, fn ($event) => $this->recorder->record('mail', $event->message::class, ['phase' => 'sent'], status: 'completed'));
        Event::listen(ResponseReceived::class, function ($event) {
            if (Client::$sending) {
                return;
            }
            $this->recorder->record('http', $event->request->method().' '.$event->request->toPsrRequest()->getUri()->getHost(), ['host' => $event->request->toPsrRequest()->getUri()->getHost()], status: (string) $event->response->status());
        });

        Event::listen('cache.*', fn (string $name) => $this->recorder->record('cache', $name));

        if (config('larasignal.record_logs', true)) {
            Event::listen(MessageLogged::class, function ($event) {
                $this->recorder->record('log', $event->message, [
                    'level' => $event->level,
                    'context' => $event->context,
                ], status: $event->level === 'error' ? 'failed' : 'completed');
            });
        }

        Event::listen('Illuminate\\Console\\Events\\*', function (string $name, array $data) {
            $event = $data[0] ?? null;
            $commandName = is_object($event) && isset($event->command) ? $event->command : class_basename($name);

            if (! $commandName || $this->isIgnoredCommand($commandName)) {
                return;
            }

            $this->recorder->record('command', (string) $commandName);
        });

        Event::listen('Illuminate\\Console\\Events\\ScheduledTask*', fn (string $name) => $this->recorder->record('schedule', class_basename($name)));
    }

    private function isIgnoredCommand(string $command): bool
    {
        foreach (config('larasignal.ignored_commands', []) as $pattern) {
            if (Str::is($pattern, $command)) {
                return true;
            }
        }

        return false;
    }

    private function isIgnoredJob(string $jobClass): bool
    {
        foreach (config('larasignal.ignored_jobs', []) as $ignored) {
            if ($jobClass === $ignored || is_a($jobClass, $ignored, true)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeSql(string $sql): string
    {
        return preg_replace(['/\\b\\d+\\b/', "/'[^']*'/"], ['?', '?'], $sql) ?: $sql;
    }
}

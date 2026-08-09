<?php

namespace LaraSignal\Agent;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

final class AgentEventSubscriber
{
    /** @var array<string, float|int> */
    private array $jobStartedAt = [];

    /** @var array<string, float|int> */
    private array $commandStartedAt = [];

    /** @var array<int, float|int> */
    private array $scheduledTaskStartedAt = [];

    /** @var array<int, true> */
    private array $failedScheduledTasksRecordedOnFinish = [];

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
            $this->jobStartedAt[$this->jobKey($event->job)] = hrtime(true);
        });

        Event::listen(JobProcessed::class, function ($event) {
            $jobName = $event->job->resolveName();
            if ($this->isIgnoredJob($jobName)) {
                return;
            }
            $this->recordJobOutcome($event->connectionName, $event->job, 'processed', 'completed');
        });

        Event::listen(JobReleasedAfterException::class, function ($event) {
            $jobName = $event->job->resolveName();
            if ($this->isIgnoredJob($jobName)) {
                return;
            }

            $attributes = ['backoff' => $event->backoff];
            if ($event->exception) {
                $attributes['exception'] = $event->exception::class;
                $attributes['message'] = $event->exception->getMessage();
            }

            $this->recordJobOutcome($event->connectionName, $event->job, 'released', 'released', $attributes);
        });

        Event::listen(JobExceptionOccurred::class, function ($event) {
            $jobName = $event->job->resolveName();
            if ($this->isIgnoredJob($jobName)) {
                return;
            }
            $this->recorder->exception($event->exception, ['job' => $jobName]);
        });

        Event::listen(JobFailed::class, function ($event) {
            $jobName = $event->job->resolveName();
            if ($this->isIgnoredJob($jobName)) {
                return;
            }

            $this->recordJobOutcome($event->connectionName, $event->job, 'failed', 'failed', [
                'exception' => $event->exception::class,
                'message' => $event->exception->getMessage(),
            ]);
        });

        Event::listen(MessageSent::class, fn ($event) => $this->recorder->record('mail', $event->message::class, ['phase' => 'sent'], status: 'completed'));
        Event::listen(NotificationSent::class, fn (NotificationSent $event) => $this->recorder->record('notification', $event->notification::class, ['channel' => $event->channel], status: 'completed'));
        Event::listen(NotificationFailed::class, fn (NotificationFailed $event) => $this->recorder->record('notification', $event->notification::class, ['channel' => $event->channel], status: 'failed'));
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
                ], status: $event->level === 'error' ? 'failed' : 'completed', severity: $event->level);
            });
        }

        Event::listen(CommandStarting::class, function (CommandStarting $event) {
            if ($this->isIgnoredCommand($event->command)) {
                return;
            }

            $this->commandStartedAt[$event->command] = hrtime(true);
        });

        Event::listen(CommandFinished::class, function (CommandFinished $event) {
            if ($this->isIgnoredCommand($event->command)) {
                return;
            }

            $startedAt = $this->commandStartedAt[$event->command] ?? null;
            unset($this->commandStartedAt[$event->command]);

            $this->recorder->record('command', $event->command, [
                'exit_code' => $event->exitCode,
                'arguments' => $event->input->getArguments(),
                'options' => $event->input->getOptions(),
                'peak_memory_bytes' => memory_get_peak_usage(true),
            ], $startedAt ? (int) round((hrtime(true) - $startedAt) / 1000) : null, $event->exitCode === 0 ? 'completed' : 'failed');
        });

        Event::listen(ScheduledTaskStarting::class, function (ScheduledTaskStarting $event) {
            $this->scheduledTaskStartedAt[spl_object_id($event->task)] = hrtime(true);
        });

        Event::listen(ScheduledTaskFinished::class, function (ScheduledTaskFinished $event) {
            $key = spl_object_id($event->task);
            unset($this->scheduledTaskStartedAt[$key]);
            $failed = filled($event->task->exitCode) && $event->task->exitCode !== 0;

            if ($failed) {
                $this->failedScheduledTasksRecordedOnFinish[$key] = true;
            }

            $this->recorder->record('schedule', $event->task->getSummaryForDisplay(), $this->scheduledTaskAttributes($event->task), (int) round($event->runtime * 1_000_000), $failed ? 'failed' : 'completed');
        });

        Event::listen(ScheduledTaskFailed::class, function (ScheduledTaskFailed $event) {
            $key = spl_object_id($event->task);
            if (isset($this->failedScheduledTasksRecordedOnFinish[$key])) {
                unset($this->failedScheduledTasksRecordedOnFinish[$key]);

                return;
            }

            $startedAt = $this->scheduledTaskStartedAt[$key] ?? null;
            unset($this->scheduledTaskStartedAt[$key]);

            $this->recorder->record('schedule', $event->task->getSummaryForDisplay(), array_merge($this->scheduledTaskAttributes($event->task), [
                'exception' => $event->exception::class,
                'message' => $event->exception->getMessage(),
            ]), $startedAt ? (int) round((hrtime(true) - $startedAt) / 1000) : null, 'failed');
        });

        Event::listen(ScheduledTaskSkipped::class, fn (ScheduledTaskSkipped $event) => $this->recorder->record('schedule', $event->task->getSummaryForDisplay(), $this->scheduledTaskAttributes($event->task), status: 'skipped'));
    }

    private function isIgnoredCommand(string $command): bool
    {
        if (Str::is('larasignal:*', $command)) {
            return true;
        }

        foreach (config('larasignal.ignored_commands', []) as $pattern) {
            if (Str::is($pattern, $command)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function scheduledTaskAttributes(ScheduledEvent $task): array
    {
        return [
            'expression' => $task->getExpression(),
            'timezone' => $task->timezone instanceof \DateTimeZone
                ? $task->timezone->getName()
                : $task->timezone,
            'next_run_at' => $task->nextRunDate()->toIso8601String(),
            'exit_code' => $task->exitCode,
            'without_overlapping' => $task->withoutOverlapping,
            'on_one_server' => $task->onOneServer,
            'run_in_background' => $task->runInBackground,
            'even_in_maintenance_mode' => $task->evenInMaintenanceMode,
            'environments' => $task->environments,
            'peak_memory_bytes' => memory_get_peak_usage(true),
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function recordJobOutcome(string $connection, Job $job, string $phase, string $status, array $attributes = []): void
    {
        $key = $this->jobKey($job);
        $startedAt = $this->jobStartedAt[$key] ?? null;
        unset($this->jobStartedAt[$key]);

        $this->recorder->record('job', $job->resolveName(), array_merge([
            'phase' => $phase,
            'attempt' => $job->attempts(),
            'connection' => $connection,
            'queue' => $job->getQueue(),
            'job_id' => $job->uuid() ?: $job->getJobId(),
        ], $attributes), $startedAt ? (int) round((hrtime(true) - $startedAt) / 1000) : null, $status);
    }

    private function jobKey(Job $job): string
    {
        return (string) ($job->uuid() ?: $job->getJobId() ?: spl_object_id($job));
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

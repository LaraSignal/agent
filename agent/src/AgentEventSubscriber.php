<?php

namespace LaraSignal\Agent;

use Illuminate\Auth\Access\Events\GateEvaluated;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Broadcasting\Channel;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\Events\DatabaseBusy;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Queue\Events\QueueFailedOver;
use Illuminate\Queue\Events\WorkerIdle;
use Illuminate\Queue\Events\WorkerStarting;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Throwable;

final class AgentEventSubscriber
{
    /** @var array<string, float|int> */
    private array $jobStartedAt = [];

    /** @var array<string, int> */
    private array $jobWaitTimeUs = [];

    /** @var array<int, float|int> */
    private array $outgoingRequestStartedAt = [];

    /** @var array<int, float|int> */
    private array $mailStartedAt = [];

    /** @var array<string, array<int, float|int>> */
    private array $transactionsStartedAt = [];

    /** @var array<string, array<int, float|int>> */
    private array $cacheOperationsStartedAt = [];

    /** @var array<string, float|int> */
    private array $commandStartedAt = [];

    /** @var array<int, float|int> */
    private array $scheduledTaskStartedAt = [];

    /** @var array<int, true> */
    private array $failedScheduledTasksRecordedOnFinish = [];

    public function __construct(private readonly Recorder $recorder) {}

    public function register(): void
    {
        $this->registerAuthenticationEvents();
        $this->registerSecurityEvents();
        $this->registerTransactionEvents();
        $this->registerQueueHealthEvents();
        $this->registerCacheEvents();

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
            $createdAt = data_get($event->job->payload(), 'createdAt');
            if (is_numeric($createdAt)) {
                $this->jobWaitTimeUs[$this->jobKey($event->job)] = max(0, (int) round((microtime(true) - (float) $createdAt) * 1_000_000));
            }
        });

        Event::listen(JobProcessed::class, function ($event) {
            $jobName = $event->job->resolveName();
            if ($this->isIgnoredJob($jobName)) {
                return;
            }
            $this->recordBroadcastOutcome($event->connectionName, $event->job, 'completed');
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

            $this->recordBroadcastOutcome($event->connectionName, $event->job, 'failed', $event->exception);
            $this->recordMailFailure($event->job, $event->exception);
            $this->recordJobOutcome($event->connectionName, $event->job, 'failed', 'failed', [
                'exception' => $event->exception::class,
                'message' => $event->exception->getMessage(),
            ]);
        });

        $this->registerReverbEvents();

        Event::listen(MessageSending::class, function (MessageSending $event): void {
            $this->mailStartedAt[spl_object_id($event->message)] = hrtime(true);
        });
        Event::listen(MessageSent::class, function (MessageSent $event): void {
            $key = spl_object_id($event->message);
            $startedAt = $this->mailStartedAt[$key] ?? null;
            unset($this->mailStartedAt[$key]);
            $this->recorder->record('mail', $event->message::class, [
                'phase' => 'sent',
                'mailer' => config('mail.default'),
                'transport' => config('mail.mailers.'.config('mail.default').'.transport'),
                'recipient_count' => count($event->message->getTo()),
            ], $startedAt ? (int) round((hrtime(true) - $startedAt) / 1000) : null, 'completed');
        });
        Event::listen(NotificationSent::class, fn (NotificationSent $event) => $this->recorder->record('notification', $event->notification::class, ['channel' => $event->channel], status: 'completed'));
        Event::listen(NotificationFailed::class, fn (NotificationFailed $event) => $this->recorder->record('notification', $event->notification::class, ['channel' => $event->channel], status: 'failed'));
        Event::listen(RequestSending::class, function (RequestSending $event): void {
            if (! Client::$sending) {
                $this->outgoingRequestStartedAt[spl_object_id($event->request)] = hrtime(true);
            }
        });
        Event::listen(ResponseReceived::class, function ($event) {
            if (Client::$sending) {
                return;
            }
            $key = spl_object_id($event->request);
            $startedAt = $this->outgoingRequestStartedAt[$key] ?? null;
            unset($this->outgoingRequestStartedAt[$key]);
            $uri = $event->request->toPsrRequest()->getUri();
            $this->recorder->record('http', $event->request->method().' '.$uri->getHost(), [
                'method' => $event->request->method(),
                'host' => $uri->getHost(),
                'url' => $uri->getScheme().'://'.$uri->getAuthority().$uri->getPath(),
                'phase' => 'response',
            ], $startedAt ? (int) round((hrtime(true) - $startedAt) / 1000) : null, (string) $event->response->status());
        });
        Event::listen(ConnectionFailed::class, function (ConnectionFailed $event): void {
            if (Client::$sending) {
                return;
            }
            $key = spl_object_id($event->request);
            $startedAt = $this->outgoingRequestStartedAt[$key] ?? null;
            unset($this->outgoingRequestStartedAt[$key]);
            $uri = $event->request->toPsrRequest()->getUri();
            $this->recorder->record('http', $event->request->method().' '.$uri->getHost(), [
                'method' => $event->request->method(),
                'host' => $uri->getHost(),
                'url' => $uri->getScheme().'://'.$uri->getAuthority().$uri->getPath(),
                'phase' => 'connection_failed',
                'exception' => $event->exception::class,
                'message' => $event->exception->getMessage(),
            ], $startedAt ? (int) round((hrtime(true) - $startedAt) / 1000) : null, 'failed');
        });

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
        $waitTimeUs = $this->jobWaitTimeUs[$key] ?? null;
        unset($this->jobStartedAt[$key]);
        unset($this->jobWaitTimeUs[$key]);

        $this->recorder->record('job', $job->resolveName(), array_merge([
            'phase' => $phase,
            'attempt' => $job->attempts(),
            'connection' => $connection,
            'queue' => $job->getQueue(),
            'job_id' => $job->uuid() ?: $job->getJobId(),
            'wait_time_us' => $waitTimeUs,
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

    private function recordBroadcastOutcome(string $queueConnection, Job $job, string $status, ?Throwable $exception = null): void
    {
        $broadcast = $this->broadcastJob($job);

        if (! $broadcast) {
            return;
        }

        $event = $broadcast->event;

        if (! is_object($event) || ! method_exists($event, 'broadcastOn')) {
            return;
        }

        $connections = method_exists($event, 'broadcastConnections') ? $event->broadcastConnections() : [null];
        $connectionNames = collect(is_iterable($connections) ? $connections : [null])->map(fn (mixed $connection): string => (string) ($connection ?: config('broadcasting.default', 'null')))->values();
        $providers = $connectionNames->map(fn (string $connection): string => (string) config("broadcasting.connections.{$connection}.driver", $connection))->unique()->values();
        $channels = collect((array) $event->broadcastOn())->map(fn (mixed $channel): string => $channel instanceof Channel ? $channel->name : (string) $channel)->values();
        $name = method_exists($event, 'broadcastAs') ? (string) $event->broadcastAs() : $event::class;

        $attributes = [
            'phase' => 'published',
            'provider' => $providers->count() === 1 ? $providers->first() : null,
            'providers' => $providers->all(),
            'broadcast_connections' => $connectionNames->all(),
            'channels' => $channels->all(),
            'channel_count' => $channels->count(),
            'queue_connection' => $queueConnection,
            'queue' => $job->getQueue(),
            'attempt' => $job->attempts(),
            'job_id' => $job->uuid() ?: $job->getJobId(),
        ];

        if ($exception) {
            $attributes['exception'] = $exception::class;
            $attributes['message'] = $exception->getMessage();
        }

        $key = $this->jobKey($job);
        $startedAt = $this->jobStartedAt[$key] ?? null;
        $this->recorder->record('broadcast', $name, $attributes, $startedAt ? (int) round((hrtime(true) - $startedAt) / 1000) : null, $status);
    }

    private function broadcastJob(Job $job): ?BroadcastEvent
    {
        $payload = $job->payload();
        $commandName = $payload['data']['commandName'] ?? null;

        if (! is_string($commandName) || ! is_a($commandName, BroadcastEvent::class, true)) {
            return null;
        }

        $serialized = $payload['data']['command'] ?? null;

        if (! is_string($serialized)) {
            return null;
        }

        try {
            $command = unserialize($serialized, ['allowed_classes' => true]);
        } catch (Throwable) {
            return null;
        }

        return $command instanceof BroadcastEvent ? $command : null;
    }

    private function registerReverbEvents(): void
    {
        foreach ([
            'Laravel\\Reverb\\Events\\ChannelCreated' => 'connected',
            'Laravel\\Reverb\\Events\\ChannelRemoved' => 'disconnected',
            'Laravel\\Reverb\\Events\\ConnectionPruned' => 'pruned',
            'Laravel\\Reverb\\Events\\MessageReceived' => 'received',
            'Laravel\\Reverb\\Events\\MessageSent' => 'sent',
        ] as $eventClass => $phase) {
            Event::listen($eventClass, function (object $event) use ($phase): void {
                $reverbChannel = data_get($event, 'channel');
                $channel = data_get($event, 'channel.name') ?: (is_scalar($reverbChannel) ? $reverbChannel : null);
                $connection = data_get($event, 'connection');
                $connectionId = is_object($connection) && method_exists($connection, 'id') ? $connection->id() : null;
                $connections = is_object($reverbChannel) && method_exists($reverbChannel, 'connections') ? $reverbChannel->connections() : null;
                $connectionCount = is_countable($connections) ? count($connections) : match ($phase) {
                    'connected' => 1,
                    'disconnected' => 0,
                    default => null,
                };

                $this->recorder->record('broadcast', $channel ? (string) $channel : 'Reverb connection', [
                    'phase' => $phase,
                    'provider' => 'reverb',
                    'channel' => $channel ? (string) $channel : null,
                    'connection_id' => $connectionId,
                    'connection_count' => $connectionCount,
                ], status: in_array($phase, ['pruned'], true) ? 'failed' : 'completed');
            });
        }
    }

    private function registerAuthenticationEvents(): void
    {
        foreach ([
            'Illuminate\\Auth\\Events\\Attempting' => ['attempting', 'completed'],
            'Illuminate\\Auth\\Events\\Authenticated' => ['authenticated', 'completed'],
            'Illuminate\\Auth\\Events\\Failed' => ['failed', 'failed'],
            'Illuminate\\Auth\\Events\\Lockout' => ['lockout', 'failed'],
            'Illuminate\\Auth\\Events\\Login' => ['login', 'completed'],
            'Illuminate\\Auth\\Events\\Logout' => ['logout', 'completed'],
            'Illuminate\\Auth\\Events\\Registered' => ['registered', 'completed'],
            'Illuminate\\Auth\\Events\\Verified' => ['verified', 'completed'],
            'Illuminate\\Auth\\Events\\PasswordReset' => ['password_reset', 'completed'],
            'Illuminate\\Auth\\Events\\PasswordResetLinkSent' => ['password_reset_link_sent', 'completed'],
        ] as $eventClass => [$phase, $status]) {
            Event::listen($eventClass, function (object $event) use ($phase, $status): void {
                $user = data_get($event, 'user');
                $this->recorder->record('authentication', Str::headline($phase), array_filter(array_merge([
                    'phase' => $phase,
                    'guard' => data_get($event, 'guard'),
                    'user_id' => is_object($user) && is_callable([$user, 'getAuthIdentifier']) ? $user->getAuthIdentifier() : null,
                    'remember' => data_get($event, 'remember'),
                ], $this->authenticationRequestContext()), fn (mixed $value): bool => $value !== null), status: $status);
            });
        }

        foreach ([
            'Laravel\\Passport\\Events\\AccessTokenCreated' => 'access_token_created',
            'Laravel\\Passport\\Events\\RefreshTokenCreated' => 'refresh_token_created',
        ] as $eventClass => $phase) {
            Event::listen($eventClass, function (object $event) use ($phase): void {
                $tokenId = data_get($event, 'tokenId') ?? data_get($event, 'refreshTokenId');

                $this->recorder->record('authentication', Str::headline($phase), array_filter(array_merge([
                    'phase' => $phase,
                    'provider' => 'passport',
                    'user_id' => data_get($event, 'userId'),
                    'client_id' => data_get($event, 'clientId'),
                    'credential_id_hash' => filled($tokenId) ? hash('sha256', (string) $tokenId) : null,
                ], $this->authenticationRequestContext()), fn (mixed $value): bool => $value !== null), status: 'completed');
            });
        }
    }

    /** @return array<string, mixed> */
    private function authenticationRequestContext(): array
    {
        if (! app()->bound('request')) {
            return [];
        }

        $request = app('request');
        if (! $request instanceof Request) {
            return [];
        }

        return [
            'method' => $request->method(),
            'route' => $request->route()?->uri(),
            'path' => '/'.ltrim($request->path(), '/'),
            'controller' => $request->route()?->getActionName(),
            'ip_hash' => filled($request->ip()) ? hash('sha256', (string) $request->ip()) : null,
            'user_agent' => $request->userAgent(),
        ];
    }

    private function registerSecurityEvents(): void
    {
        Event::listen(GateEvaluated::class, function (GateEvaluated $event): void {
            if ($event->result !== false) {
                return;
            }

            $subjects = collect($event->arguments)->map(fn (mixed $argument): string => is_object($argument) ? $argument::class : get_debug_type($argument))->values()->all();
            $this->recorder->record('security', 'Authorization denied', [
                'phase' => 'authorization_denied',
                'ability' => $event->ability,
                'subjects' => $subjects,
                'user_id' => $event->user?->getAuthIdentifier(),
            ], status: 'failed', severity: 'warning');
        });
    }

    private function registerTransactionEvents(): void
    {
        Event::listen(TransactionBeginning::class, function (TransactionBeginning $event): void {
            $this->transactionsStartedAt[$event->connectionName][] = hrtime(true);
        });

        foreach ([TransactionCommitted::class => 'committed', TransactionRolledBack::class => 'rolled_back'] as $eventClass => $phase) {
            Event::listen($eventClass, function (object $event) use ($phase): void {
                $connectionName = (string) data_get($event, 'connectionName', 'default');
                $connection = data_get($event, 'connection');
                $transactionStack = $this->transactionsStartedAt[$connectionName] ?? [];
                $startedAt = array_pop($transactionStack);
                $this->transactionsStartedAt[$connectionName] = $transactionStack;
                $this->recorder->record('transaction', $connectionName, [
                    'phase' => $phase,
                    'connection' => $connectionName,
                    'transaction_level' => is_object($connection) && method_exists($connection, 'transactionLevel') ? $connection->transactionLevel() : null,
                ], $startedAt ? (int) round((hrtime(true) - $startedAt) / 1000) : null, $phase === 'rolled_back' ? 'failed' : 'completed');
            });
        }

        Event::listen(ConnectionEstablished::class, fn (ConnectionEstablished $event) => $this->recorder->runtime('Database connection established', [
            'phase' => 'database_connected',
            'connection' => $event->connectionName,
            'driver' => $event->connection->getDriverName(),
        ]));
        Event::listen(DatabaseBusy::class, fn (DatabaseBusy $event) => $this->recorder->runtime('Database connections busy', [
            'phase' => 'database_busy',
            'connection' => $event->connectionName,
            'connection_count' => $event->connections,
        ], 'failed'));
    }

    private function registerQueueHealthEvents(): void
    {
        if (property_exists(JobQueued::class, 'queue') && property_exists(JobQueued::class, 'delay')) {
            Event::listen(JobQueued::class, function (JobQueued $event): void {
                $payload = $event->payload();
                $this->recorder->record('queue', (string) ($event->queue ?: 'default'), [
                    'phase' => 'queued',
                    'connection' => $event->connectionName,
                    'queue' => $event->queue ?: 'default',
                    'job' => data_get($payload, 'displayName'),
                    'job_id' => $event->id ?: data_get($payload, 'uuid'),
                    'delay_seconds' => $event->delay,
                ], status: 'completed');
            });
        }
        Event::listen(JobTimedOut::class, fn (JobTimedOut $event) => $this->recorder->record('queue', $event->job->resolveName(), [
            'phase' => 'timed_out',
            'connection' => $event->connectionName,
            'queue' => $event->job->getQueue(),
            'job_id' => $event->job->uuid() ?: $event->job->getJobId(),
        ], status: 'failed', severity: 'error'));
        Event::listen(QueueBusy::class, fn (QueueBusy $event) => $this->recorder->record('queue', $event->queue, [
            'phase' => 'busy',
            'connection' => $event->connectionName,
            'queue' => $event->queue,
            'size' => $event->size,
        ], status: 'failed', severity: 'warning'));
        Event::listen(QueueFailedOver::class, fn (QueueFailedOver $event) => $this->recorder->record('queue', $event->connectionName ?: 'default', [
            'phase' => 'failed_over',
            'connection' => $event->connectionName,
            'exception' => $event->exception::class,
            'message' => $event->exception->getMessage(),
        ], status: 'failed', severity: 'warning'));
        Event::listen(WorkerStarting::class, fn (WorkerStarting $event) => $this->recorder->runtime('Queue worker started', [
            'phase' => 'worker_started',
            'connection' => data_get($event, 'connectionName'),
        ]));
        Event::listen(WorkerIdle::class, fn (WorkerIdle $event) => $this->recorder->runtime('Queue worker idle', [
            'phase' => 'worker_idle',
            'connection' => $event->connectionName,
            'queue' => $event->queue,
        ]));
        Event::listen(WorkerStopping::class, fn (WorkerStopping $event) => $this->recorder->runtime('Queue worker stopped', [
            'phase' => 'worker_stopped',
            'exit_status' => $event->status,
            'reason' => $event->reason?->name,
            'jobs_processed' => $event->jobsProcessed,
            'memory_mb' => $event->memoryUsage,
        ], $event->status === 0 ? 'completed' : 'failed'));
    }

    private function registerCacheEvents(): void
    {
        foreach ([
            'Illuminate\\Cache\\Events\\RetrievingKey' => 'read',
            'Illuminate\\Cache\\Events\\WritingKey' => 'write',
            'Illuminate\\Cache\\Events\\ForgettingKey' => 'forget',
            'Illuminate\\Cache\\Events\\CacheFlushing' => 'flush',
            'Illuminate\\Cache\\Events\\CacheLocksFlushing' => 'flush_locks',
        ] as $eventClass => $operation) {
            if (class_exists($eventClass)) {
                Event::listen($eventClass, function (object $event) use ($operation): void {
                    $this->cacheOperationsStartedAt[$this->cacheOperationKey($event, $operation)][] = hrtime(true);
                });
            }
        }

        foreach ([
            'Illuminate\\Cache\\Events\\CacheHit' => ['read', 'hit', 'completed'],
            'Illuminate\\Cache\\Events\\CacheMissed' => ['read', 'miss', 'completed'],
            'Illuminate\\Cache\\Events\\KeyWritten' => ['write', 'written', 'completed'],
            'Illuminate\\Cache\\Events\\KeyWriteFailed' => ['write', 'write_failed', 'failed'],
            'Illuminate\\Cache\\Events\\KeyForgotten' => ['forget', 'forgotten', 'completed'],
            'Illuminate\\Cache\\Events\\KeyForgetFailed' => ['forget', 'forget_failed', 'failed'],
            'Illuminate\\Cache\\Events\\CacheFlushed' => ['flush', 'flushed', 'completed'],
            'Illuminate\\Cache\\Events\\CacheFlushFailed' => ['flush', 'flush_failed', 'failed'],
            'Illuminate\\Cache\\Events\\CacheLocksFlushed' => ['flush_locks', 'locks_flushed', 'completed'],
            'Illuminate\\Cache\\Events\\CacheLocksFlushFailed' => ['flush_locks', 'locks_flush_failed', 'failed'],
        ] as $eventClass => [$operation, $phase, $status]) {
            if (! class_exists($eventClass)) {
                continue;
            }

            Event::listen($eventClass, function (object $event) use ($operation, $phase, $status): void {
                $key = $this->cacheOperationKey($event, $operation);
                $operationStack = $this->cacheOperationsStartedAt[$key] ?? [];
                $startedAt = array_pop($operationStack);
                $this->cacheOperationsStartedAt[$key] = $operationStack;

                $this->recorder->record('cache', $phase, array_filter([
                    'phase' => $phase,
                    'operation' => $operation,
                    'store' => data_get($event, 'storeName'),
                    'key_hash' => filled(data_get($event, 'key')) ? hash('sha256', (string) data_get($event, 'key')) : null,
                ]), $startedAt ? (int) round((hrtime(true) - $startedAt) / 1000) : null, $status);
            });
        }

        if (class_exists('Illuminate\\Cache\\Events\\CacheFailedOver')) {
            Event::listen('Illuminate\\Cache\\Events\\CacheFailedOver', fn (object $event) => $this->recorder->runtime('Cache failed over', [
                'phase' => 'cache_failed_over',
                'store' => data_get($event, 'storeName'),
            ], 'failed'));
        }
    }

    private function cacheOperationKey(object $event, string $operation): string
    {
        $key = filled(data_get($event, 'key')) ? hash('sha256', (string) data_get($event, 'key')) : 'all';

        return implode('|', [(string) data_get($event, 'storeName', 'default'), $operation, $key]);
    }

    private function recordMailFailure(Job $job, Throwable $exception): void
    {
        $payload = $job->payload();
        if (data_get($payload, 'data.commandName') !== 'Illuminate\\Mail\\SendQueuedMailable') {
            return;
        }

        $this->recorder->record('mail', (string) data_get($payload, 'displayName', 'Queued mailable'), [
            'phase' => 'failed',
            'mailer' => config('mail.default'),
            'transport' => config('mail.mailers.'.config('mail.default').'.transport'),
            'queue' => $job->getQueue(),
            'job_id' => $job->uuid() ?: $job->getJobId(),
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ], status: 'failed', severity: 'error');
    }
}

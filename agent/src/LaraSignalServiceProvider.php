<?php

namespace LaraSignal\Agent;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Exceptions\Handler as FrameworkExceptionHandler;
use Illuminate\Support\ServiceProvider;
use LaraSignal\Agent\Console\DeploymentCommand;
use LaraSignal\Agent\Console\FlushSpoolCommand;
use LaraSignal\Agent\Console\HeartbeatCommand;
use LaraSignal\Agent\Console\HelpCommand;
use LaraSignal\Agent\Console\InstallCommand;
use LaraSignal\Agent\Console\RunCommand;
use LaraSignal\Agent\Console\StatusCommand;
use LaraSignal\Agent\Console\TestEventCommand;
use LaraSignal\Agent\Http\Middleware\RecordRequest;
use LaraSignal\Agent\Support\Redactor;
use Throwable;

final class LaraSignalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/larasignal.php', 'larasignal');
        $this->app->singleton(Client::class);
        $this->app->singleton(Redactor::class);
        $this->app->singleton(Recorder::class);
        $this->app->singleton(AgentEventSubscriber::class);
    }

    public function boot(Kernel $kernel, ExceptionHandler $exceptionHandler, AgentEventSubscriber $subscriber): void
    {
        $this->publishes([__DIR__.'/../config/larasignal.php' => config_path('larasignal.php')], 'larasignal-config');

        $this->publishes([
            __DIR__.'/../stubs/larasignal-development.md' => base_path('.agents/skills/larasignal-development/SKILL.md'),
            __DIR__.'/../stubs/larasignal-rule.mdc' => base_path('.cursor/rules/larasignal.mdc'),
        ], 'larasignal-skill');

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                StatusCommand::class,
                TestEventCommand::class,
                FlushSpoolCommand::class,
                HeartbeatCommand::class,
                DeploymentCommand::class,
                HelpCommand::class,
                RunCommand::class,
            ]);
        }

        if ($exceptionHandler instanceof FrameworkExceptionHandler) {
            $exceptionHandler->reportable(function (Throwable $exception): void {
                $recorder = $this->app->make(Recorder::class);
                $recorder->exception($exception);
                $recorder->flush();
            });
        }

        if (! config('larasignal.enabled')) {
            return;
        }

        $kernel->pushMiddleware(RecordRequest::class);
        $subscriber->register();

        $this->app->terminating(function () {
            $this->app->make(Recorder::class)->flush();
        });
    }
}

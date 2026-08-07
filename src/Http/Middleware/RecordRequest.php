<?php

namespace LaraSignal\Agent\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use LaraSignal\Agent\Recorder;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class RecordRequest
{
    public function __construct(private readonly Recorder $recorder) {}

    public function handle(Request $request, Closure $next): Response
    {
        foreach (config('larasignal.ignored_routes', []) as $pattern) {
            if ($request->is($pattern)) {
                return $next($request);
            }
        }

        $started = hrtime(true);
        $this->recorder->startTrace($request->header('X-Trace-Id'));

        try {
            $response = $next($request);
            $this->recorder->record('request', $request->method().' '.($request->route()?->uri() ?? $request->path()), [
                'method' => $request->method(),
                'route' => $request->route()?->uri(),
            ], intdiv(hrtime(true) - $started, 1000), (string) $response->getStatusCode());

            return $response;
        } catch (Throwable $exception) {
            $this->recorder->exception($exception, ['route' => $request->route()?->uri()]);
            throw $exception;
        }
    }

    public function terminate(): void
    {
        $this->recorder->flush();
    }
}

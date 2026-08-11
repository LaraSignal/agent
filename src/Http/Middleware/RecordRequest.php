<?php

namespace LaraSignal\Agent\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use LaraSignal\Agent\Recorder;
use Symfony\Component\HttpFoundation\Response;

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

        $response = $next($request);
        $route = $request->route();
        $this->recorder->record('request', $request->method().' '.($request->route()?->uri() ?? $request->path()), [
            'method' => $request->method(),
            'route' => $route?->uri(),
            'path' => '/'.ltrim($request->path(), '/'),
            'url' => $request->getSchemeAndHttpHost().'/'.ltrim($request->path(), '/'),
            'query' => $request->query(),
            'request_headers' => $request->headers->all(),
            'response_headers' => $response->headers->all(),
            'middleware' => $route?->gatherMiddleware() ?? [],
            'controller' => $route?->getActionName(),
            'response_size' => strlen((string) $response->getContent()),
            'peak_memory_bytes' => memory_get_peak_usage(true),
        ], intdiv(hrtime(true) - $started, 1000), (string) $response->getStatusCode());

        if ($response->getStatusCode() === 429) {
            $this->recorder->record('security', 'Rate limit exceeded', [
                'phase' => 'rate_limited',
                'method' => $request->method(),
                'route' => $route?->uri(),
                'retry_after' => $response->headers->get('Retry-After'),
            ], status: 'failed', severity: 'warning');
        }

        return $response;
    }

    public function terminate(): void
    {
        $this->recorder->flush();
    }
}

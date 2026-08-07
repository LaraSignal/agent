<?php

namespace LaraSignal\Agent\Facades;

use Illuminate\Support\Facades\Facade;
use LaraSignal\Agent\Recorder;

/**
 * @method static \LaraSignal\Agent\Recorder filter(\Closure $callback)
 * @method static \LaraSignal\Agent\Recorder context(array $context)
 * @method static mixed withContext(array $context, \Closure $callback)
 * @method static \LaraSignal\Agent\Recorder tag(string ...$tags)
 * @method static \LaraSignal\Agent\Recorder user(mixed $user)
 * @method static void event(string $name, array $attributes = [])
 * @method static mixed measure(string $name, \Closure $callback)
 * @method static string startTrace(?string $traceId = null)
 * @method static void record(string $type, string $name, array $attributes = [], ?int $durationUs = null, ?string $status = null, bool $force = false)
 * @method static void exception(\Throwable $exception, array $context = [])
 * @method static void flush()
 *
 * @see Recorder
 */
final class LaraSignal extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Recorder::class;
    }
}

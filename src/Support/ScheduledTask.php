<?php

declare(strict_types=1);

namespace Codeva\Watch\Support;

use Closure;
use Illuminate\Console\Application;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use ReflectionClass;
use ReflectionFunction;
use WeakMap;

use function base_path;
use function in_array;
use function is_array;
use function is_object;
use function is_string;
use function preg_replace;
use function sprintf;
use function str_replace;

/**
 * The stable identity of a scheduled task, shared by records, heartbeats and
 * the schedule sync so that all three name a task the same way.
 *
 * @internal
 */
final class ScheduledTask
{
    /** @var WeakMap<Event, int>|null */
    private static ?WeakMap $grace = null;

    /** @var WeakMap<Event, true>|null */
    private static ?WeakMap $internal = null;

    public static function setGrace(Event $event, int $minutes): void
    {
        self::$grace ??= new WeakMap;
        self::$grace[$event] = $minutes;
    }

    public static function grace(Event $event, int $default): int
    {
        return self::$grace[$event] ?? $default;
    }

    /**
     * Watch's own tasks are kept out of the schedule sync and send no heartbeats,
     * so an app that runs no scheduler is not expected to run them.
     */
    public static function markInternal(Event $event): void
    {
        self::$internal ??= new WeakMap;
        self::$internal[$event] = true;
    }

    public static function isInternal(Event $event): bool
    {
        return isset(self::$internal[$event]);
    }

    public static function name(Event $event): string
    {
        $name = str_replace([
            Application::phpBinary(),
            Application::artisanBinary(),
        ], [
            'php',
            (string) preg_replace("#['\"]#", '', Application::artisanBinary()),
        ], $event->command ?? '');

        if ($event instanceof CallbackEvent) {
            $name = $event->getSummaryForDisplay();

            if (in_array($name, ['Closure', 'Callback'], true)) {
                $name = self::closureLocation($event);
            }
        }

        return $name;
    }

    private static function closureLocation(CallbackEvent $event): string
    {
        $callback = (new ReflectionClass($event))->getProperty('callback')->getValue($event);

        if ($callback instanceof Closure) {
            $function = new ReflectionFunction($callback);

            return sprintf(
                'Closure at: %s:%s',
                str_replace(base_path().DIRECTORY_SEPARATOR, '', $function->getFileName() ?: ''),
                $function->getStartLine()
            );
        }

        if (is_string($callback)) {
            return $callback;
        }

        if (is_array($callback)) {
            return is_string($callback[0]) ? $callback[0] : $callback[0]::class;
        }

        return is_object($callback) ? $callback::class : '';
    }
}

<?php

namespace Codeva\Watch;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Context;
use Symfony\Component\Console\Input\ArgvInput;

use function implode;
use function tap;
use function value;
use function version_compare;

/**
 * @internal
 */
final class Compatibility
{
    public static bool $terminatingEventExists = false;

    public static bool $cacheDurationCapturable = false;

    public static bool $cacheFailuresCapturable = false;

    public static bool $cacheStoreNameCapturable = false;

    public static bool $mailableClassNameCapturable = false;

    public static bool $queueNameCapturable = false;

    public static bool $firesFinishedAndFailedEventsForScheduledConsoleCommands = false;

    public static bool $contextExists = false;

    public static bool $queuedJobDurationCapturable = false;

    public static bool $subMinuteScheduledTasksSupported = false;

    public static bool $queryConnectionTypeCapturable = false;

    /**
     * @var array{
     *   watch_should_sample?: bool|null,
     *   watch_sample_rate?: float,
     *   watch_trace_id?: string|null,
     *   watch_user_id?: string,
     * }
     */
    public static array $context = [];

    public static function boot(Application $app): void
    {
        $version = $app->version();

        /**
         * @see https://github.com/laravel/framework/pull/49730
         * @see https://github.com/laravel/framework/pull/49754
         * @see https://github.com/laravel/framework/pull/49837
         * @see https://github.com/laravel/framework/releases/tag/v11.0.0
         */
        self::$contextExists =
        self::$queueNameCapturable =
        self::$cacheStoreNameCapturable =
            version_compare($version, '11.0.0', '>=');

        /**
         * @see https://github.com/laravel/framework/pull/51560
         * @see https://github.com/laravel/framework/releases/tag/v11.11.0
         */
        self::$cacheFailuresCapturable =
        self::$cacheDurationCapturable =
            version_compare($version, '11.11.0', '>=');

        /**
         * @see https://github.com/laravel/framework/pull/52259
         * @see https://github.com/laravel/framework/releases/tag/v11.18.0
         */
        self::$terminatingEventExists = version_compare($version, '11.18.0', '>=');

        /**
         * @see https://github.com/laravel/framework/pull/53042
         * @see https://github.com/laravel/framework/releases/tag/v11.27.0
         */
        self::$mailableClassNameCapturable = version_compare($version, '11.27.0', '>=');

        /**
         * @see https://github.com/laravel/framework/pull/55572
         * @see https://github.com/laravel/framework/releases/tag/v12.11.0
         * @see https://github.com/laravel/framework/releases/tag/v12.11.1
         * @see https://github.com/laravel/framework/pull/55624
         * @see https://github.com/laravel/framework/releases/tag/v12.18.0
         */
        self::$firesFinishedAndFailedEventsForScheduledConsoleCommands = version_compare($version, '12.11.0', '=') || version_compare($version, '12.18.0', '>=');

        /**
         * @see https://github.com/laravel/framework/pull/49722
         * @see https://github.com/laravel/framework/releases/tag/v10.42.0
         */
        self::$queuedJobDurationCapturable =
            version_compare($version, '10.42.0', '>=');

        /**
         * @see https://github.com/laravel/framework/pull/47279
         * @see https://github.com/laravel/framework/releases/tag/v10.15.0
         */
        self::$subMinuteScheduledTasksSupported =
            version_compare($version, '10.15.0', '>=');

        /**
         * @see https://github.com/laravel/framework/commit/6da5093aa672d26d0357b35
         * @see https://github.com/laravel/framework/releases/tag/v11.5.0
         */
        if (version_compare($version, '11.5.0', '<')) {
            Event::macro('tap', fn (callable $callable) => tap($this, $callable));
        }

        /**
         * @see https://github.com/laravel/framework/pull/58156
         * @see https://github.com/laravel/framework/releases/tag/v12.45.0
         */
        self::$queryConnectionTypeCapturable = version_compare($version, '12.45.0', '>=');
    }

    /**
     * @see https://github.com/symfony/symfony/pull/54347
     * @see https://github.com/symfony/console/releases/tag/v7.1.0-BETA1
     */
    public static function parseCommand(ArgvInput $input): string
    {
        return implode(' ', $input->getRawTokens());
    }

    public static function addSamplingToContext(bool $sample): void
    {
        self::addHiddenContext('watch_should_sample', $sample);
    }

    public static function removeSamplingFromContext(): void
    {
        self::addHiddenContext('watch_should_sample', null);
    }

    /**
     * @template T of bool|null
     *
     * @param  T  $default
     * @return (T is bool ? bool : bool|null)
     */
    public static function getSamplingFromContext(?bool $default = true)
    {
        $context = self::getHiddenContext('watch_should_sample', $default);

        if ($context === null) {
            return null;
        }

        return (bool) $context;
    }

    public static function addSampleRateToContext(float $rate): void
    {
        self::addHiddenContext('watch_sample_rate', $rate);
    }

    public static function getSampleRateFromContext(): float
    {
        return (float) self::getHiddenContext('watch_sample_rate', 1.0);
    }

    public static function addTraceIdToContext(string $trace): void
    {
        self::addHiddenContext('watch_trace_id', $trace);
    }

    public static function getTraceIdFromContext(mixed $default = null): mixed
    {
        return self::getHiddenContext('watch_trace_id', $default);
    }

    public static function addUserIdToContext(string $id): void
    {
        self::addHiddenContext('watch_user_id', $id);
    }

    public static function getUserIdFromContext(): string
    {
        return (string) self::getHiddenContext('watch_user_id');
    }

    /**
     * @see https://github.com/laravel/framework/pull/49730
     * @see https://github.com/laravel/framework/releases/tag/v11.0.0
     *
     * @param  'watch_trace_id'|'watch_should_sample'|'watch_sample_rate'|'watch_user_id'  $key
     */
    private static function addHiddenContext(string $key, mixed $value): void
    {
        if (! self::$contextExists) {
            self::$context[$key] = $value;

            return;
        }

        Context::addHidden($key, $value);
    }

    /**
     * @see https://github.com/laravel/framework/pull/49730
     * @see https://github.com/laravel/framework/releases/tag/v11.0.0
     *
     * @param  'watch_trace_id'|'watch_should_sample'|'watch_sample_rate'|'watch_user_id'  $key
     */
    private static function getHiddenContext(string $key, mixed $default = null): mixed
    {
        if (! self::$contextExists) {
            return self::$context[$key] ?? value($default);
        }

        return Context::getHidden($key) ?? value($default);
    }
}

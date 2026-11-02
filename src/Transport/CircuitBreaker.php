<?php

declare(strict_types=1);

namespace Codeva\Watch\Transport;

use function apcu_delete;
use function apcu_fetch;
use function apcu_store;
use function error_log;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function function_exists;
use function hash;
use function ini_get;
use function is_file;
use function is_float;
use function max;
use function microtime;
use function posix_getuid;
use function substr;
use function sys_get_temp_dir;
use function unlink;

/**
 * Stops the app from waiting on a dead agent: after one failure every send is
 * skipped for the cool-down. A static property covers Octane and queue workers;
 * APCu shares the state across FPM workers when it is available, and a small
 * file in the temp directory shares it when it is not (and across CLI runs).
 *
 * @internal
 */
final class CircuitBreaker
{
    private const string APCU_KEY = 'codeva_watch_circuit_open_until';

    private const int LOG_INTERVAL_SECONDS = 60;

    /** @var array<string, float> by breaker name */
    private static array $openUntil = [];

    /** @var array<string, float> by breaker name */
    private static array $lastLoggedAt = [];

    /**
     * @param  string  $name  one per destination, so an app's dead agent does
     *                        not stop its http fallback or another app's agent
     */
    public function __construct(
        private readonly int $coolDownSeconds = 30,
        private readonly string $name = 'agent',
    ) {
        //
    }

    public static function forAgent(string $address): self
    {
        return new self(name: 'agent-'.substr(hash('xxh128', $address), 0, 8));
    }

    public function isOpen(): bool
    {
        $now = microtime(true);

        if ((self::$openUntil[$this->name] ?? 0.0) > $now) {
            return true;
        }

        if (self::apcuEnabled()) {
            $until = apcu_fetch($this->apcuKey());

            if (is_float($until) && $until > $now) {
                self::$openUntil[$this->name] = $until;

                return true;
            }

            return false;
        }

        [$until] = self::readFile($this->name);

        if ($until > $now) {
            self::$openUntil[$this->name] = $until;

            return true;
        }

        return false;
    }

    public function open(string $reason): void
    {
        $now = microtime(true);
        $until = $now + $this->coolDownSeconds;

        self::$openUntil[$this->name] = $until;

        if (self::apcuEnabled()) {
            apcu_store($this->apcuKey(), $until, $this->coolDownSeconds);
        }

        $lastLoggedAt = max(self::$lastLoggedAt[$this->name] ?? 0.0, self::readFile($this->name)[1]);
        $shouldLog = $now - $lastLoggedAt >= self::LOG_INTERVAL_SECONDS;

        if ($shouldLog) {
            $lastLoggedAt = $now;
        }

        self::$lastLoggedAt[$this->name] = $lastLoggedAt;

        @file_put_contents(self::file($this->name), $until.' '.$lastLoggedAt, LOCK_EX);

        if ($shouldLog) {
            error_log("[codeva/laravel-watch] {$reason}; skipping sends for {$this->coolDownSeconds}s");
        }
    }

    /**
     * Forget this destination's failures, for diagnostics that must really send.
     */
    public function close(): void
    {
        unset(self::$openUntil[$this->name], self::$lastLoggedAt[$this->name]);

        if (is_file(self::file($this->name))) {
            @unlink(self::file($this->name));
        }

        if (self::apcuEnabled()) {
            apcu_delete($this->apcuKey());
        }
    }

    public static function reset(): void
    {
        foreach (self::$openUntil + self::$lastLoggedAt as $name => $_) {
            (new self(name: (string) $name))->close();
        }

        self::$openUntil = [];
        self::$lastLoggedAt = [];

        (new self)->close();
    }

    /**
     * Called on every send, so a missing file must stay cheap and silent.
     *
     * @return array{0: float, 1: float} [open until, last logged at]
     */
    private static function readFile(string $name): array
    {
        $file = self::file($name);
        $parts = explode(' ', is_file($file) ? (string) @file_get_contents($file) : '');

        return [(float) $parts[0], (float) ($parts[1] ?? 0)];
    }

    private static function file(string $name): string
    {
        return sys_get_temp_dir()."/codeva-watch-breaker-{$name}-".(function_exists('posix_getuid') ? posix_getuid() : 0);
    }

    private function apcuKey(): string
    {
        return self::APCU_KEY.':'.$this->name;
    }

    private static function apcuEnabled(): bool
    {
        return function_exists('apcu_fetch') && (PHP_SAPI !== 'cli' || ini_get('apc.enable_cli'));
    }
}

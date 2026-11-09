<?php

declare(strict_types=1);

namespace Codeva\Watch\Agent;

use Composer\InstalledVersions;
use OutOfBoundsException;

use function gethostname;
use function ltrim;
use function rtrim;
use function str_starts_with;

/** @internal */
final readonly class AgentConfig
{
    public const string VERSION = '1.0.0';

    public function __construct(
        public string $token,
        public string $serverUrl,
        public string $listen = '127.0.0.1:2408',
        public string $spoolPath = 'storage/watch-spool',
        public string $serverName = '',
        public string $version = self::VERSION,
        public int $maxBufferBytes = 6_000_000,
        public float $maxBufferSeconds = 10.0,
        public int $concurrentRequests = 5,
        public int $spoolCapBytes = 100_000_000,
        public float $requestTimeout = 10.0,
        public bool $verbose = false,
    ) {
        //
    }

    /**
     * The installed package version (e.g. "1.17.0"), or the built-in fallback
     * when Composer's runtime metadata is unavailable or reports a dev branch.
     */
    public static function installedVersion(): string
    {
        try {
            $version = InstalledVersions::getPrettyVersion('codeva/laravel-watch');
        } catch (OutOfBoundsException) {
            return self::VERSION;
        }

        if ($version === null || str_starts_with($version, 'dev-')) {
            return self::VERSION;
        }

        return ltrim($version, 'v');
    }

    /**
     * @param  array<string, mixed>  $env
     */
    public static function fromEnv(array $env, string $basePath, bool $verbose = false): self
    {
        return new self(
            token: (string) ($env['WATCH_TOKEN'] ?? ''),
            serverUrl: rtrim((string) ($env['WATCH_SERVER_URL'] ?? ''), '/'),
            listen: (string) ($env['WATCH_AGENT_LISTEN'] ?? '127.0.0.1:2408'),
            spoolPath: (string) ($env['WATCH_SPOOL_PATH'] ?? $basePath.'/storage/watch-spool'),
            serverName: (string) ($env['WATCH_SERVER_NAME'] ?? gethostname()),
            maxBufferSeconds: (float) ($env['WATCH_AGENT_BUFFER_SECONDS'] ?? 10.0),
            verbose: $verbose,
        );
    }
}

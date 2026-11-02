<?php

declare(strict_types=1);

namespace Codeva\Watch\Support;

use Illuminate\Support\Env;

use function gethostname;
use function is_scalar;

/**
 * Identifies the host from the environment, so no code changes per platform.
 *
 * @internal
 */
final class Platform
{
    private const array DEPLOY_KEYS = ['WATCH_DEPLOY', 'LARAVEL_CLOUD_DEPLOY_UUID', 'FORGE_DEPLOY_COMMIT', 'VAPOR_COMMIT_HASH'];

    /**
     * @param  callable(string): mixed  $env
     */
    public function __construct(private $env)
    {
        //
    }

    public static function fromEnvironment(): self
    {
        return new self(static fn (string $key): mixed => Env::get($key));
    }

    public function isVapor(): bool
    {
        return $this->has('VAPOR_SSM_PATH');
    }

    public function isCloud(): bool
    {
        return $this->has('LARAVEL_CLOUD');
    }

    public function deploy(): string
    {
        foreach (self::DEPLOY_KEYS as $key) {
            if ($this->has($key)) {
                return $this->get($key);
            }
        }

        return '';
    }

    /**
     * A Lambda hostname changes with every container, so Vapor reports the
     * function, which is what a server means there.
     */
    public function server(): string
    {
        if ($this->has('WATCH_SERVER_NAME')) {
            return $this->get('WATCH_SERVER_NAME');
        }

        if ($this->isVapor() && $this->has('AWS_LAMBDA_FUNCTION_NAME')) {
            return $this->get('AWS_LAMBDA_FUNCTION_NAME');
        }

        return (string) gethostname();
    }

    private function has(string $key): bool
    {
        return $this->get($key) !== '';
    }

    private function get(string $key): string
    {
        $value = ($this->env)($key);

        return is_scalar($value) ? (string) $value : '';
    }
}

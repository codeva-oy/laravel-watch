<?php

namespace Codeva\Watch;

use Codeva\Watch\Contracts\Ingest;
use Codeva\Watch\Facades\Watch;
use Codeva\Watch\Hooks\GuzzleMiddleware;
use Codeva\Watch\State\CommandState;
use Codeva\Watch\State\RequestState;
use Codeva\Watch\Support\Uuid;
use Codeva\Watch\Transport\SampleRate;
use Illuminate\Contracts\Auth\Authenticatable;
use Throwable;
use WeakMap;

/**
 * @template TState of RequestState|CommandState
 */
final class Core
{
    use Concerns\CapturesState,
        Concerns\RedactsRecords,
        Concerns\RejectsRecords;

    /**
     * @internal
     *
     * @var null|(callable(Authenticatable): array{id: mixed, name?: mixed, username?: mixed})
     */
    public $userDetailsResolver = null;

    /**
     * @param  TState  $executionState
     * @param  array{
     *     enabled: bool,
     *     sampling: array{
     *         requests: float,
     *         commands: float,
     *         exceptions: float,
     *         scheduled_tasks: float,
     *         jobs: float,
     *     },
     *     filtering: array{
     *         ignore_cache_events: bool,
     *         ignore_mail: bool,
     *         ignore_notifications: bool,
     *         ignore_outgoing_requests: bool,
     *         ignore_queries: bool,
     *         ignore_logs: bool,
     *     },
     * }  $config
     */
    public function __construct(
        /** @internal */
        public Ingest $ingest,
        /** @internal */
        public SensorManager $sensor,
        /** @internal */
        public RequestState|CommandState $executionState,
        /** @internal */
        public Clock $clock,
        /** @internal */
        public Uuid $uuid,
        /** @internal */
        public array $config,
        /** @internal */
        public SampleRate $sampleRate = new SampleRate,
    ) {
        $this->routesWithMiddlewareRegistered = new WeakMap;
        $this->scheduledTasksSampleRates = new WeakMap;
    }

    /**
     * @api
     */
    public function user(callable $callback): void
    {
        $this->userDetailsResolver = $callback;
    }

    /**
     * @api
     */
    public function guzzleMiddleware(): callable
    {
        return new GuzzleMiddleware($this);
    }

    /**
     * @api
     */
    public function digest(): void
    {
        $this->finishExecution();
    }

    /**
     * @internal
     *
     * @return $this
     */
    public function finishExecution(): self
    {
        try {
            if (! $this->sampling) {
                $this->ingest->flush();
            }

            foreach ($this->pendingExceptions as $record) {
                $this->ingest->write($record);
            }

            if ($this->sampling || $this->pendingExceptions !== []) {
                $this->ingest->digest();
            }

            $this->pendingExceptions = [];
        } catch (Throwable $e) {
            Watch::unrecoverableExceptionOccurred($e);
        }

        return $this;
    }

    /**
     * @api
     *
     * @param  array<mixed>  $payload
     */
    public function record(string $type, array $payload): void
    {
        $this->ingest->writeNow([
            't' => $type,
            'v' => 1,
            'timestamp' => $this->clock->microtime(),
            'payload' => $payload,
        ]);
    }

    /**
     * @internal
     */
    public function enabled(): bool
    {
        return $this->config['enabled'];
    }
}

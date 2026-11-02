<?php

namespace Codeva\Watch\Concerns;

use Codeva\Watch\Records\CacheEvent;
use Codeva\Watch\Records\Command;
use Codeva\Watch\Records\Exception;
use Codeva\Watch\Records\Mail;
use Codeva\Watch\Records\OutgoingRequest;
use Codeva\Watch\Records\Query;
use Codeva\Watch\Records\Request;

/**
 * @internal
 */
trait RedactsRecords
{
    /**
     * @var list<callable(Exception): bool>
     */
    private array $redactExceptionCallbacks = [];

    /**
     * @var list<callable(CacheEvent): bool>
     */
    private array $redactCacheEventCallbacks = [];

    /**
     * @var list<callable(Command): bool>
     */
    private array $redactCommandCallbacks = [];

    /**
     * @var list<callable(Mail): bool>
     */
    private array $redactMailCallbacks = [];

    /**
     * @var list<callable(OutgoingRequest): bool>
     */
    private array $redactOutgoingRequestCallbacks = [];

    /**
     * @var list<callable(Query): bool>
     */
    private array $redactQueryCallbacks = [];

    /**
     * @var list<callable(Request): bool>
     */
    private array $redactRequestCallbacks = [];

    /**
     * @api
     *
     * @param  callable(Exception): bool  $callback
     */
    public function redactExceptions(callable $callback): void
    {
        $this->redactExceptionCallbacks[] = $callback;
    }

    /**
     * @api
     *
     * @param  callable(CacheEvent): bool  $callback
     */
    public function redactCacheEvents(callable $callback): void
    {
        $this->redactCacheEventCallbacks[] = $callback;
    }

    /**
     * @api
     *
     * @param  callable(Command): bool  $callback
     */
    public function redactCommands(callable $callback): void
    {
        $this->redactCommandCallbacks[] = $callback;
    }

    /**
     * @api
     *
     * @param  callable(Mail): bool  $callback
     */
    public function redactMail(callable $callback): void
    {
        $this->redactMailCallbacks[] = $callback;
    }

    /**
     * @api
     *
     * @param  callable(OutgoingRequest): bool  $callback
     */
    public function redactOutgoingRequests(callable $callback): void
    {
        $this->redactOutgoingRequestCallbacks[] = $callback;
    }

    /**
     * @api
     *
     * @param  callable(Query): bool  $callback
     */
    public function redactQueries(callable $callback): void
    {
        $this->redactQueryCallbacks[] = $callback;
    }

    /**
     * @api
     *
     * @param  callable(Request): bool  $callback
     */
    public function redactRequests(callable $callback): void
    {
        $this->redactRequestCallbacks[] = $callback;
    }
}

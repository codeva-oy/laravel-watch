<?php

namespace Codeva\Watch\Concerns;

use Codeva\Watch\Records\CacheEvent;
use Codeva\Watch\Records\Mail;
use Codeva\Watch\Records\Notification;
use Codeva\Watch\Records\OutgoingRequest;
use Codeva\Watch\Records\Query;
use Codeva\Watch\Records\QueuedJob;

/**
 * @internal
 */
trait RejectsRecords
{
    /**
     * @var list<callable(CacheEvent): bool>
     */
    private array $rejectCacheEventCallbacks = [];

    private bool $captureDefaultVendorCacheKeys = false;

    /**
     * @var list<string>
     */
    private array $rejectCacheKeys = [];

    /**
     * @var list<callable(Mail): bool>
     */
    private array $rejectMailCallbacks = [];

    /**
     * @var list<callable(Notification): bool>
     */
    private array $rejectNotificationCallbacks = [];

    /**
     * @var list<callable(OutgoingRequest): bool>
     */
    private array $rejectOutgoingRequestCallbacks = [];

    /**
     * @var list<callable(Query): bool>
     */
    private array $rejectQueryCallbacks = [];

    /**
     * @var list<callable(QueuedJob): bool>
     */
    private array $rejectQueuedJobCallbacks = [];

    /**
     * @api
     *
     * @param  callable(CacheEvent): bool  $callback
     */
    public function rejectCacheEvents(callable $callback): void
    {
        $this->rejectCacheEventCallbacks[] = $callback;
    }

    /**
     * @api
     *
     * @param  list<string>  $keys
     */
    public function rejectCacheKeys(array $keys): void
    {
        $this->rejectCacheKeys = [
            ...$this->rejectCacheKeys,
            ...$keys,
        ];
    }

    /**
     * @api
     */
    public function captureDefaultVendorCacheKeys(bool $capture = true): void
    {
        $this->captureDefaultVendorCacheKeys = $capture;
    }

    /**
     * @api
     *
     * @return list<string>
     */
    public static function defaultVendorCacheKeys(): array
    {
        return [
            '/(^laravel_vapor_job_attemp(t?)s:)/',
            '/^illuminate:(?!cache:flexible:created:)/',
            '/^framework\/schedule/',
            '/^laravel:pulse:/',
            '/^laravel:reverb:/',
            '/^nova/',
            '/^telescope:/',
            '/^livewire-checksum-failures:/',
        ];
    }

    /**
     * @api
     *
     * @param  callable(Mail): bool  $callback
     */
    public function rejectMail(callable $callback): void
    {
        $this->rejectMailCallbacks[] = $callback;
    }

    /**
     * @api
     *
     * @param  callable(Notification): bool  $callback
     */
    public function rejectNotifications(callable $callback): void
    {
        $this->rejectNotificationCallbacks[] = $callback;
    }

    /**
     * @api
     *
     * @param  callable(OutgoingRequest): bool  $callback
     */
    public function rejectOutgoingRequests(callable $callback): void
    {
        $this->rejectOutgoingRequestCallbacks[] = $callback;
    }

    /**
     * @api
     *
     * @param  callable(Query): bool  $callback
     */
    public function rejectQueries(callable $callback): void
    {
        $this->rejectQueryCallbacks[] = $callback;
    }

    /**
     * @api
     *
     * @param  callable(QueuedJob): bool  $callback
     */
    public function rejectQueuedJobs(callable $callback): void
    {
        $this->rejectQueuedJobCallbacks[] = $callback;
    }
}

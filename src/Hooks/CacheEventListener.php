<?php

namespace Codeva\Watch\Hooks;

use Codeva\Watch\Core;
use Codeva\Watch\State\CommandState;
use Codeva\Watch\State\RequestState;
use Illuminate\Cache\Events\CacheEvent;
use Throwable;

/**
 * @internal
 */
final class CacheEventListener
{
    /**
     * @param  Core<RequestState|CommandState>  $watch
     */
    public function __construct(
        private Core $watch,
    ) {
        //
    }

    public function __invoke(CacheEvent $event): void
    {
        try {
            $this->watch->cacheEvent($event);
        } catch (Throwable $e) {
            $this->watch->report($e, handled: true);
        }
    }
}

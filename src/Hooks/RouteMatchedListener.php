<?php

namespace Codeva\Watch\Hooks;

use Codeva\Watch\Core;
use Codeva\Watch\State\RequestState;
use Illuminate\Routing\Events\RouteMatched;
use Throwable;

/**
 * @internal
 */
final class RouteMatchedListener
{
    /**
     * @param  Core<RequestState>  $watch
     */
    public function __construct(
        private Core $watch,
    ) {
        //
    }

    public function __invoke(RouteMatched $event): void
    {
        try {
            $this->watch->attachMiddlewareToRoute($event->route);
        } catch (Throwable $e) {
            $this->watch->report($e, handled: true);
        }
    }
}

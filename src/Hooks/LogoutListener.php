<?php

namespace Codeva\Watch\Hooks;

use Codeva\Watch\Core;
use Codeva\Watch\State\RequestState;
use Illuminate\Auth\Events\Logout;
use Throwable;

/**
 * @internal
 */
final class LogoutListener
{
    /**
     * @param  Core<RequestState>  $watch
     */
    public function __construct(
        private Core $watch,
    ) {
        //
    }

    public function __invoke(Logout $event): void
    {
        try {
            if ($event->user !== null) {
                $this->watch->remember($event->user);
            }
        } catch (Throwable $e) {
            $this->watch->report($e, handled: true);
        }
    }
}

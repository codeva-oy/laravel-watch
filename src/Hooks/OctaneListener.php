<?php

namespace Codeva\Watch\Hooks;

use Codeva\Watch\Core;
use Codeva\Watch\State\CommandState;
use Codeva\Watch\State\RequestState;
use Laravel\Octane\Events\RequestReceived;
use Throwable;

/**
 * @internal
 */
final class OctaneListener
{
    /**
     * @param  Core<RequestState|CommandState>  $watch
     */
    public function __construct(private Core $watch)
    {
        //
    }

    public function __invoke(RequestReceived $event): void
    {
        try {
            $this->watch->prepareForNextRequest();
        } catch (Throwable $e) {
            $this->watch->report($e);
        }
    }
}

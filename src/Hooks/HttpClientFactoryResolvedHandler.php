<?php

namespace Codeva\Watch\Hooks;

use Codeva\Watch\Core;
use Codeva\Watch\Records\OutgoingRequest;
use Codeva\Watch\State\CommandState;
use Codeva\Watch\State\RequestState;
use Illuminate\Http\Client\Factory;
use Throwable;

/**
 * @internal
 */
final class HttpClientFactoryResolvedHandler
{
    /**
     * @param  Core<RequestState|CommandState>  $watch
     */
    public function __construct(
        private Core $watch,
    ) {
        //
    }

    public function __invoke(Factory $factory): void
    {
        try {
            /**
             * @see OutgoingRequest
             */
            $factory->globalMiddleware($this->watch->guzzleMiddleware());
        } catch (Throwable $e) {
            $this->watch->report($e, handled: true);
        }
    }
}

<?php

namespace Codeva\Watch\Hooks;

use Codeva\Watch\Core;
use Codeva\Watch\State\CommandState;
use Codeva\Watch\State\RequestState;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * @internal
 */
final class GuzzleMiddleware
{
    /**
     * @param  Core<RequestState|CommandState>  $watch
     */
    public function __construct(
        private Core $watch,
    ) {
        //
    }

    /**
     * TODO record the failed responses as well.
     */
    public function __invoke(callable $handler): callable
    {
        if ($this->watch->config['filtering']['ignore_outgoing_requests'] || $this->watch->paused()) {
            return $handler;
        }

        return function (RequestInterface $request, array $options) use ($handler): mixed {
            try {
                $startMicrotime = $this->watch->clock->microtime();
            } catch (Throwable $e) {
                $this->watch->report($e, handled: true);

                return $handler($request, $options);
            }

            return $handler($request, $options)->then(function (ResponseInterface $response) use ($request, $startMicrotime): ResponseInterface {
                try {
                    $endMicrotime = $this->watch->clock->microtime();

                    $this->watch->outgoingRequest(
                        $startMicrotime, $endMicrotime,
                        $request, $response,
                    );
                } catch (Throwable $e) {
                    $this->watch->report($e, handled: true);
                }

                return $response;
            });
        };
    }
}

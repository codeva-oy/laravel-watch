<?php

namespace Codeva\Watch\Hooks;

use Closure;
use Codeva\Watch\Core;
use Codeva\Watch\ExecutionStage;
use Codeva\Watch\State\RequestState;
use Illuminate\Http\Request;
use Throwable;

/**
 * @internal
 */
final class RouteMiddleware
{
    /**
     * @param  Core<RequestState>  $watch
     */
    public function __construct(
        private Core $watch,
    ) {
        //
    }

    public function handle(Request $request, Closure $next): mixed
    {
        try {
            $this->watch->stage(ExecutionStage::Action);
        } catch (Throwable $e) {
            $this->watch->report($e, handled: true);
        }

        $response = $next($request);

        // If an exception occurs in the action phase, the usual
        // ResponsePrepared event is not fired. This fallback
        // ensures that we go to the AfterMiddleware stage.
        try {
            $this->watch->stage(ExecutionStage::AfterMiddleware);
        } catch (Throwable $e) {
            $this->watch->report($e, handled: true);
        }

        return $response;
    }
}

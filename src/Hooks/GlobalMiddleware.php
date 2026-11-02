<?php

namespace Codeva\Watch\Hooks;

use Closure;
use Codeva\Watch\Compatibility;
use Codeva\Watch\Core;
use Codeva\Watch\ExecutionStage;
use Codeva\Watch\Facades\Watch;
use Codeva\Watch\State\RequestState;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * @internal
 */
final class GlobalMiddleware
{
    private bool $hasHandledRequest = false;

    private bool $hasTerminated = false;

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
        if ($this->hasHandledRequest) {
            return $next($request);
        }

        $this->hasHandledRequest = true;

        try {
            $this->watch->configureRequestSampling();
        } catch (Throwable $e) {
            Watch::unrecoverableExceptionOccurred($e);
        }

        try {
            $this->watch->captureRequestPreview($request);
        } catch (Throwable $e) {
            $this->watch->report($e, handled: true);
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if ($this->hasTerminated || Compatibility::$terminatingEventExists) {
            return;
        }

        $this->hasTerminated = true;

        try {
            $this->watch->stage(ExecutionStage::Terminating);
        } catch (Throwable $e) {
            $this->watch->report($e, handled: true);
        }
    }
}

<?php

namespace Codeva\Watch\Hooks;

use Codeva\Watch\Core;
use Codeva\Watch\Records\Exception;
use Codeva\Watch\State\CommandState;
use Codeva\Watch\State\RequestState;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use Throwable;

use function array_filter;

/**
 * @internal
 */
final class ExceptionHandlerResolvedHandler
{
    /**
     * @param  Core<RequestState|CommandState>  $watch
     */
    public function __construct(
        private Core $watch,
    ) {
        //
    }

    public function __invoke(ExceptionHandler $handler): void
    {
        try {
            if ($handler instanceof Handler) {
                /**
                 * @see Exception
                 */
                // Run first: a reporter registered earlier may stop the rest, as Laravel
                // Cloud's exception tracking does, and Watch would never see the error.
                $watch = $handler->reportable(new ReportableHandler($this->watch));
                (function () use ($watch): void {
                    $this->reportCallbacks = [$watch, ...array_filter($this->reportCallbacks, fn ($callback) => $callback !== $watch)];
                })->call($handler);
            }
        } catch (Throwable $e) {
            $this->watch->report($e, handled: true);
        }
    }
}

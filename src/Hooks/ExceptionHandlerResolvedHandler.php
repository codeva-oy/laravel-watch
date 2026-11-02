<?php

namespace Codeva\Watch\Hooks;

use Codeva\Watch\Core;
use Codeva\Watch\Records\Exception;
use Codeva\Watch\State\CommandState;
use Codeva\Watch\State\RequestState;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use Throwable;

use function array_pop;
use function array_unshift;

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
                $handler->reportable(new ReportableHandler($this->watch));

                // Run first: a reporter registered earlier may stop the rest, as Laravel
                // Cloud's exception tracking does, and Watch would never see the error.
                (function (): void {
                    array_unshift($this->reportCallbacks, array_pop($this->reportCallbacks));
                })->call($handler);
            }
        } catch (Throwable $e) {
            $this->watch->report($e, handled: true);
        }
    }
}

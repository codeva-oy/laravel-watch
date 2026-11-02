<?php

namespace Codeva\Watch\Hooks;

use Carbon\Carbon;
use Codeva\Watch\Core;
use Codeva\Watch\ExecutionStage;
use Codeva\Watch\State\CommandState;
use Symfony\Component\Console\Input\InputInterface;
use Throwable;

/**
 * @internal
 */
final class CommandLifecycleIsLongerThanHandler
{
    /**
     * @param  Core<CommandState>  $watch
     */
    public function __construct(
        private Core $watch,
    ) {
        //
    }

    public function __invoke(Carbon $startedAt, InputInterface $input, int $status): void
    {
        try {
            $this->watch->stage(ExecutionStage::End);
        } catch (Throwable $e) {
            $this->watch->report($e, handled: true);
        }

        try {
            $this->watch->command($input, $status);
        } catch (Throwable $e) {
            $this->watch->report($e, handled: true);
        }

        $this->watch->finishExecution();
    }
}

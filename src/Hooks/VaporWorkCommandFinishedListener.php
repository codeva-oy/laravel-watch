<?php

namespace Codeva\Watch\Hooks;

use Codeva\Watch\Core;
use Codeva\Watch\State\CommandState;
use Illuminate\Console\Events\CommandFinished;

/**
 * @internal
 */
final class VaporWorkCommandFinishedListener
{
    /**
     * @param  Core<CommandState>  $watch
     */
    public function __construct(
        private Core $watch,
    ) {
        //
    }

    public function __invoke(CommandFinished $event): void
    {
        $this->watch->finishExecution();
    }
}

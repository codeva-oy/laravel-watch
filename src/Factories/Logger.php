<?php

namespace Codeva\Watch\Factories;

use Codeva\Watch\Core;
use Codeva\Watch\Hooks\LogHandler;
use Codeva\Watch\Hooks\LogRecordProcessor;
use Codeva\Watch\State\CommandState;
use Codeva\Watch\State\RequestState;
use DateTimeZone;
use Monolog\Logger as Monolog;
use Monolog\Processor\PsrLogMessageProcessor;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * @internal
 */
final class Logger
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
     * @param  array<string, mixed>&array{level: LogLevel::*}  $config
     */
    public function __invoke(array $config): LoggerInterface
    {
        return new Monolog(
            name: 'watch',
            handlers: [
                new LogHandler(
                    watch: $this->watch,
                    level: Monolog::toMonologLevel($config['level']),
                    // There is some unexpected behaviour in the framework when
                    // using a log stack that causes monolog processors to leak
                    // and apply their side-effects to other log handlers in
                    // the stack. Instead of passing processors to the monolog
                    // instance, as you would usually expect, we pass them to
                    // our handler to apply manually. This allows us to keep
                    // the side-effects of the processors isolated to
                    // Watch's handler when used in a stack of handlers.
                    processors: [
                        new LogRecordProcessor($this->watch, 'Y-m-d H:i:s.uP'),
                        new PsrLogMessageProcessor('Y-m-d H:i:s.uP'),
                    ],
                ),
            ],
            timezone: new DateTimeZone('UTC'),
        );
    }
}

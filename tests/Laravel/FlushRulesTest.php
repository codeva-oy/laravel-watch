<?php

namespace Laravel\Octane\Events;

if (! \class_exists(RequestTerminated::class)) {
    class RequestTerminated
    {
        //
    }
}

namespace Codeva\Watch\Tests\Laravel;

use Codeva\Watch\Contracts\Ingest;
use Codeva\Watch\Core;
use Codeva\Watch\Hooks\VaporWorkCommandFinishedListener;
use Codeva\Watch\Hooks\WorkerLifecycleListener;
use Codeva\Watch\RecordsBuffer;
use Codeva\Watch\Transport\AgentIngest;
use Codeva\Watch\Transport\CircuitBreaker;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Laravel\Octane\Events\RequestTerminated;
use Mockery;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

\it('flushes after every Octane request', function () {
    $core = \app(Core::class);
    $core->sample(1.0);

    $ingest = Mockery::mock(Ingest::class);
    $ingest->shouldReceive('digest')->once();
    $core->ingest = $ingest;

    \app('events')->dispatch(new RequestTerminated);
});

\it('flushes after each job of a queue worker and waits for the next', function () {
    $core = \app(Core::class);
    $core->sample(1.0);

    $ingest = Mockery::mock(Ingest::class)->shouldIgnoreMissing();
    $ingest->shouldReceive('digest')->once();
    $core->ingest = $ingest;

    (new WorkerLifecycleListener($core))(new Looping('database', 'default'));

    \expect($core->sampling())->toBeFalse();
});

\it('flushes a vapor:work job when the command finishes', function () {
    $core = \app(Core::class);
    $core->sample(1.0);

    $ingest = Mockery::mock(Ingest::class)->shouldIgnoreMissing();
    $ingest->shouldReceive('digest')->once();
    $core->ingest = $ingest;

    (new VaporWorkCommandFinishedListener($core))(new CommandFinished('vapor:work', new ArrayInput([]), new NullOutput, 0));
});

\it('resets per job on Vapor', function () {
    $core = \app(Core::class);
    \putenv('VAPOR_SSM_PATH=/x');

    try {
        $core->ingest->write(['t' => 'query', 'sql' => '']);

        $job = Mockery::mock(Job::class);
        $job->shouldReceive('resolveName')->andReturn('J');
        $job->shouldReceive('attempts')->andReturn(1);

        (new WorkerLifecycleListener($core))(new JobProcessing('database', $job));

        $inner = (new ReflectionProperty($core->ingest, 'inner'))->getValue($core->ingest);

        \expect($inner->records)->toBeEmpty();
    } finally {
        \putenv('VAPOR_SSM_PATH');
    }
});

\it('flushes a long command when the buffer fills', function () {
    $listener = \stream_socket_server('tcp://127.0.0.1:0');
    $address = \stream_socket_get_name($listener, false);
    $breaker = new CircuitBreaker(name: 'buffer-test');
    $breaker->close();

    $ingest = new AgentIngest($address, 'secret', new RecordsBuffer(3), $breaker, connectTimeout: 0.2, readTimeout: 0.2);

    $ingest->write(['t' => 'query']);
    $ingest->write(['t' => 'query']);
    \expect($ingest->buffer->count())->toBe(2);

    $ingest->write(['t' => 'query']);
    \expect($ingest->buffer->count())->toBe(0);

    $breaker->close();
});

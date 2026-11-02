<?php

use Codeva\Watch\Compatibility;
use Codeva\Watch\Core;
use Codeva\Watch\Hooks\ScheduledTaskHeartbeatListener;
use Codeva\Watch\Jobs\SendHeartbeat;
use Codeva\Watch\Support\ScheduledTask;
use Codeva\Watch\Support\ScheduleSync;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->listener = new ScheduledTaskHeartbeatListener(app(Core::class), app(Dispatcher::class), 'production');
    $this->task = app(Schedule::class)->command('inspire')->hourly();
});

function pings(): array
{
    return Queue::pushed(SendHeartbeat::class)->map(fn ($job) => $job->body)->values()->all();
}

it('queues a started ping, never sending inline', function () {
    ($this->listener)(new ScheduledTaskStarting($this->task));

    expect(pings()[0])->toMatchArray(['event' => 'started', 'cron' => '0 * * * *', 'duration_ms' => null, 'exit_code' => null, 'environment' => 'production', 'background' => false])
        ->and(pings()[0]['at'])->toBeFloat()
        ->and(pings()[0]['task'])->toContain('inspire');
});

it('queues a finished ping with duration and exit code', function () {
    $this->task->exitCode = 0;
    ($this->listener)(new ScheduledTaskFinished($this->task, 1.5));

    expect(pings()[0])->toMatchArray(['event' => 'finished', 'duration_ms' => 1500, 'exit_code' => 0]);
});

it('queues a failed ping', function () {
    $this->task->exitCode = 1;
    ($this->listener)(new ScheduledTaskFailed($this->task, new RuntimeException('x')));

    expect(pings()[0])->toMatchArray(['event' => 'failed', 'exit_code' => 1]);
});

it('does not send two pings for a task that failed', function () {
    $this->task->exitCode = 1;
    ($this->listener)(new ScheduledTaskFinished($this->task, 1.0));

    // Older frameworks fire only Finished, so there it has to carry the failure.
    Compatibility::$firesFinishedAndFailedEventsForScheduledConsoleCommands
        ? expect(pings())->toBeEmpty()
        : expect(pings()[0]['event'])->toBe('failed');
});

it('still reports a failed background task on its finished event', function () {
    $this->task->exitCode = 1;
    $this->task->runInBackground = true;
    ($this->listener)(new ScheduledTaskFinished($this->task, 1.0));

    expect(pings()[0]['event'])->toBe('failed');
});

it('swallows a broken queue', function () {
    $bus = Mockery::mock(Dispatcher::class);
    $bus->shouldReceive('dispatch')->andThrow(new RuntimeException('queue down'));

    (new ScheduledTaskHeartbeatListener(app(Core::class), $bus, 'production'))(new ScheduledTaskStarting($this->task));

    expect(true)->toBeTrue();
});

it('uses the grace set on the task, then the configured default', function () {
    config(['watch.schedule.grace_minutes' => 7]);
    $this->task->watchGrace(2);
    app(Schedule::class)->command('list')->daily();

    expect(array_column(app(ScheduleSync::class)->tasks(), 'grace_minutes'))->toBe([2, 7]);
});

it('stops grace from leaking between events', function () {
    ScheduledTask::setGrace($this->task, 9);

    expect(ScheduledTask::grace(app(Schedule::class)->command('list')->daily(), 5))->toBe(5);
});

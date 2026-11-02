<?php

use Codeva\Watch\Jobs\SendHeartbeat;
use Codeva\Watch\Support\ScheduleSync;
use Codeva\Watch\Transport\ServerClient;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Config\Repository as Config;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\Job;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/watch-heartbeat-'.uniqid();
    mkdir($this->dir);
    $this->port = freePort();
    $this->stub = startStub($this->dir, $this->port);
    $this->server = new ServerClient("http://127.0.0.1:{$this->port}");
    $this->cache = new Repository(new ArrayStore);
    $this->sync = new ScheduleSync(Mockery::mock(Schedule::class, ['events' => []]), $this->server, $this->cache, new Config(['watch' => ['token' => 't']]));
});

afterEach(function () {
    proc_terminate($this->stub, 9);
    @exec('rm -rf '.escapeshellarg($this->dir));
});

function heartbeat(): SendHeartbeat
{
    return new SendHeartbeat(['event' => 'finished', 'task' => 'a', 'cron' => '* * * * *', 'timezone' => 'UTC', 'at' => 1.5, 'duration_ms' => 3, 'exit_code' => 0, 'background' => false, 'server' => 's', 'deploy' => 'd', 'environment' => 'e']);
}

it('posts to the ping token of the task', function () {
    $this->cache->forever(ScheduleSync::TOKENS_KEY, ['a' => 'tok/en']);

    heartbeat()->handle($this->sync, $this->server);

    $request = stubRequests($this->dir)[0];

    expect($request['path'])->toBe('/api/heartbeat/tok%2Fen')
        ->and(json_decode($request['body'], true)['event'])->toBe('finished');
});

it('syncs once for an unknown task and gives up quietly without a token', function () {
    heartbeat()->handle($this->sync, $this->server);

    expect(array_column(stubRequests($this->dir), 'path'))->toBe(['/api/schedule-sync']);
});

it('releases for a retry when the server fails', function () {
    $this->cache->forever(ScheduleSync::TOKENS_KEY, ['a' => 'tok']);

    $job = heartbeat();
    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('attempts')->andReturn(1);
    $queueJob->shouldReceive('release')->once()->with(10);
    $job->setJob($queueJob);

    $job->handle($this->sync, new ServerClient('http://127.0.0.1:1'));
});

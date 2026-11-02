<?php

use Codeva\Watch\Transport\CircuitBreaker;

/**
 * Drives the real demo application over HTTP against a real agent and a stub
 * server, so these assertions are on what actually reached the wire.
 */
const DEMO = __DIR__.'/../../../demo';

function demoEnv(object $t, array $extra = []): array
{
    return [
        ...getenv(),
        'APP_ENV' => 'local', 'APP_DEBUG' => 'false',
        'WATCH_TOKEN' => 'secret', 'WATCH_AGENT_ADDRESS' => "127.0.0.1:{$t->agentPort}",
        'WATCH_SERVER_URL' => "http://127.0.0.1:{$t->serverPort}", 'WATCH_HEALTH_SECRET' => 'health-secret',
        'DEMO_OUTGOING_URL' => "http://user:pw@127.0.0.1:{$t->serverPort}", 'WATCH_DEPLOY' => 'abc123', 'WATCH_SERVER_NAME' => 'web-1',
        'QUEUE_CONNECTION' => 'database', 'LOG_STACK' => 'watch', 'LOG_CHANNEL' => 'stack',
        ...$extra,
    ];
}

function startDemo(object $t, array $env = []): void
{
    $t->demoPort = freePort();
    $t->demo = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$t->demoPort}", '-t', DEMO.'/public'], [1 => ['file', "{$t->dir}/demo.log", 'a'], 2 => ['file', "{$t->dir}/demo.log", 'a']], $p, DEMO, demoEnv($t, $env));
    expect(waitUntil(fn () => portOpen($t->demoPort)))->toBeTrue();
}

function hit(object $t, string $path, array $headers = []): array
{
    $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10, 'header' => implode("\r\n", $headers)]]);
    $body = file_get_contents("http://127.0.0.1:{$t->demoPort}{$path}", false, $context);

    return [(int) substr($http_response_header[0], 9, 3), $body];
}

function artisan(object $t, array $arguments, array $env = []): array
{
    $process = proc_open([PHP_BINARY, 'artisan', ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, DEMO, demoEnv($t, $env));
    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);

    return [proc_close($process), $output];
}

/** The records for one request, once its execution record has arrived. */
function recordsFor(object $t, callable $match): array
{
    $found = [];
    waitUntil(function () use ($t, $match, &$found) {
        $found = array_values(array_filter(accepted($t->dir), $match));

        return $found !== [];
    });

    return $found;
}

beforeEach(function () {
    if (! is_dir(DEMO.'/vendor')) {
        $this->markTestSkipped('The demo app is not installed.');
    }

    $this->dir = sys_get_temp_dir().'/watch-demo-'.uniqid();
    mkdir($this->dir);
    $this->serverPort = freePort();
    $this->agentPort = freePort();
    $this->stub = startStub($this->dir, $this->serverPort);
    $this->agentProcess = proc_open([PHP_BINARY, __DIR__.'/../../bin/watch-agent'], [1 => ['file', "{$this->dir}/agent.log", 'a'], 2 => ['file', "{$this->dir}/agent.log", 'a']], $p, null, [
        'WATCH_TOKEN' => 'secret', 'WATCH_SERVER_URL' => "http://127.0.0.1:{$this->serverPort}",
        'WATCH_AGENT_LISTEN' => "127.0.0.1:{$this->agentPort}", 'WATCH_SPOOL_PATH' => "{$this->dir}/spool", 'WATCH_AGENT_BUFFER_SECONDS' => '0.05',
    ]);
    expect(waitUntil(fn () => portOpen($this->agentPort)))->toBeTrue();
});

afterEach(function () {
    foreach (['demo', 'agentProcess', 'stub'] as $process) {
        if (is_resource($this->$process ?? null)) {
            proc_terminate($this->$process, 9);
        }
    }
    @exec('rm -rf '.escapeshellarg($this->dir));
});

it('sends a full, private trace for a normal request', function () {
    startDemo($this);
    expect(hit($this, '/demo')[0])->toBe(200);

    $records = recordsFor($this, fn ($r) => $r['t'] === 'request');
    $all = accepted($this->dir);
    $byType = fn ($type) => array_values(array_filter($all, fn ($r) => $r['t'] === $type));

    expect($records[0])->toMatchArray(['method' => 'GET', 'route_path' => '/demo', 'status_code' => 200, 'deploy' => 'abc123', 'server' => 'web-1', 'sample_rate' => 1, 'v' => 1, 'environment' => 'local'])
        ->not->toHaveKeys(['ip', 'headers', 'payload'])
        ->and($byType('query'))->not->toBeEmpty()
        ->and($byType('query')[0])->toHaveKey('group_hash')->not->toHaveKey('bindings')
        ->and($byType('cache_event')[0]['key'])->not->toContain('mark')
        ->and($byType('log'))->toHaveCount(1)
        ->and($byType('log')[0]['message'])->toBe('hello')
        ->and($byType('log')[0]['context'])->toBe(['email']);

    // A guest has no id at all: nothing derived from their IP or browser.
    expect(array_unique(array_column($all, 'user')))->toBe(['']);

    $wire = json_encode($all);
    expect($wire)->not->toContain('a@b.c')->not->toContain('mark@example.com');
});

it('honours WATCH_LOG_LEVEL and WATCH_IGNORE_LOGS', function () {
    startDemo($this, ['WATCH_LOG_LEVEL' => 'debug']);
    hit($this, '/demo');
    $logs = recordsFor($this, fn ($r) => $r['t'] === 'log');
    expect(array_column($logs, 'message'))->toContain('below the default level');
    proc_terminate($this->demo, 9);

    array_map('unlink', glob("{$this->dir}/accepted-*"));
    startDemo($this, ['WATCH_IGNORE_LOGS' => 'true']);
    hit($this, '/demo');
    recordsFor($this, fn ($r) => $r['t'] === 'request');
    expect(array_filter(accepted($this->dir), fn ($r) => $r['t'] === 'log'))->toBeEmpty();
});

it('carries the real rate of a Sample override on every record of the request', function () {
    startDemo($this, ['WATCH_SAMPLE_REQUESTS' => '0.0']);
    hit($this, '/sampled');
    recordsFor($this, fn ($r) => $r['t'] === 'request');

    expect(array_unique(array_column(accepted($this->dir), 'sample_rate')))->toBe([1]);
});

it('sends nothing for an unsampled request', function () {
    startDemo($this);
    hit($this, '/unsampled');
    hit($this, '/demo');
    recordsFor($this, fn ($r) => $r['t'] === 'request');

    expect(array_column(array_filter(accepted($this->dir), fn ($r) => $r['t'] === 'request'), 'route_path'))->toBe(['/demo']);
});

it('sends the exception and a minimal request from an unsampled request', function () {
    startDemo($this);
    expect(hit($this, '/unsampled-boom')[0])->toBe(500);

    $request = recordsFor($this, fn ($r) => $r['t'] === 'request')[0];
    $all = accepted($this->dir);

    expect($request)->toMatchArray(['minimal' => true, 'route_path' => '/unsampled-boom', 'status_code' => 500, 'sample_rate' => 0])
        ->not->toHaveKeys(['queries', 'bootstrap', 'peak_memory_usage'])
        ->and(array_column($all, 't'))->toEqualCanonicalizing(['exception', 'request'])
        ->and(array_values(array_filter($all, fn ($r) => $r['t'] === 'exception'))[0])
        ->toMatchArray(['class' => 'RuntimeException', 'sample_rate' => 1]);
});

it('fingerprints an exception by its normalised message and first application frame', function () {
    startDemo($this);
    hit($this, '/boom');
    $exception = recordsFor($this, fn ($r) => $r['t'] === 'exception')[0];
    $normalised = 'Order {num} failed';
    $expected = hash('xxh128', 'RuntimeException'.'routes/web.php:23'.$normalised);

    expect($exception['fingerprint'])->toBe($expected)
        ->and($exception)->not->toHaveKey('group_hash')
        ->and($exception['file'])->toBe('routes/web.php');
});

it('sends real traces without arguments, capped, and with source for app frames only', function () {
    startDemo($this);
    hit($this, '/deep');
    $exception = recordsFor($this, fn ($r) => $r['t'] === 'exception')[0];
    $trace = json_decode($exception['trace'], true);

    expect($trace)->toHaveCount(50);

    foreach ($trace as $frame) {
        expect($frame['source'] === '' || str_ends_with($frame['source'], '()'))->toBeTrue();
    }

    $withCode = array_filter($trace, fn ($frame) => $frame['code'] !== null);
    expect($withCode)->not->toBeEmpty();

    foreach ($withCode as $frame) {
        expect($frame['file'])->not->toStartWith('vendor/')->and(count($frame['code']))->toBeLessThanOrEqual(11);
    }
});

it('skips vendor frames when grouping and sends no vendor source', function () {
    startDemo($this);
    hit($this, '/boom-vendor');
    $exception = recordsFor($this, fn ($r) => $r['t'] === 'exception')[0];
    $trace = json_decode($exception['trace'], true);

    expect($trace[0]['file'])->toStartWith('vendor/')->and($trace[0]['code'])->toBeNull();

    $appFrame = array_values(array_filter($trace, fn ($f) => ! str_starts_with($f['file'], 'vendor/') && ! str_starts_with($f['file'], '[')))[0]['file'];

    expect($appFrame)->toStartWith('routes/web.php:')
        ->and($exception['fingerprint'])->toBe(hash('xxh128', $exception['class'].$appFrame.$exception['message']));
});

it('does not send query bindings inside a QueryException message', function () {
    startDemo($this);
    hit($this, '/boom-query');
    $exception = recordsFor($this, fn ($r) => $r['t'] === 'exception')[0];

    expect($exception['message'])->toContain('SQL: [hidden]')
        ->and(json_encode(accepted($this->dir)))->not->toContain('mark@example.com');
});

it('groups outgoing requests with ids normalised and no query string', function () {
    startDemo($this);
    expect(hit($this, '/outgoing')[0])->toBe(200);
    $outgoing = recordsFor($this, fn ($r) => $r['t'] === 'outgoing_request')[0];

    expect($outgoing)->toMatchArray(['method' => 'GET', 'host' => '127.0.0.1', 'path' => '/users/{id}/orders/{id}', 'status' => 200])
        ->toHaveKey('duration')->not->toHaveKeys(['url', 'status_code'])
        ->and($outgoing['group_hash'])->toBe(hash('xxh128', 'GET127.0.0.1/users/{id}/orders/{id}'))
        ->and(json_encode($outgoing))->not->toContain('secret')->not->toContain('user:pw');
});

it('falls back to http when the agent is down, and drops without it', function () {
    proc_terminate($this->agentProcess, 9);
    usleep(100_000);

    startDemo($this, ['WATCH_FALLBACK_HTTP' => 'true']);
    hit($this, '/demo');
    expect(recordsFor($this, fn ($r) => $r['t'] === 'request'))->not->toBeEmpty();

    $request = array_values(array_filter(stubRequests($this->dir), fn ($r) => $r['path'] === '/api/ingest'))[0];
    expect($request)->toMatchArray(['authorization' => 'Bearer secret', 'encoding' => 'gzip', 'agent' => 'http', 'server' => 'web-1']);
});

it('stands by while the breaker is open and comes back when it closes', function () {
    proc_terminate($this->agentProcess, 9);
    usleep(100_000);

    $breaker = CircuitBreaker::forAgent("127.0.0.1:{$this->agentPort}");

    startDemo($this);
    expect(hit($this, '/demo')[0])->toBe(200)
        ->and($breaker->isOpen())->toBeTrue();

    // In standby the app must behave exactly the same, facades included.
    expect(hit($this, '/demo')[0])->toBe(200)
        ->and(hit($this, '/boom')[0])->toBe(500)
        ->and(hit($this, '/sampled')[0])->toBe(200);

    expect(file_get_contents("{$this->dir}/demo.log"))->not->toContain('PHP Fatal')->not->toContain('PHP Warning');

    $breaker->close();
});

it('fills deploy from the platform and keeps WATCH_DEPLOY first', function () {
    startDemo($this, ['WATCH_DEPLOY' => '', 'FORGE_DEPLOY_COMMIT' => 'forge-sha']);
    hit($this, '/demo');
    expect(recordsFor($this, fn ($r) => $r['t'] === 'request')[0]['deploy'])->toBe('forge-sha');
});

it('delivers over the http transport with no agent configured', function () {
    startDemo($this, ['WATCH_TRANSPORT' => 'http', 'WATCH_AGENT_ADDRESS' => '127.0.0.1:1']);
    hit($this, '/boom');

    expect(recordsFor($this, fn ($r) => $r['t'] === 'exception'))->not->toBeEmpty();
});

it('queues http batches when asked to and delivers them from a worker', function () {
    startDemo($this, ['WATCH_TRANSPORT' => 'http', 'WATCH_HTTP_QUEUE' => 'true']);
    artisan($this, ['queue:clear', 'database']);
    hit($this, '/demo');

    expect(waitUntil(fn () => str_contains(artisan($this, ['queue:work', '--once', '--stop-when-empty', '--max-time=1'], ['WATCH_TRANSPORT' => 'http'])[1], 'SendBatch')))->toBeTrue();

    $records = recordsFor($this, fn ($r) => $r['t'] === 'request');

    expect($records)->toHaveCount(1)
        // The job itself must not generate a batch, or the queue never drains.
        ->and(array_filter(accepted($this->dir), fn ($r) => $r['t'] === 'job' && str_contains($r['name'] ?? '', 'SendBatch')))->toBeEmpty();
});

it('flushes a job per attempt from a real worker', function () {
    startDemo($this);
    artisan($this, ['queue:clear', 'database']);
    hit($this, '/dispatch');
    recordsFor($this, fn ($r) => $r['t'] === 'request');

    [$code] = artisan($this, ['queue:work', '--stop-when-empty']);
    expect($code)->toBe(0);

    $jobs = recordsFor($this, fn ($r) => $r['t'] === 'job' && ($r['status'] ?? '') === 'processed');
    expect($jobs[0])->toMatchArray(['name' => 'App\\Jobs\\DemoJob', 'execution_source' => 'job', 'sample_rate' => 1]);
});

it('serves the health endpoint only with the secret', function () {
    startDemo($this);

    expect(hit($this, '/watch/health')[0])->toBe(404)
        ->and(hit($this, '/watch/health', ['X-Watch-Health-Secret: wrong'])[0])->toBe(404);

    [$status, $body] = hit($this, '/watch/health', ['X-Watch-Health-Secret: health-secret']);
    $health = json_decode($body, true);

    expect($status)->toBe(200)
        ->and($health['finishedAt'])->toBeInt()
        ->and(array_column($health['checkResults'], 'name'))->toBe(['Database', 'Cache', 'QueueSize', 'FailedJobs', 'UsedDiskSpace', 'DebugMode', 'Schedule'])
        ->and($health['checkResults'][0])->toHaveKeys(['name', 'label', 'status', 'notificationMessage', 'shortSummary', 'meta'])
        ->and($health['checkResults'][0]['status'])->toBe('ok')
        ->and(array_column($health['checkResults'], 'status'))->not->toContain('crashed');
});

it('keeps the health route with route:cache on', function () {
    artisan($this, ['route:cache']);

    try {
        startDemo($this);
        expect(hit($this, '/watch/health', ['X-Watch-Health-Secret: health-secret'])[0])->toBe(200);
    } finally {
        artisan($this, ['route:clear']);
    }
});

it('runs watch:test against the agent, end to end', function () {
    [$code, $output] = artisan($this, ['watch:test']);

    expect($code)->toBe(0)->and($output)->toContain('delivered it to the server');

    $exception = recordsFor($this, fn ($r) => $r['t'] === 'exception')[0];
    expect($exception['message'])->toContain('test exception');
});

it('runs watch:test over the http transport', function () {
    [$code, $output] = artisan($this, ['watch:test'], ['WATCH_TRANSPORT' => 'http']);

    expect($code)->toBe(0)->and($output)->toContain('The server accepted');
});

it('reports a failing watch:test', function () {
    proc_terminate($this->agentProcess, 9);
    usleep(100_000);

    [$code, $output] = artisan($this, ['watch:test']);

    expect($code)->toBe(1)->and($output)->toContain('not delivered');
});

it('syncs the schedule and queues heartbeats from a real schedule:run', function () {
    artisan($this, ['queue:clear', 'database']);
    artisan($this, ['cache:clear']);

    [$code, $output] = artisan($this, ['watch:sync-schedule']);
    expect($code)->toBe(0)->and($output)->toContain('Synced 2 scheduled tasks');

    $sync = array_values(array_filter(stubRequests($this->dir), fn ($r) => $r['path'] === '/api/schedule-sync'))[0];
    expect($sync['authorization'])->toBe('Bearer secret')
        ->and(json_decode($sync['body'], true)['tasks'][0])->toBe(['name' => 'demo:ok', 'cron' => '* * * * *', 'timezone' => 'UTC', 'grace_minutes' => 3]);

    artisan($this, ['schedule:run']);

    // Nothing is sent inline: the pings are on the queue until a worker runs.
    expect(array_filter(stubRequests($this->dir), fn ($r) => str_starts_with($r['path'], '/api/heartbeat')))->toBeEmpty();

    artisan($this, ['queue:work', '--stop-when-empty']);

    $pings = array_values(array_filter(stubRequests($this->dir), fn ($r) => str_starts_with($r['path'], '/api/heartbeat')));
    $bodies = array_map(fn ($r) => json_decode($r['body'], true), $pings);

    expect(array_column($pings, 'path'))->toBe(['/api/heartbeat/ping0', '/api/heartbeat/ping0'])
        ->and(array_column($bodies, 'event'))->toBe(['started', 'finished'])
        ->and($bodies[1])->toHaveKeys(['task', 'cron', 'timezone', 'at', 'duration_ms', 'exit_code', 'background', 'server', 'deploy', 'environment']);

    // The demo's cache is real and shared: do not leave the stub's tokens in it.
    artisan($this, ['cache:clear']);
});

/*
 * On Vapor there is no agent: every batch becomes a SendBatch job on the app's own queue.
 * If Watch recorded those jobs, each would queue another and the queue would never drain,
 * billing Lambda invocations forever. This drives a real worker in Vapor mode and counts.
 */
describe('on Vapor, Watch never queues work for its own jobs', function () {
    $vapor = ['VAPOR_SSM_PATH' => '/watch-test', 'WATCH_TRANSPORT' => 'http'];

    // The http breaker is shared through a temp file across processes; the server-down
    // test opens it, which would make the next run's requests skip sending.
    beforeEach(fn () => (new CircuitBreaker(name: 'http'))->close());
    afterEach(fn () => (new CircuitBreaker(name: 'http'))->close());

    // Counted with Watch off, so the check itself queues nothing.
    $pending = fn (object $t): int => (int) trim(artisan($t, ['tinker', '--execute=echo DB::table("jobs")->count();'], ['WATCH_ENABLED' => 'false'])[1]);
    $batches = fn (object $t): int => is_file("{$t->dir}/hits") ? count(array_filter(explode("\n", file_get_contents("{$t->dir}/hits")))) : 0;

    // Runs the worker until the queue is empty; returns how many runs that took.
    $drain = function (object $t) use ($vapor, $pending): int {
        for ($run = 1; $run <= 6; $run++) {
            artisan($t, ['queue:work', 'database', '--stop-when-empty', '--max-time=20'], $vapor);

            if ($pending($t) === 0) {
                return $run;
            }
        }

        return $run;
    };

    it('drains the queue: one batch per request and per app job, then nothing', function () use ($vapor, $pending, $batches, $drain) {
        startDemo($this, $vapor);
        artisan($this, ['queue:clear', 'database'], ['WATCH_ENABLED' => 'false']);

        hit($this, '/demo');
        hit($this, '/dispatch');
        hit($this, '/boom');

        // Batches are queued after each response is sent: 3 SendBatch jobs and the DemoJob.
        // Wait before counting: the demo's SQLite database locks while it is being read.
        sleep(2);
        expect($pending($this))->toBe(4);

        $runs = $drain($this);
        $delivered = $batches($this);

        // Another worker pass must find nothing and send nothing.
        artisan($this, ['queue:work', 'database', '--stop-when-empty', '--max-time=20'], $vapor);

        expect($runs)->toBeLessThanOrEqual(2)       // requests and the app job, then the app job's own batch
            ->and($pending($this))->toBe(0)
            ->and($batches($this))->toBe($delivered)
            // 3 requests and the app job's own batch. Nothing for SendBatch.
            ->and($delivered)->toBe(4)
            ->and(recordsFor($this, fn ($r) => $r['t'] === 'request'))->not->toBeEmpty()
            ->and(array_filter(accepted($this->dir), fn ($r) => $r['t'] === 'job' && str_contains($r['name'] ?? '', 'SendBatch')))->toBeEmpty();
    });

    it('also drains while the Watch server is down', function () use ($vapor, $pending, $drain) {
        file_put_contents("{$this->dir}/mode", '503');
        startDemo($this, $vapor);
        artisan($this, ['queue:clear', 'database'], ['WATCH_ENABLED' => 'false']);

        hit($this, '/demo');
        hit($this, '/boom');
        sleep(2);
        expect($pending($this))->toBe(2);

        expect($drain($this))->toBeLessThanOrEqual(2)
            ->and($pending($this))->toBe(0);
    });
});

it('stops the agent on queue:restart, so a deploy can start it on the new release', function () {
    $port = freePort();
    $agent = proc_open([PHP_BINARY, 'artisan', 'watch:agent'], [1 => ['file', "{$this->dir}/artisan-agent.log", 'a'], 2 => ['file', "{$this->dir}/artisan-agent.log", 'a']], $pipes, DEMO, demoEnv($this, ['WATCH_AGENT_LISTEN' => "127.0.0.1:{$port}"]));
    expect(waitUntil(fn () => portOpen($port), 10))->toBeTrue();

    artisan($this, ['queue:restart']);

    expect(waitUntil(fn () => ! proc_get_status($agent)['running'], 15))->toBeTrue()
        ->and(file_get_contents("{$this->dir}/artisan-agent.log"))->toContain('Restart requested');
});

it('never fails a deploy hook when Watch is unreachable, unless --strict', function () {
    $env = ['WATCH_SERVER_URL' => 'http://127.0.0.1:1'];

    [$code, $output] = artisan($this, ['watch:sync-schedule'], $env);
    expect($code)->toBe(0)->and($output)->toContain('Watch server unreachable');

    [$strict] = artisan($this, ['watch:sync-schedule', '--strict'], $env);
    expect($strict)->toBe(1);

    [$noToken] = artisan($this, ['watch:sync-schedule'], ['WATCH_TOKEN' => '']);
    expect($noToken)->toBe(0);
});

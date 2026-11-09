<?php

use Codeva\Watch\RecordsBuffer;
use Codeva\Watch\Transport\AgentIngest;
use Codeva\Watch\Transport\CircuitBreaker;

function startAgent(object $t): void
{
    $t->agentLog = "{$t->dir}/agent.log";
    $t->agent = proc_open([PHP_BINARY, __DIR__.'/../../bin/watch-agent'], [1 => ['file', $t->agentLog, 'a'], 2 => ['file', $t->agentLog, 'a']], $pipes, null, [
        'WATCH_TOKEN' => 'secret', 'WATCH_SERVER_URL' => "http://127.0.0.1:{$t->serverPort}",
        'WATCH_AGENT_LISTEN' => "127.0.0.1:{$t->agentPort}", 'WATCH_SPOOL_PATH' => "{$t->dir}/spool",
        'WATCH_AGENT_BUFFER_SECONDS' => '0.1',
    ]);
    expect(waitUntil(fn () => portOpen($t->agentPort)))->toBeTrue();
}

function client(object $t, string $token = 'secret'): AgentIngest
{
    return new AgentIngest("127.0.0.1:{$t->agentPort}", $token, new RecordsBuffer(500), new CircuitBreaker);
}

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/watch-e2e-'.uniqid();
    mkdir($this->dir);
    $this->serverPort = freePort();
    $this->agentPort = freePort();
    $this->stub = startStub($this->dir, $this->serverPort);
    startAgent($this);
});

afterEach(function () {
    foreach (['agent', 'stub'] as $p) {
        if (is_resource($this->$p ?? null)) {
            proc_terminate($this->$p, 9);
        }
    }
});

it('delivers a batch gzipped with the bearer token', function () {
    $ingest = client($this);
    $ingest->write(['t' => 'query', 'n' => 1]);
    $ingest->write(['t' => 'query', 'n' => 2]);
    $ingest->digest();

    expect(waitUntil(fn () => count(accepted($this->dir)) === 2))->toBeTrue();

    [$auth, $encoding] = json_decode(explode("\n", file_get_contents("{$this->dir}/hits"))[0]);
    expect($auth)->toBe('Bearer secret')->and($encoding)->toBe('gzip');
});

it('spools while the server fails and delivers everything on recovery', function () {
    file_put_contents("{$this->dir}/mode", '500');

    client($this)->writeNow(['t' => 'exception', 'n' => 1]);
    expect(waitUntil(fn () => glob("{$this->dir}/spool/*.gz") !== []))->toBeTrue();

    file_put_contents("{$this->dir}/mode", '202');

    expect(waitUntil(fn () => count(accepted($this->dir)) === 1, 10))->toBeTrue()
        ->and(waitUntil(fn () => glob("{$this->dir}/spool/*.gz") === []))->toBeTrue();
});

it('honours 429 and later delivers', function () {
    file_put_contents("{$this->dir}/mode", '429');
    client($this)->writeNow(['t' => 'query', 'n' => 1]);

    expect(waitUntil(fn () => glob("{$this->dir}/spool/*.gz") !== []))->toBeTrue();
    file_put_contents("{$this->dir}/mode", '202');

    expect(waitUntil(fn () => count(accepted($this->dir)) === 1, 10))->toBeTrue();
});

it('splits a batch the server refuses as too large', function () {
    file_put_contents("{$this->dir}/mode", 'split');

    $ingest = client($this);
    foreach (range(1, 4) as $n) {
        $ingest->write(['t' => 'query', 'n' => $n]);
    }
    $ingest->digest();

    expect(waitUntil(fn () => count(accepted($this->dir)) === 4, 10))->toBeTrue()
        ->and(glob("{$this->dir}/spool/*.gz"))->toBe([]);
});

it('drops a batch the server will never accept instead of retrying it forever', function () {
    file_put_contents("{$this->dir}/mode", '422');
    client($this)->writeNow(['t' => 'query', 'n' => 1]);

    expect(waitUntil(fn () => str_contains((string) @file_get_contents($this->agentLog), 'dropping it')))->toBeTrue()
        ->and(glob("{$this->dir}/spool/*.gz"))->toBe([]);

    file_put_contents("{$this->dir}/mode", '202');
    client($this)->writeNow(['t' => 'query', 'n' => 2]);

    expect(waitUntil(fn () => count(accepted($this->dir)) === 1, 10))->toBeTrue()
        ->and(accepted($this->dir)[0]['n'] ?? null)->toBe(2);
});

it('flushes the buffer to the spool on SIGTERM and resends after restart', function () {
    proc_terminate($this->agent, 9);
    usleep(100_000);
    $this->serverDown = true;
    file_put_contents("{$this->dir}/mode", '503');

    $this->agentPort = freePort();
    $this->agent = proc_open([PHP_BINARY, __DIR__.'/../../bin/watch-agent'], [1 => ['file', "{$this->dir}/agent.log", 'a'], 2 => ['file', "{$this->dir}/agent.log", 'a']], $pipes, null, [
        'WATCH_TOKEN' => 'secret', 'WATCH_SERVER_URL' => "http://127.0.0.1:{$this->serverPort}",
        'WATCH_AGENT_LISTEN' => "127.0.0.1:{$this->agentPort}", 'WATCH_SPOOL_PATH' => "{$this->dir}/spool",
        'WATCH_AGENT_BUFFER_SECONDS' => '30',
    ]);
    expect(waitUntil(fn () => portOpen($this->agentPort)))->toBeTrue();

    client($this)->writeNow(['t' => 'query', 'n' => 7]);
    proc_terminate($this->agent, SIGTERM);

    expect(waitUntil(fn () => ! proc_get_status($this->agent)['running']))->toBeTrue()
        ->and(glob("{$this->dir}/spool/*.gz"))->toHaveCount(1);

    file_put_contents("{$this->dir}/mode", '202');
    startAgent($this);

    expect(waitUntil(fn () => count(accepted($this->dir)) === 1))->toBeTrue();
});

it('rejects a wrong token without forwarding it', function () {
    $ingest = client($this, 'wrong');
    $ingest->writeNow(['t' => 'query']);

    usleep(400_000);

    expect(accepted($this->dir))->toBe([])
        ->and(file_get_contents($this->agentLog))->toContain('bad token')
        ->and((new CircuitBreaker)->isOpen())->toBeTrue();
});

it('rejects a newer protocol with a clear log line', function () {
    $s = fsockopen('127.0.0.1', $this->agentPort);
    fwrite($s, '13:v2:abc1234:[]');
    stream_get_contents($s);

    usleep(100_000);

    expect(file_get_contents($this->agentLog))->toContain('protocol [v2]');
});

it('keeps answering while it compresses a full buffer', function () {
    $body = str_repeat('x', 900_000);

    foreach (range(1, 7) as $i) {
        client($this)->writeNow(['t' => 'log', 'n' => $i, 'message' => $body]);
    }

    $start = hrtime(true);
    client($this)->writeNow(['t' => 'log', 'n' => 8, 'message' => 'small']);

    expect((hrtime(true) - $start) / 1e6)->toBeLessThan(50)
        ->and(waitUntil(fn () => count(accepted($this->dir)) === 8))->toBeTrue()
        ->and(array_column(accepted($this->dir), 'n'))->toEqualCanonicalizing(range(1, 8));
});

it('survives the agent being down: no exception, breaker opens, later calls are instant', function () {
    proc_terminate($this->agent, 9);
    usleep(100_000);

    $ingest = client($this);
    $ingest->writeNow(['t' => 'query']);

    expect((new CircuitBreaker)->isOpen())->toBeTrue();

    $start = hrtime(true);
    $ingest->write(['t' => 'query']);
    $ingest->digest();
    $elapsedMs = (hrtime(true) - $start) / 1e6;

    expect($elapsedMs)->toBeLessThan(0.2);
});

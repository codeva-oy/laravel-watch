<?php

use Codeva\Watch\RecordsBuffer;
use Codeva\Watch\Transport\AgentIngest;
use Codeva\Watch\Transport\CircuitBreaker;
use Codeva\Watch\Transport\HttpIngest;
use Codeva\Watch\Transport\HttpSender;
use Codeva\Watch\Transport\ServerClient;

function sender(object $t, array $options = []): HttpSender
{
    return new HttpSender(
        server: new ServerClient($options['url'] ?? "http://127.0.0.1:{$t->port}"),
        token: 'secret',
        serverName: 'web-1',
        breaker: $t->breaker,
        queue: $options['queue'] ?? false,
        dispatch: function (string $payload) use ($t) {
            $t->queued[] = $payload;
        },
        runningInConsole: $options['console'] ?? false,
    );
}

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/watch-http-'.uniqid();
    mkdir($this->dir);
    $this->port = freePort();
    $this->stub = startStub($this->dir, $this->port);
    $this->breaker = new CircuitBreaker(name: 'http-test');
    $this->breaker->close();
    $this->queued = [];
});

afterEach(function () {
    proc_terminate($this->stub, 9);
    $this->breaker->close();
    @exec('rm -rf '.escapeshellarg($this->dir));
});

it('posts a gzipped batch with the headers of the agent', function () {
    expect(sender($this)->send([['t' => 'request', 'a' => 1]]))->toBeNull()
        ->and(accepted($this->dir))->toBe([['t' => 'request', 'a' => 1]])
        ->and(stubRequests($this->dir)[0])->toMatchArray(['path' => '/api/ingest', 'authorization' => 'Bearer secret', 'encoding' => 'gzip', 'server' => 'web-1']);
});

it('opens its breaker on a server error and then skips sending', function () {
    file_put_contents("{$this->dir}/mode", '500');

    expect(sender($this)->send([['t' => 'request']]))->toContain('500')
        ->and($this->breaker->isOpen())->toBeTrue()
        ->and(sender($this)->send([['t' => 'request']]))->toContain('breaker open')
        ->and(stubRequests($this->dir))->toHaveCount(1);
});

it('gives up within the timeout when the server hangs', function () {
    $hung = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($hung, false);

    $start = microtime(true);
    $error = sender($this, ['url' => "http://{$address}"])->send([['t' => 'request']]);

    expect($error)->not->toBeNull()->and(microtime(true) - $start)->toBeLessThan(1.5);
});

it('queues when asked to', function () {
    expect(sender($this, ['queue' => true])->send([['t' => 'request']]))->toBeNull()
        ->and($this->queued)->toHaveCount(1)
        ->and(json_decode(gzdecode(base64_decode($this->queued[0], true)), true))->toBe([['t' => 'request']])
        ->and(stubRequests($this->dir))->toBeEmpty();
});

it('sends exceptions from the CLI directly, even when queueing', function () {
    sender($this, ['queue' => true, 'console' => true])->send([['t' => 'exception']]);

    expect($this->queued)->toBeEmpty()->and(accepted($this->dir))->toHaveCount(1);
});

it('still queues CLI batches without an exception, and web exceptions', function () {
    sender($this, ['queue' => true, 'console' => true])->send([['t' => 'query']]);
    sender($this, ['queue' => true])->send([['t' => 'exception']]);

    expect($this->queued)->toHaveCount(2);
});

it('splits a batch too big for one queue message', function () {
    $records = array_map(fn ($i) => ['t' => 'log', 'message' => bin2hex(random_bytes(1000))], range(1, 300));

    sender($this, ['queue' => true])->send($records);

    $total = 0;
    foreach ($this->queued as $payload) {
        expect(strlen($payload))->toBeLessThanOrEqual(200_000);
        $total += count(json_decode(gzdecode(base64_decode($payload, true)), true));
    }

    expect($this->queued)->not->toHaveCount(1)->and($total)->toBe(300);
});

it('never throws when nothing is listening', function () {
    $ingest = new HttpIngest(new RecordsBuffer(10), sender($this, ['url' => 'http://127.0.0.1:1']));

    $ingest->write(['t' => 'query']);
    $ingest->digest();

    expect($ingest->lastDelivery()['delivered'])->toBeFalse()->and($this->breaker->isOpen())->toBeTrue();
});

it('falls back from the agent to http on failure and while the breaker is open', function () {
    $breaker = new CircuitBreaker(name: 'agent-fallback-test');
    $breaker->close();
    $ingest = new AgentIngest('127.0.0.1:1', 'secret', new RecordsBuffer(10), $breaker, fallback: sender($this));

    $ingest->writeNow(['t' => 'exception', 'n' => 1]);
    $ingest->writeNow(['t' => 'exception', 'n' => 2]);

    expect(array_column(accepted($this->dir), 'n'))->toEqualCanonicalizing([1, 2])
        ->and($ingest->lastDelivery())->toMatchArray(['delivered' => true, 'via' => 'http']);

    $breaker->close();
});

it('uses no HTTP client library', function () {
    $source = file_get_contents(__DIR__.'/../../src/Transport/ServerClient.php').file_get_contents(__DIR__.'/../../src/Transport/HttpSender.php');

    expect($source)->not->toContain('GuzzleHttp')->not->toContain('Illuminate\\Http\\Client');
});

<?php

use Codeva\Watch\LazyValue;
use Codeva\Watch\Transport\RecordShaper;
use Codeva\Watch\Transport\SampleRate;

beforeEach(fn () => $this->shaper = new RecordShaper('token', new SampleRate(0.25), exceptionRate: 0.5));

it('strips everything identifying from a request', function () {
    $record = $this->shaper->shape([
        't' => 'request', 'v' => 9, '_group' => 'abc', 'ip' => '1.2.3.4', 'headers' => '{"cookie":"x"}',
        'user' => '42', 'url' => 'https://a.test/watch/video?token=secret&id=9', 'execution_source' => 'request',
    ]);

    expect($record)->not->toHaveKeys(['ip', 'headers', '_group'])
        ->and($record['url'])->toBe('https://a.test/watch/video')
        ->and($record['user'])->not->toBe('42')->toHaveLength(32)
        ->and($record['group_hash'])->toBe('abc')
        ->and($record['v'])->toBe(1)
        ->and(json_encode($record['sample_rate']))->toBe('0.25');
});

it('never sends the user record', function () {
    expect($this->shaper->shape(['t' => 'user', 'id' => '1', 'name' => 'A', 'username' => 'a@b.c']))->toBeNull();
});

it('reduces mail to a recipient count', function () {
    $record = $this->shaper->shape(['t' => 'mail', 'subject' => 'Your account', 'to' => 2, 'cc' => 1, 'bcc' => 0]);

    expect($record)->not->toHaveKeys(['subject', 'to', 'cc', 'bcc'])->and($record['recipients'])->toBe(3);
});

it('hashes cache keys', function () {
    $record = $this->shaper->shape(['t' => 'cache-event', 'key' => 'user:mark@example.com']);

    expect($record['t'])->toBe('cache_event')->and($record['key'])->not->toContain('mark');
});

it('keeps only log context keys', function () {
    $record = $this->shaper->shape(['t' => 'log', 'message' => 'x', 'context' => '{"email":"a@b.c"}', 'extra' => '{"ip":"1"}']);

    expect($record['context'])->toBe(['email'])->and($record)->not->toHaveKey('extra');
});

it('applies the size caps without splitting multibyte characters', function () {
    $sql = $this->shaper->shape(['t' => 'query', 'sql' => str_repeat('ä', 10_000)])['sql'];
    $message = $this->shaper->shape(['t' => 'exception', 'message' => str_repeat('a', 9000)])['message'];
    $url = $this->shaper->shape(['t' => 'request', 'url' => str_repeat('a', 9000)])['url'];

    expect(strlen($sql))->toBeLessThanOrEqual(10_240)->and(mb_check_encoding($sql, 'UTF-8'))->toBeTrue()
        ->and(strlen($message))->toBe(4096)->and(strlen($url))->toBe(2048);
});

it('caps traces at 50 frames', function () {
    $record = $this->shaper->shape(['t' => 'exception', 'message' => '', 'trace' => json_encode(array_fill(0, 80, ['file' => 'a']))]);

    expect(json_decode($record['trace'], true))->toHaveCount(50);
});

it('maps job records', function () {
    expect($this->shaper->shape(['t' => 'job-attempt'])['t'])->toBe('job')
        ->and($this->shaper->shape(['t' => 'queued-job'])['status'])->toBe('queued');
});

it('hashes a lazy user id when it is serialised, not when it is written', function () {
    $id = '';
    $record = $this->shaper->shape(['t' => 'query', 'sql' => '', 'user' => new LazyValue(function () use (&$id) {
        return $id;
    })]);

    $id = '42';
    $json = json_decode(json_encode($record), true);

    expect($json['user'])->toHaveLength(32)->not->toBe('42');
});

it('fills the execution source and applies its sample rate on top-level records', function () {
    $record = $this->shaper->shape(['t' => 'request', 'url' => '']);

    expect($record['execution_source'])->toBe('request')->and(json_encode($record['sample_rate']))->toBe('0.25');
});

it('normalises what varies between occurrences of a message', function (string $message, string $expected) {
    expect(RecordShaper::normaliseMessage($message))->toBe($expected);
})->with([
    ['Order 12345 failed', 'Order {num} failed'],
    ['Price 12.50 too high', 'Price {num} too high'],
    ['User 9f8b2d1e-6f3a-4f43-9a5e-0d4a8c1b7e11 gone', 'User {uuid} gone'],
    ['Pointer 0xDEADBEEF and id deadbeef0123', 'Pointer {hex} and id {hex}'],
    ['No user mark.k+x@example.co.uk here', 'No user {email} here'],
    ['Column "name" and \'title\' and `age`', 'Column {str} and {str} and {str}'],
    ["Can't reach user's cart", "Can't reach user's cart"],
]);

it('fingerprints by class, first application frame and normalised message', function () {
    $trace = fn ($line) => json_encode([
        ['file' => 'vendor/laravel/framework/src/X.php:10', 'source' => '', 'code' => null],
        ['file' => '[internal function]', 'source' => 'f()', 'code' => null],
        ['file' => "app/Foo.php:{$line}", 'source' => 'g()', 'code' => null],
    ]);
    $print = fn ($message, $line = 7, $class = 'E') => $this->shaper->shape(['t' => 'exception', 'class' => $class, 'message' => $message, 'trace' => $trace($line)])['fingerprint'];

    expect($print('Order 1 failed'))->toBe(hash('xxh128', 'Eapp/Foo.php:7Order {num} failed'))
        ->toBe($print('Order 2 failed'))
        ->not->toBe($print('Order 1 failed', 8))
        ->not->toBe($print('Order 1 failed', 7, 'F'))
        ->not->toBe($print('Invoice 1 failed'));
});

it('finds the application frame past the cap on frames', function () {
    $frames = array_fill(0, 60, ['file' => 'vendor/x.php:1', 'source' => 'a()', 'code' => null]);
    $frames[55] = ['file' => 'app/Deep.php:3', 'source' => 'b()', 'code' => null];

    $record = $this->shaper->shape(['t' => 'exception', 'class' => 'E', 'message' => 'm', 'trace' => json_encode($frames)]);

    expect($record['fingerprint'])->toBe(hash('xxh128', 'Eapp/Deep.php:3m'));
});

it('falls back to the location for fatal errors, which have no trace', function () {
    $record = $this->shaper->shape(['t' => 'exception', 'class' => 'E', 'message' => 'm', 'file' => 'app/Foo.php', 'line' => 4, 'trace' => '', '_fingerprint' => null]);

    expect($record['fingerprint'])->toBe(hash('xxh128', 'Eapp/Foo.php:4m'))->and($record)->not->toHaveKey('_fingerprint');
});

it('uses a custom fingerprint when one is given', function () {
    expect($this->shaper->shape(['t' => 'exception', 'message' => 'm', '_fingerprint' => 'custom'])['fingerprint'])->toBe('custom');
});

it('strips arguments and vendor source from serialised frames', function () {
    $record = $this->shaper->shape(['t' => 'exception', 'message' => '', 'trace' => json_encode([
        ['file' => 'app/A.php:1', 'source' => 'A->b(string, App\User, resource (closed))', 'code' => ['1' => 'x']],
        ['file' => 'vendor/v/B.php:2', 'source' => '{closure:App\C::d():12}()', 'code' => ['2' => 'secret']],
    ])]);

    $trace = json_decode($record['trace'], true);

    expect($trace[0]['source'])->toBe('A->b()')->and($trace[0]['code'])->toBe(['1' => 'x'])
        ->and($trace[1]['source'])->toBe('{closure:App\C::d():12}()')->and($trace[1]['code'])->toBeNull();
});

it('reads the current rate when serialised, so overrides reach earlier records', function () {
    $rate = new SampleRate(1.0);
    $record = (new RecordShaper('token', $rate))->shape(['t' => 'query', 'sql' => '']);

    $rate->value = 0.1;

    expect(json_decode(json_encode($record), true)['sample_rate'])->toBe(0.1);
});

it('gives exceptions the exception rate', function () {
    expect($this->shaper->shape(['t' => 'exception', 'message' => ''])['sample_rate'])->toBe(0.5);
});

it('cuts an unsampled execution record down to the minimum', function () {
    $record = $this->shaper->shape(['t' => 'request', '_minimal' => true, 'url' => 'https://a.test/x?token=s', 'method' => 'GET', 'route_path' => '/x', 'status_code' => 500, 'queries' => 3, 'peak_memory_usage' => 1]);

    expect($record)->toHaveKeys(['t', 'method', 'route_path', 'status_code', 'sample_rate'])
        ->not->toHaveKeys(['queries', 'peak_memory_usage', '_minimal'])
        ->and($record['minimal'])->toBeTrue()->and($record['url'])->toBe('https://a.test/x');
});

it('removes query bindings from every message that can carry them', function () {
    $message = 'SQLSTATE[42S02]: not found (Connection: mysql, Host: h, SQL: select * from u where email = mark@a.test)';

    expect($this->shaper->shape(['t' => 'exception', 'message' => $message])['message'])->not->toContain('mark@')
        ->and($this->shaper->shape(['t' => 'log', 'message' => $message, 'context' => '{}'])['message'])->not->toContain('mark@')
        ->and($this->shaper->shape(['t' => 'request', 'url' => '', 'exception_preview' => substr($message, 0, 90)])['exception_preview'])->not->toContain('mark@');
});

it('emits the outgoing request fields of the spec and nothing of the url', function () {
    $record = $this->shaper->shape(['t' => 'outgoing-request', '_group' => 'x', 'method' => 'POST', 'host' => 'api.test', 'status_code' => 201, 'duration' => 5, 'url' => 'https://u:p@api.test/v1/orders/42/items/9f8b2d1e-6f3a-4f43-9a5e-0d4a8c1b7e11/a1?sig=abc']);

    expect($record)->toMatchArray(['t' => 'outgoing_request', 'path' => '/v1/orders/{id}/items/{id}/a1', 'status' => 201, 'group_hash' => hash('xxh128', 'POSTapi.test/v1/orders/{id}/items/{id}/a1')])
        ->not->toHaveKeys(['url', 'status_code']);
});

it('sends a command by name, without its arguments', function () {
    $record = $this->shaper->shape(['t' => 'command', 'name' => 'import', 'command' => 'artisan import --password=hunter2']);

    expect($record['name'])->toBe('import')->and($record)->not->toHaveKey('command');
});

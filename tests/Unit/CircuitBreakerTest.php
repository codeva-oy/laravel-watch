<?php

use Codeva\Watch\Transport\CircuitBreaker;

it('is shared between processes through the temp file', function () {
    (new CircuitBreaker)->open('test');

    $autoload = realpath(__DIR__.'/../../vendor/autoload.php');
    $other = shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg("require '{$autoload}'; echo (new Codeva\\Watch\\Transport\\CircuitBreaker)->isOpen() ? 'open' : 'closed';"));

    expect($other)->toBe('open');
});

it('closes after the cool-down', function () {
    (new CircuitBreaker(0))->open('test');

    usleep(10_000);

    expect((new CircuitBreaker)->isOpen())->toBeFalse();
});

it('checks an open breaker in under 0.2 ms from a cold process state', function () {
    (new CircuitBreaker)->open('test');

    $start = hrtime(true);
    for ($i = 0; $i < 100; $i++) {
        (new CircuitBreaker)->isOpen();
    }

    expect((hrtime(true) - $start) / 1e8)->toBeLessThan(0.2);
});

it('keeps destinations apart', function () {
    $agent = CircuitBreaker::forAgent('127.0.0.1:2408');
    $other = CircuitBreaker::forAgent('127.0.0.1:2409');

    $agent->close();
    $other->close();
    $agent->open('down');

    expect($agent->isOpen())->toBeTrue()->and($other->isOpen())->toBeFalse();

    $agent->close();

    expect($agent->isOpen())->toBeFalse();
});

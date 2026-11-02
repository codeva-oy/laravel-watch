<?php

use Codeva\Watch\Transport\BatchLimiter;

it('drops cache events first, then queries, never exceptions', function () {
    $records = [
        ['t' => 'cache-event', 'x' => str_repeat('a', 400)],
        ['t' => 'query', 'x' => str_repeat('b', 400)],
        ['t' => 'exception', 'x' => str_repeat('c', 400)],
    ];

    $types = fn (int $max) => array_column(json_decode((new BatchLimiter($max))->encode($records), true), 't');

    expect($types(10_000))->toBe(['cache-event', 'query', 'exception'])
        ->and($types(1000))->toBe(['query', 'exception'])
        ->and($types(500))->toBe(['exception'])
        ->and($types(10))->toBe(['exception']);
});

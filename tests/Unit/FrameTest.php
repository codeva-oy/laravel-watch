<?php

use Codeva\Watch\Agent\Frame;
use Codeva\Watch\Payload;

it('parses a frame split across chunks', function () {
    $wire = Payload::text('[{"t":"query"}]', 'abc1234')->pull();

    $frame = new Frame;
    foreach (str_split($wire, 3) as $chunk) {
        expect($frame->complete)->toBeFalse();
        $frame->append($chunk);
    }

    expect($frame->complete)->toBeTrue()
        ->and($frame->body)->toBe('[{"t":"query"}]')
        ->and($frame->auth)->toBe('abc1234')
        ->and($frame->versionIsValid())->toBeTrue();
});

it('keeps colons inside the body', function () {
    $frame = new Frame;
    $frame->append(Payload::text('[{"a":"b:c"}]', 'abc1234')->pull());

    expect($frame->body)->toBe('[{"a":"b:c"}]');
});

it('requires the HMAC when asked and accepts the short hash otherwise', function () {
    $frame = new Frame;
    $frame->append(Payload::text('[]', 'abc1234')->pull());

    expect($frame->isAuthorised('abc1234', 'secret', false))->toBeTrue()
        ->and($frame->isAuthorised('abc1234', 'secret', true))->toBeFalse();

    $signed = new Frame;
    $signed->append(Payload::text('[]', hash_hmac('sha256', '[]', 'secret'))->pull());

    expect($signed->isAuthorised('abc1234', 'secret', true))->toBeTrue();
});

it('flags a frame over the size limit', function () {
    $frame = new Frame;
    $frame->append('9000000:v1:abc1234:x');

    expect($frame->tooLarge)->toBeTrue();
});

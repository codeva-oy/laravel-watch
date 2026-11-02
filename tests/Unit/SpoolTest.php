<?php

use Codeva\Watch\Agent\Spool;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/watch-spool-'.uniqid();
});

it('returns batches oldest first and survives a restart', function () {
    $spool = new Spool($this->dir, 1000);
    $spool->put('first');
    $spool->put('second');

    $reopened = new Spool($this->dir, 1000);

    expect($reopened->bytes())->toBe(11)
        ->and($reopened->oldest()[1])->toBe('first');
});

it('deletes the oldest batches at the cap', function () {
    $spool = new Spool($this->dir, 10);
    $spool->put('aaaaaa');
    $spool->put('bbbbbb');

    expect($spool->oldest()[1])->toBe('bbbbbb')
        ->and($spool->bytes())->toBe(6);
});

<?php

use Codeva\Watch\Core;
use Codeva\Watch\Transport\NullIngest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// The package installed without WATCH_TOKEN, as in local development and CI.
it('records nothing without a token', function () {
    putenv('WATCH_TOKEN');
    $this->refreshApplication();

    $core = app(Core::class);
    $core->report(new RuntimeException('not configured'));
    DB::connection('testing')->select('select 1');
    Log::error('not configured');

    $inner = (new ReflectionProperty($core->ingest, 'inner'))->getValue($core->ingest);

    expect($core->enabled())->toBeFalse()
        ->and($inner)->toBeInstanceOf(NullIngest::class)
        ->and($inner->records)->toBe([]);
});

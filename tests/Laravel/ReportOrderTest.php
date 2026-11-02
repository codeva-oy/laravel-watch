<?php

use Codeva\Watch\Core;
use Codeva\Watch\Hooks\ExceptionHandlerResolvedHandler;
use Illuminate\Foundation\Exceptions\Handler;

// Laravel Cloud's exception tracking registers its reporter before any package and
// returns false from it, which stops every reporter after it.
it('reports exceptions even when an earlier reporter stops the rest', function () {
    $core = app(Core::class);
    $handler = new Handler(app());
    $handler->reportable(fn (Throwable $e) => false);

    (new ExceptionHandlerResolvedHandler($core))($handler);
    $handler->report(new RuntimeException('seen by Watch'));

    $inner = (new ReflectionProperty($core->ingest, 'inner'))->getValue($core->ingest);
    $types = array_column(json_decode(json_encode($inner->records), true), 't');

    expect($types)->toContain('exception');
});

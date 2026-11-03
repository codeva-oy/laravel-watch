<?php

use Codeva\Watch\Core;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;

beforeEach(function () {
    $this->core = app(Core::class);
});

function overrideRecords(object $t): array
{
    $inner = (new ReflectionProperty($t->core->ingest, 'inner'))->getValue($t->core->ingest);

    return json_decode(json_encode($inner->records), true);
}

// Scanners send POSTs with a _method like "GET;DROP": getMethod() throws on it.
// Laravel answers 400 and does not report it; Watch must not report it either.
it('does not report a malformed method override sent by a client', function () {
    $this->core->sample(1.0);
    Route::get('/probe', fn () => 'ok');

    $this->post('/probe', ['_method' => 'GET;DROP'])->assertStatus(400);

    $exceptions = array_filter(overrideRecords($this), fn ($r) => $r['t'] === 'exception');

    expect($exceptions)->toBeEmpty();
});

it('does not report request exceptions Laravel itself never reports', function () {
    $this->core->sample(1.0);

    $this->core->report(new SuspiciousOperationException('Invalid HTTP method override.'), handled: true);
    $this->core->report(new RuntimeException('still reported'));

    $messages = array_column(array_filter(overrideRecords($this), fn ($r) => $r['t'] === 'exception'), 'message');

    expect($messages)->toBe(['still reported']);
});

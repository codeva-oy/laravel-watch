<?php

use Illuminate\Support\Facades\Blade;

it('renders the Web Vitals script with the project key', function () {
    config(['watch.server_url' => 'https://watch.example/', 'watch.vitals.key' => 'wv_abcdefghijklmnopqrstuvwx', 'watch.vitals.sample' => 0.25]);

    expect(Blade::render('@watchVitals'))->toBe('<script src="https://watch.example/vitals.js" data-key="wv_abcdefghijklmnopqrstuvwx" data-sample="0.25" defer></script>');
});

it('renders nothing without a key, with a malformed key or when Watch is off', function () {
    config(['watch.server_url' => 'https://watch.example']);

    expect(Blade::render('@watchVitals'))->toBe('');

    config(['watch.vitals.key' => '"><script>alert(1)</script>']);
    expect(Blade::render('@watchVitals'))->toBe('');

    config(['watch.vitals.key' => 'wv_abcdefghijklmnopqrstuvwx', 'watch.enabled' => false]);
    expect(Blade::render('@watchVitals'))->toBe('');
});

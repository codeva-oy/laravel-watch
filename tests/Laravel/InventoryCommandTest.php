<?php

use Codeva\Watch\Core;
use Codeva\Watch\Hooks\ScheduledTaskHeartbeatListener;
use Codeva\Watch\Jobs\SendHeartbeat;
use Codeva\Watch\Support\Inventory;
use Codeva\Watch\Support\ScheduleSync;
use Codeva\Watch\Transport\ServerClient;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Queue;

function useInventoryServer(string $url): void
{
    config(['watch.server_url' => $url, 'watch.token' => 'secret', 'watch.environment.server_name' => 'web-1', 'app.env' => 'production']);

    app()->bind(ServerClient::class, fn () => new ServerClient($url, timeout: 5.0));
    app()->bind(Inventory::class, fn ($app) => new Inventory(
        $app->make(ServerClient::class),
        $app['config'],
        $app,
        __DIR__.'/../stubs/inventory',
        [__DIR__.'/../stubs/inventory/os-release'],
    ));
}

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/watch-inventory-'.uniqid();
    mkdir($this->dir);
});

afterEach(function () {
    if (isset($this->stub)) {
        proc_terminate($this->stub, 9);
    }

    @exec('rm -rf '.escapeshellarg($this->dir));
});

it('posts the inventory to the server with the project token', function () {
    $port = freePort();
    $this->stub = startStub($this->dir, $port);
    useInventoryServer("http://127.0.0.1:{$port}");

    $this->artisan('watch:inventory')
        ->expectsOutputToContain('Sent inventory: PHP '.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.'.'.PHP_RELEASE_VERSION.', Laravel '.app()->version().', 3 Composer and 8 npm packages.')
        ->assertSuccessful();

    $request = stubRequests($this->dir)[0];
    $body = json_decode($request['body'], true);

    expect($request)->toMatchArray(['path' => '/api/inventory', 'authorization' => 'Bearer secret', 'type' => 'application/json', 'accept' => 'application/json', 'encoding' => ''])
        ->and($body)->toMatchArray([
            'agent_version' => '1.0.0',
            'environment' => 'production',
            'server' => 'web-1',
            'framework' => ['name' => 'laravel', 'version' => app()->version()],
            'os' => ['name' => 'Ubuntu', 'version' => '26.04', 'pretty' => 'Ubuntu 26.04 LTS'],
            'kernel' => php_uname('r'),
        ])
        ->and($body['php']['extensions'])->toContain('json')
        ->and($body['composer'])->toHaveCount(3)
        ->and($body['npm'])->toHaveCount(8);
});

it('warns but succeeds when the server fails or cannot be reached', function () {
    $port = freePort();
    $this->stub = startStub($this->dir, $port);
    file_put_contents("{$this->dir}/mode", '500');
    useInventoryServer("http://127.0.0.1:{$port}");

    $this->artisan('watch:inventory')->expectsOutputToContain('Watch server answered 500.')->assertSuccessful();

    useInventoryServer('http://127.0.0.1:1');

    $this->artisan('watch:inventory')->expectsOutputToContain('Watch server unreachable.')->assertSuccessful();
    $this->artisan('watch:inventory', ['--strict' => true])->assertFailed();
});

it('warns but succeeds when Watch is not configured', function () {
    config(['watch.server_url' => null]);

    $this->artisan('watch:inventory')->expectsOutputToContain('WATCH_SERVER_URL and WATCH_TOKEN must be set')->assertSuccessful();
});

it('prints the inventory without sending it', function (string $option) {
    useInventoryServer('http://127.0.0.1:1');

    $this->artisan('watch:inventory', [$option => true])
        ->expectsOutputToContain('"name": "string-width"')
        ->doesntExpectOutputToContain('unreachable')
        ->assertSuccessful();
})->with(['--show', '--dry-run']);

it('schedules the inventory daily when Watch is configured', function () {
    putenv('WATCH_SERVER_URL=https://watch.test');
    $this->refreshApplication();
    config(['app.key' => 'base64:key']);

    $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'watch:inventory'));

    expect($event?->expression)->toBe(Inventory::dailyCron('base64:key'));

    // An app that runs no scheduler must not be expected to run it.
    Queue::fake();
    (new ScheduledTaskHeartbeatListener(app(Core::class), app(Dispatcher::class), 'production'))(new ScheduledTaskStarting($event));

    expect(app(ScheduleSync::class)->tasks())->toBe([])
        ->and(Queue::pushed(SendHeartbeat::class))->toBeEmpty();
})->after(fn () => putenv('WATCH_SERVER_URL'));

it('schedules nothing without a server or with the inventory turned off', function (array $env) {
    foreach ($env as $key => $value) {
        putenv("{$key}={$value}");
    }

    $this->refreshApplication();

    expect(collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains($event->command, 'watch:inventory')))->toBeEmpty();

    foreach (array_keys($env) as $key) {
        putenv($key);
    }
})->with([
    'no server' => [[]],
    'turned off' => [['WATCH_SERVER_URL' => 'https://watch.test', 'WATCH_INVENTORY' => 'false']],
]);

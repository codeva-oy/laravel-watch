<?php

beforeEach(function () {
    $this->env = base_path('.env');
    $this->original = file_exists($this->env) ? file_get_contents($this->env) : null;
    $this->config = config_path('watch.php');
});

afterEach(function () {
    $this->original === null ? @unlink($this->env) : file_put_contents($this->env, $this->original);
    @unlink($this->config);
});

it('publishes the config and writes the env keys', function () {
    file_put_contents($this->env, "APP_NAME=x\nLOG_CHANNEL=stack\nLOG_STACK=single\n");

    $this->artisan('watch:install', ['--token' => 'cw_$1abc', '--url' => 'https://watch.test/', '--no-interaction' => true])->assertSuccessful();

    $env = file_get_contents($this->env);

    expect($this->config)->toBeFile()
        ->and($env)->toContain('LOG_STACK=single,watch')
        ->toContain('WATCH_TOKEN=cw_$1abc')
        ->toContain('WATCH_SERVER_URL=https://watch.test')
        ->toMatch('/WATCH_HEALTH_SECRET=\S{40}/');
});

it('is idempotent and keeps an existing health secret and stack', function () {
    file_put_contents($this->env, "LOG_STACK=single,watch\nWATCH_HEALTH_SECRET=keep\nWATCH_TOKEN=old\n");

    $this->artisan('watch:install', ['--no-interaction' => true])->assertSuccessful();
    $this->artisan('watch:install', ['--no-interaction' => true])->assertSuccessful();

    $env = file_get_contents($this->env);

    expect($env)->toContain("LOG_STACK=single,watch\n")->toContain('WATCH_HEALTH_SECRET=keep')->toContain('WATCH_TOKEN=old')
        ->and(substr_count($env, 'WATCH_TOKEN='))->toBe(1);
});

it('adds LOG_STACK when the app has none', function () {
    file_put_contents($this->env, "APP_NAME=x\n");

    $this->artisan('watch:install', ['--no-interaction' => true])->assertSuccessful();

    expect(file_get_contents($this->env))->toContain('LOG_STACK=single,watch');
});

it('warns when LOG_CHANNEL does not use the stack', function () {
    file_put_contents($this->env, "LOG_CHANNEL=daily\n");

    $this->artisan('watch:install', ['--no-interaction' => true])->expectsOutputToContain('LOG_CHANNEL is not')->assertSuccessful();
});

it('does not overwrite a published config without --force', function () {
    file_put_contents($this->config, '<?php return [];');
    file_put_contents($this->env, '');

    $this->artisan('watch:install', ['--no-interaction' => true])->assertSuccessful();

    expect(file_get_contents($this->config))->toBe('<?php return [];');
});

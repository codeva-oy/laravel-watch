<?php

use Codeva\Watch\Support\Platform;

$platform = fn (array $env) => new Platform(fn (string $key) => $env[$key] ?? null);

it('takes the deploy from WATCH_DEPLOY first, then the platforms', function (array $env, string $deploy) use ($platform) {
    expect($platform($env)->deploy())->toBe($deploy);
})->with([
    [['WATCH_DEPLOY' => 'mine', 'FORGE_DEPLOY_COMMIT' => 'f'], 'mine'],
    [['LARAVEL_CLOUD_DEPLOY_UUID' => 'c', 'FORGE_DEPLOY_COMMIT' => 'f'], 'c'],
    [['FORGE_DEPLOY_COMMIT' => 'f', 'VAPOR_COMMIT_HASH' => 'v'], 'f'],
    [['VAPOR_COMMIT_HASH' => 'v'], 'v'],
    [[], ''],
]);

it('detects Vapor and Cloud', function () use ($platform) {
    expect($platform(['VAPOR_SSM_PATH' => '/x'])->isVapor())->toBeTrue()
        ->and($platform([])->isVapor())->toBeFalse()
        ->and($platform(['LARAVEL_CLOUD' => '1'])->isCloud())->toBeTrue()
        ->and($platform([])->isCloud())->toBeFalse();
});

it('names the server', function () use ($platform) {
    expect($platform(['WATCH_SERVER_NAME' => 'web-1'])->server())->toBe('web-1')
        ->and($platform(['VAPOR_SSM_PATH' => '/x', 'AWS_LAMBDA_FUNCTION_NAME' => 'app-production'])->server())->toBe('app-production')
        ->and($platform([])->server())->toBe((string) gethostname());
});

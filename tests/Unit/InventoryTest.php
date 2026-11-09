<?php

use Codeva\Watch\Support\Inventory;

$fixture = fn (string $path): string => file_get_contents(__DIR__.'/../stubs/inventory/'.$path);

it('reads Composer 2 installed.json, skipping the root package and flagging dev packages', function () use ($fixture) {
    expect(Inventory::composerPackages($fixture('vendor/composer/installed.json'), 'acme/shop'))->toBe([
        ['name' => 'laravel/framework', 'version' => '13.40.0', 'dev' => false],
        ['name' => 'monolog/monolog', 'version' => '3.9.0', 'dev' => false],
        ['name' => 'pestphp/pest', 'version' => '4.1.0', 'dev' => true],
    ]);
});

it('reads the Composer 1 list format', function () use ($fixture) {
    expect(Inventory::composerPackages($fixture('vendor/composer/installed-v1.json')))->toBe([
        ['name' => 'laravel/framework', 'version' => '12.0.1', 'dev' => false],
        ['name' => 'symfony/console', 'version' => '7.2.0', 'dev' => false],
    ]);
});

it('sends no Composer packages for a missing or broken installed.json', function () {
    expect(Inventory::composerPackages(null))->toBe([])
        ->and(Inventory::composerPackages('{nope'))->toBe([]);
});

it('reads every installed copy from package-lock.json with dev and direct flags', function () use ($fixture) {
    expect(Inventory::npmPackages($fixture('package-lock.json'), $fixture('package.json')))->toBe([
        ['name' => '@babel/core', 'version' => '7.26.0', 'dev' => true, 'direct' => false],
        ['name' => 'axios', 'version' => '1.7.9', 'dev' => false, 'direct' => true],
        ['name' => 'esbuild', 'version' => '0.21.5', 'dev' => true, 'direct' => false],
        ['name' => 'foo', 'version' => '1.0.0', 'dev' => true, 'direct' => false],
        ['name' => 'form-data', 'version' => '4.0.1', 'dev' => false, 'direct' => false],
        // Needed in production by one copy and in dev by another: production wins.
        ['name' => 'lodash', 'version' => '4.17.21', 'dev' => false, 'direct' => false],
        // Installed as "str", an alias: the real name is what advisories know.
        ['name' => 'string-width', 'version' => '4.2.3', 'dev' => true, 'direct' => true],
        ['name' => 'vite', 'version' => '6.0.0', 'dev' => true, 'direct' => true],
    ]);
});

it('takes direct dependencies from the lockfile root without a package.json', function () use ($fixture) {
    $direct = array_column(array_filter(Inventory::npmPackages($fixture('package-lock.json')), fn ($package) => $package['direct']), 'name');

    expect(array_values($direct))->toBe(['axios', 'string-width', 'vite']);
});

it('sends no npm packages without a lockfile or for a version 1 lockfile', function () {
    expect(Inventory::npmPackages(null))->toBe([])
        ->and(Inventory::npmPackages(json_encode(['lockfileVersion' => 1, 'dependencies' => ['a' => ['version' => '1.0.0']]])))->toBe([]);
});

it('parses os-release', function () use ($fixture) {
    expect(Inventory::osRelease($fixture('os-release')))->toBe(['name' => 'Ubuntu', 'version' => '26.04', 'pretty' => 'Ubuntu 26.04 LTS'])
        ->and(Inventory::osRelease("NAME='Alpine Linux'\nVERSION_ID=3.22.1\n"))->toBe(['name' => 'Alpine Linux', 'version' => '3.22.1', 'pretty' => null])
        ->and(Inventory::osRelease(''))->toBe(['name' => null, 'version' => null, 'pretty' => null]);
});

it('dedupes, caps and trims', function () {
    $lock = ['packages' => ['' => []]];

    for ($i = 0; $i < Inventory::MAX_NPM + 10; $i++) {
        $lock['packages']["node_modules/p{$i}"] = ['version' => '1.0.0'];
        $lock['packages']["node_modules/x/node_modules/p{$i}"] = ['version' => '1.0.0'];
    }

    $installed = ['packages' => [['name' => str_repeat('a', 300), 'version' => '1.0.0']]];

    for ($i = 0; $i < Inventory::MAX_COMPOSER + 10; $i++) {
        $installed['packages'][] = ['name' => "v/p{$i}", 'version' => '1.0.0'];
    }

    $npm = Inventory::npmPackages(json_encode($lock));
    $composer = Inventory::composerPackages(json_encode($installed));

    expect($npm)->toHaveCount(Inventory::MAX_NPM)
        ->and(count(array_unique(array_column($npm, 'name'))))->toBe(Inventory::MAX_NPM)
        ->and($composer)->toHaveCount(Inventory::MAX_COMPOSER)
        ->and(strlen($composer[0]['name']))->toBe(200);
});

it('lists extensions lowercase and sorted', function () {
    expect(Inventory::extensions(['Zend OPcache', 'ctype', 'PDO', 'ctype']))->toBe(['ctype', 'pdo', 'zend opcache']);
});

it('derives a stable daily time from the seed', function () {
    expect(Inventory::dailyCron('base64:abc'))->toBe(Inventory::dailyCron('base64:abc'))
        ->toMatch('/^([0-9]|[1-5][0-9]) ([0-9]|1[0-9]|2[0-3]) \* \* \*$/')
        ->and(collect(range(1, 20))->map(fn ($i) => Inventory::dailyCron("app{$i}"))->unique()->count())->toBeGreaterThan(15);
});

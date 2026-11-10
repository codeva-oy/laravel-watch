<?php

declare(strict_types=1);

namespace Codeva\Watch\Support;

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_UNESCAPED_SLASHES;
use const PHP_MAJOR_VERSION;
use const PHP_MINOR_VERSION;
use const PHP_OS_FAMILY;
use const PHP_RELEASE_VERSION;

use Codeva\Watch\Agent\AgentConfig;
use Codeva\Watch\Transport\ServerClient;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;

use function array_is_list;
use function array_keys;
use function array_map;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function explode;
use function file_get_contents;
use function get_loaded_extensions;
use function hash;
use function hexdec;
use function intdiv;
use function is_array;
use function is_file;
use function is_readable;
use function is_string;
use function json_decode;
use function json_encode;
use function mb_substr;
use function php_uname;
use function preg_match;
use function preg_replace;
use function restore_error_handler;
use function set_error_handler;
use function sort;
use function sprintf;
use function strcmp;
use function strlen;
use function strrpos;
use function strtolower;
use function substr;
use function trim;
use function usort;

/**
 * What the app runs on and with: PHP, Laravel, the OS and every Composer and
 * npm package, so the server can show versions and match known vulnerabilities.
 * Read from files only; nothing here runs composer or npm.
 *
 * @phpstan-type ComposerPackage array{name: string, version: string, dev: bool}
 * @phpstan-type NpmPackage array{name: string, version: string, dev: bool, direct: bool}
 * @phpstan-type Os array{name: string|null, version: string|null, pretty: string|null}
 * @phpstan-type Payload array{
 *     agent_version: string,
 *     environment: string,
 *     server: string,
 *     platform: string|null,
 *     deploy: string|null,
 *     php: array{version: string, extensions: list<string>},
 *     framework: array{name: string, version: string},
 *     os: Os,
 *     kernel: string,
 *     composer: list<ComposerPackage>,
 *     npm: list<NpmPackage>,
 * }
 *
 * @internal
 */
final class Inventory
{
    public const int MAX_COMPOSER = 5_000;

    public const int MAX_NPM = 20_000;

    private const int MAX_LENGTH = 200;

    private readonly string $basePath;

    /**
     * @param  list<string>  $osReleasePaths
     */
    public function __construct(
        private readonly ServerClient $server,
        private readonly Config $config,
        private readonly Application $app,
        ?string $basePath = null,
        private readonly array $osReleasePaths = ['/etc/os-release', '/usr/lib/os-release'],
    ) {
        $this->basePath = $basePath ?? $app->basePath();
    }

    /**
     * @return Payload
     */
    public function collect(): array
    {
        $composerJson = json_decode(self::read($this->basePath.'/composer.json') ?? '', true);

        return [
            'agent_version' => AgentConfig::installedVersion(),
            'environment' => self::text((string) $this->config->get('app.env', 'production')),
            'server' => self::text($this->serverName()),
            // So the server can tell a Cloud deploy job and the containers that replace each
            // other from separate servers; their hostnames change with every deploy.
            'platform' => $this->platform(),
            'deploy' => $this->deploy(),
            'php' => [
                'version' => PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.'.'.PHP_RELEASE_VERSION,
                'extensions' => self::extensions(get_loaded_extensions()),
            ],
            'framework' => ['name' => 'laravel', 'version' => self::text($this->app->version())],
            'os' => $this->os(),
            'kernel' => self::text(php_uname('r')),
            'composer' => self::composerPackages(
                self::read($this->basePath.'/vendor/composer/installed.json'),
                is_array($composerJson) && is_string($composerJson['name'] ?? null) ? $composerJson['name'] : '',
            ),
            'npm' => self::npmPackages(self::read($this->basePath.'/package-lock.json'), self::read($this->basePath.'/package.json')),
        ];
    }

    /**
     * @param  Payload  $payload
     * @return int the response status, 0 when unreachable
     */
    public function send(array $payload): int
    {
        return $this->server->post('/api/inventory', self::encode($payload), [
            'Authorization: Bearer '.$this->config->get('watch.token'),
            'Content-Type: application/json',
            'Accept: application/json',
        ])['status'];
    }

    /**
     * @param  Payload  $payload
     */
    public static function encode(array $payload, int $flags = 0): string
    {
        return (string) json_encode($payload, $flags | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Once a day at a time of its own, so apps sharing a Watch server do not all
     * send at midnight. The seed is hashed: the app key never leaves the app.
     */
    public static function dailyCron(string $seed): string
    {
        $minute = (int) hexdec(substr(hash('sha256', $seed), 0, 8)) % 1440;

        return sprintf('%d %d * * *', $minute % 60, intdiv($minute, 60));
    }

    /**
     * vendor/composer/installed.json: Composer 2 writes {packages, dev-package-names},
     * Composer 1 a plain list, which says nothing about dev packages.
     *
     * @return list<ComposerPackage>
     */
    public static function composerPackages(?string $installedJson, string $rootName = ''): array
    {
        $decoded = json_decode($installedJson ?? '', true);

        if (! is_array($decoded)) {
            return [];
        }

        $packages = array_is_list($decoded) ? $decoded : $decoded['packages'] ?? [];
        $devNames = array_is_list($decoded) ? [] : $decoded['dev-package-names'] ?? [];
        $dev = [];

        foreach (is_array($devNames) ? $devNames : [] as $name) {
            if (is_string($name)) {
                $dev[$name] = true;
            }
        }

        $entries = [];

        foreach (is_array($packages) ? $packages : [] as $package) {
            if (! is_array($package) || ! is_string($package['name'] ?? null) || ! is_string($package['version'] ?? null)) {
                continue;
            }

            $name = self::text($package['name']);

            if ($name === '' || $name === $rootName) {
                continue;
            }

            $entries[] = [
                'name' => $name,
                'version' => self::text((string) preg_replace('/^v(?=\d)/i', '', trim($package['version']))),
                'dev' => isset($dev[$package['name']]),
            ];
        }

        /** @var list<ComposerPackage> */
        return self::unique($entries, self::MAX_COMPOSER);
    }

    /**
     * package-lock.json versions 2 and 3: every installed copy is a key of
     * "packages", nested ones under their parent's node_modules. An aliased
     * package carries its real name, which is the one advisories use.
     *
     * @return list<NpmPackage>
     */
    public static function npmPackages(?string $lockJson, ?string $packageJson = null): array
    {
        $lock = json_decode($lockJson ?? '', true);

        if (! is_array($lock) || ! is_array($lock['packages'] ?? null)) {
            return [];
        }

        $root = json_decode($packageJson ?? '', true);
        $root = is_array($root) ? $root : $lock['packages'][''] ?? [];
        $direct = [];

        foreach (['dependencies', 'devDependencies'] as $key) {
            foreach (is_array($root) && is_array($root[$key] ?? null) ? array_keys($root[$key]) : [] as $name) {
                $direct[(string) $name] = true;
            }
        }

        $entries = [];

        foreach ($lock['packages'] as $path => $package) {
            $path = (string) $path;
            $position = strrpos($path, 'node_modules/');

            // The root "" entry, workspace folders and links have no installed version.
            if ($position === false || ! is_array($package) || ! is_string($package['version'] ?? null)) {
                continue;
            }

            $installedAs = substr($path, $position + strlen('node_modules/'));
            $name = is_string($package['name'] ?? null) && $package['name'] !== '' ? $package['name'] : $installedAs;

            if ($installedAs === '') {
                continue;
            }

            $entries[] = [
                'name' => self::text($name),
                'version' => self::text($package['version']),
                'dev' => ($package['dev'] ?? false) === true,
                'direct' => $path === 'node_modules/'.$installedAs && isset($direct[$installedAs]),
            ];
        }

        /** @var list<NpmPackage> */
        return self::unique($entries, self::MAX_NPM);
    }

    /**
     * /etc/os-release: shell-style KEY=value lines, values optionally quoted.
     *
     * @return Os
     */
    public static function osRelease(string $contents): array
    {
        $values = [];

        foreach (explode("\n", $contents) as $line) {
            if (preg_match('/^([A-Z0-9_]+)=(.*)$/', trim($line), $match) !== 1) {
                continue;
            }

            $value = $match[2];

            if (preg_match('/^(["\'])(.*)\1$/', $value, $quoted) === 1) {
                $value = $quoted[1] === '"' ? (string) preg_replace('/\\\\(.)/', '$1', $quoted[2]) : $quoted[2];
            }

            $values[$match[1]] = self::text($value);
        }

        return [
            'name' => ($values['NAME'] ?? '') ?: null,
            'version' => ($values['VERSION_ID'] ?? '') ?: null,
            'pretty' => ($values['PRETTY_NAME'] ?? '') ?: null,
        ];
    }

    /**
     * @param  list<string>  $extensions
     * @return list<string>
     */
    public static function extensions(array $extensions): array
    {
        $extensions = array_values(array_unique(array_map(static fn (string $extension): string => self::text(strtolower($extension)), $extensions)));

        sort($extensions);

        return $extensions;
    }

    /**
     * @return Os
     */
    private function os(): array
    {
        foreach ($this->osReleasePaths as $path) {
            $contents = self::read($path);

            if ($contents !== null && ($os = self::osRelease($contents))['name'] !== null) {
                return $os;
            }
        }

        // macOS, Windows and the BSDs have no os-release.
        return ['name' => self::text(php_uname('s')) ?: PHP_OS_FAMILY, 'version' => null, 'pretty' => null];
    }

    private function platform(): ?string
    {
        $platform = Platform::fromEnvironment();

        return match (true) {
            $platform->isCloud() => 'cloud',
            $platform->isVapor() => 'vapor',
            default => null,
        };
    }

    private function deploy(): ?string
    {
        $configured = $this->config->get('watch.environment.deploy_id');
        $deploy = is_string($configured) && $configured !== '' ? $configured : Platform::fromEnvironment()->deploy();

        return $deploy === '' ? null : self::text($deploy);
    }

    private function serverName(): string
    {
        $configured = $this->config->get('watch.environment.server_name');

        return is_string($configured) && $configured !== '' ? $configured : Platform::fromEnvironment()->server();
    }

    /**
     * One entry per name@version, sorted, then capped.
     *
     * @param  list<array{name: string, version: string, dev: bool, direct?: bool}>  $entries
     * @return list<array{name: string, version: string, dev: bool, direct?: bool}>
     */
    private static function unique(array $entries, int $limit): array
    {
        $unique = [];

        foreach ($entries as $entry) {
            $key = $entry['name'].'@'.$entry['version'];

            if (! isset($unique[$key])) {
                $unique[$key] = $entry;

                continue;
            }

            // A copy needed outside dev makes the package a production one.
            $unique[$key]['dev'] = $unique[$key]['dev'] && $entry['dev'];

            if (isset($entry['direct'], $unique[$key]['direct'])) {
                $unique[$key]['direct'] = $unique[$key]['direct'] || $entry['direct'];
            }
        }

        $unique = array_values($unique);

        usort($unique, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']) ?: strcmp($a['version'], $b['version']));

        return count($unique) > $limit ? array_slice($unique, 0, $limit) : $unique;
    }

    private static function text(string $value): string
    {
        return mb_substr(trim($value), 0, self::MAX_LENGTH);
    }

    private static function read(string $path): ?string
    {
        // open_basedir turns a mere is_file() into a warning.
        set_error_handler(static fn (): bool => true);

        try {
            if (! is_file($path) || ! is_readable($path)) {
                return null;
            }

            $contents = file_get_contents($path);

            return $contents === false ? null : $contents;
        } finally {
            restore_error_handler();
        }
    }
}

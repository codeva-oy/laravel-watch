<?php

declare(strict_types=1);

namespace Codeva\Watch\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

use function array_filter;
use function array_map;
use function base_path;
use function explode;
use function implode;
use function in_array;
use function is_string;
use function preg_match;
use function preg_quote;
use function preg_replace_callback;
use function rtrim;
use function trim;

/** @internal */
final class InstallCommand extends Command
{
    protected $signature = 'watch:install
        {--token= : The project token}
        {--url= : The Watch server URL}
        {--force : Overwrite a config file that was already published}';

    protected $description = 'Publish the Watch config and write its environment keys';

    public function handle(Filesystem $files): int
    {
        $this->call('vendor:publish', ['--tag' => 'watch-config', '--force' => (bool) $this->option('force')]);

        $path = base_path('.env');

        if (! $files->exists($path)) {
            $this->components->warn('No .env file found; set WATCH_TOKEN, WATCH_SERVER_URL and LOG_STACK in your environment.');

            return self::SUCCESS;
        }

        $env = $files->get($path);

        $token = $this->value('token', 'WATCH_TOKEN', 'Project token', $env);
        $url = $this->value('url', 'WATCH_SERVER_URL', 'Watch server URL', $env);

        $env = self::write($env, 'WATCH_TOKEN', $token);
        $env = self::write($env, 'WATCH_SERVER_URL', rtrim($url, '/'));

        if (self::read($env, 'WATCH_HEALTH_SECRET') === '') {
            $env = self::write($env, 'WATCH_HEALTH_SECRET', Str::random(40));
        }

        $env = self::write($env, 'LOG_STACK', self::withWatch(self::read($env, 'LOG_STACK')));

        $files->put($path, $env);

        if (! in_array(self::read($env, 'LOG_CHANNEL'), ['', 'stack'], true)) {
            $this->components->warn('LOG_CHANNEL is not "stack", so logs will not reach Watch until it is.');
        }

        $this->components->info('Watch installed. Run php artisan watch:test to check it.');

        return self::SUCCESS;
    }

    private function value(string $option, string $key, string $question, string $env): string
    {
        $value = $this->option($option);

        if (is_string($value) && $value !== '') {
            return $value;
        }

        $current = self::read($env, $key);

        if ($current !== '' || ! $this->input->isInteractive()) {
            return $current;
        }

        return trim((string) $this->ask($question));
    }

    private static function withWatch(string $stack): string
    {
        $channels = array_filter(array_map(trim(...), explode(',', $stack === '' ? 'single' : $stack)));

        if (! in_array('watch', $channels, true)) {
            $channels[] = 'watch';
        }

        return implode(',', $channels);
    }

    private static function read(string $env, string $key): string
    {
        return preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $env, $match) === 1 ? trim($match[1], " \t\"'\r") : '';
    }

    private static function write(string $env, string $key, string $value): string
    {
        $line = "{$key}={$value}";
        $count = 0;

        $updated = preg_replace_callback('/^'.preg_quote($key, '/').'=.*$/m', static fn (): string => $line, $env, -1, $count);

        return $count > 0 ? (string) $updated : rtrim($env, "\n")."\n{$line}\n";
    }
}

<?php

declare(strict_types=1);

namespace Codeva\Watch\Console;

use Codeva\Watch\Agent\Agent;
use Codeva\Watch\Agent\AgentConfig;
use Codeva\Watch\Agent\Spool;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use React\EventLoop\Loop;
use Throwable;

use function config;
use function gethostname;
use function rtrim;
use function storage_path;

/** @internal */
final class AgentCommand extends Command
{
    protected $signature = 'watch:agent';

    protected $description = 'Run the Codeva Watch agent';

    public function handle(): int
    {
        /** @var array<string, mixed> $watch */
        $watch = (array) config('watch');

        $config = new AgentConfig(
            token: (string) ($watch['token'] ?? ''),
            serverUrl: rtrim((string) ($watch['server_url'] ?? ''), '/'),
            listen: (string) ($watch['agent_listen'] ?? '127.0.0.1:2408'),
            spoolPath: storage_path('watch-spool'),
            serverName: (string) ($watch['environment']['server_name'] ?? gethostname()),
            version: AgentConfig::installedVersion(),
            verbose: $this->output->isVerbose(),
        );

        if ($config->token === '' || $config->serverUrl === '') {
            $this->components->error('WATCH_TOKEN and WATCH_SERVER_URL must be set.');

            return self::FAILURE;
        }

        $loop = Loop::get();
        $agent = new Agent($loop, $config, new Spool($config->spoolPath, $config->spoolCapBytes));
        $agent->start();

        // `php artisan queue:restart` on deploy: spool what is held and exit, so the
        // process manager starts the agent again on the new release.
        $restartedAt = $this->restartSignal();
        $loop->addPeriodicTimer(5.0, function () use ($agent, $restartedAt, $loop): void {
            if ($this->restartSignal() !== $restartedAt) {
                $this->components->info('Restart requested (queue:restart); stopping.');
                $agent->stop();
                $loop->stop();
            }
        });

        $loop->run();

        return self::SUCCESS;
    }

    private function restartSignal(): mixed
    {
        try {
            return Cache::get('illuminate:queue:restart');
        } catch (Throwable) {
            return null;
        }
    }
}

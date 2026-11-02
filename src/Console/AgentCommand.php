<?php

declare(strict_types=1);

namespace Codeva\Watch\Console;

use Codeva\Watch\Agent\Agent;
use Codeva\Watch\Agent\AgentConfig;
use Codeva\Watch\Agent\Spool;
use Illuminate\Console\Command;
use React\EventLoop\Loop;

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
            verbose: $this->output->isVerbose(),
        );

        if ($config->token === '' || $config->serverUrl === '') {
            $this->components->error('WATCH_TOKEN and WATCH_SERVER_URL must be set.');

            return self::FAILURE;
        }

        $loop = Loop::get();

        (new Agent($loop, $config, new Spool($config->spoolPath, $config->spoolCapBytes)))->start();

        $loop->run();

        return self::SUCCESS;
    }
}

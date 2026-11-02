<?php

namespace Codeva\Watch;

use Codeva\Watch\Contracts\Ingest as IngestContract;
use Codeva\Watch\Facades\Watch;
use Codeva\Watch\Factories\Logger;
use Codeva\Watch\Hooks\ArtisanStartingListener;
use Codeva\Watch\Hooks\CacheEventListener;
use Codeva\Watch\Hooks\CommandBootedHandler;
use Codeva\Watch\Hooks\CommandStartingListener;
use Codeva\Watch\Hooks\ContextDehydratingHandler;
use Codeva\Watch\Hooks\CreateQueuePayloadHandler;
use Codeva\Watch\Hooks\ExceptionHandlerResolvedHandler;
use Codeva\Watch\Hooks\GlobalMiddleware;
use Codeva\Watch\Hooks\HttpClientFactoryResolvedHandler;
use Codeva\Watch\Hooks\HttpKernelResolvedHandler;
use Codeva\Watch\Hooks\LivewireListener;
use Codeva\Watch\Hooks\LogoutListener;
use Codeva\Watch\Hooks\MailListener;
use Codeva\Watch\Hooks\NotificationListener;
use Codeva\Watch\Hooks\OctaneListener;
use Codeva\Watch\Hooks\OctaneRequestTerminatedListener;
use Codeva\Watch\Hooks\PolyfillContextDehydration;
use Codeva\Watch\Hooks\PolyfillContextHydration;
use Codeva\Watch\Hooks\PreparingResponseListener;
use Codeva\Watch\Hooks\QueryExecutedListener;
use Codeva\Watch\Hooks\QueuedJobListener;
use Codeva\Watch\Hooks\RequestBootedHandler;
use Codeva\Watch\Hooks\RequestHandledListener;
use Codeva\Watch\Hooks\ResponsePreparedListener;
use Codeva\Watch\Hooks\RouteMatchedListener;
use Codeva\Watch\Hooks\RouteMiddleware;
use Codeva\Watch\Hooks\ScheduledTaskHeartbeatListener;
use Codeva\Watch\Hooks\TerminatingListener;
use Codeva\Watch\Http\Middleware\Sample;
use Codeva\Watch\Jobs\SendBatch;
use Codeva\Watch\Records\CacheEvent;
use Codeva\Watch\Records\Command;
use Codeva\Watch\Records\Exception;
use Codeva\Watch\Records\JobAttempt;
use Codeva\Watch\Records\Mail;
use Codeva\Watch\Records\Notification;
use Codeva\Watch\Records\OutgoingRequest;
use Codeva\Watch\Records\Query;
use Codeva\Watch\Records\QueuedJob;
use Codeva\Watch\Records\Request;
use Codeva\Watch\State\CommandState;
use Codeva\Watch\State\RequestState;
use Codeva\Watch\Support\Platform;
use Codeva\Watch\Support\ScheduledTask;
use Codeva\Watch\Support\Uuid;
use Codeva\Watch\Transport\AgentIngest;
use Codeva\Watch\Transport\CircuitBreaker;
use Codeva\Watch\Transport\HttpIngest;
use Codeva\Watch\Transport\HttpSender;
use Codeva\Watch\Transport\NullIngest;
use Codeva\Watch\Transport\RecordShaper;
use Codeva\Watch\Transport\SampleRate;
use Codeva\Watch\Transport\ServerClient;
use Codeva\Watch\Transport\ShapingIngest;
use Illuminate\Auth\AuthManager;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\ForgettingKey;
use Illuminate\Cache\Events\KeyForgetFailed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWriteFailed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Cache\Events\RetrievingManyKeys;
use Illuminate\Cache\Events\WritingKey;
use Illuminate\Cache\Events\WritingManyKeys;
use Illuminate\Console\Events\ArtisanStarting;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Events\Terminating;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Queue\Queue;
use Illuminate\Routing\Events\PreparingResponse;
use Illuminate\Routing\Events\ResponsePrepared;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\ServiceProvider;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\RequestTerminated;
use Livewire\Livewire;
use Livewire\LivewireManager;
use Ramsey\Uuid\Uuid as BaseUuid;
use Throwable;

use function array_filter;
use function array_keys;
use function array_values;
use function class_exists;
use function defined;
use function microtime;

/**
 * @internal
 */
final class WatchServiceProvider extends ServiceProvider
{
    /**
     * @var Core<RequestState|CommandState>
     */
    private Core $core;

    private float $timestamp;

    private bool $isRequest;

    private Repository $config;

    /**
     * @var array{
     *     enabled?: bool,
     *     token?: string|null,
     *     transport?: string,
     *     agent_address?: string,
     *     server_url?: string|null,
     *     fallback_http?: bool,
     *     http_queue?: bool|null,
     *     health_secret?: string|null,
     *     log_level?: string,
     *     sampling?: array{
     *        requests?: float,
     *        commands?: float,
     *        jobs?: float,
     *        exceptions?: float,
     *        tasks?: float,
     *     },
     *     ignore?: array{
     *         cache?: bool,
     *         mail?: bool,
     *         notifications?: bool,
     *         outgoing_requests?: bool,
     *         queries?: bool,
     *         logs?: bool,
     *     },
     *     environment?: array{ deploy_id?: string|null, server_name?: string|null },
     *     privacy?: array{
     *         capture_source_code?: bool,
     *         capture_payload?: bool,
     *         redact_fields?: list<string>,
     *         redact_headers?: list<string>,
     *     },
     *     ingest?: array{ buffer_size?: int },
     *  }
     */
    private array $watchConfig;

    private ?Throwable $registerException = null;

    private Platform $platform;

    private SampleRate $sampleRate;

    public function register(): void
    {
        try {
            $this->captureTimestamp();
            $this->captureExecutionType();
            $this->registerAndCaptureConfig();

            if ($this->shouldStandBy()) {
                $this->registerStandby();

                return;
            }

            Compatibility::boot($this->app);
            $this->registerBindings();

            if (! $this->core->enabled()) {
                return;
            }

            $this->registerHooks();
        } catch (Throwable $e) {
            $this->registerException = $e;
        }
    }

    public function boot(): void
    {
        try {
            if ($this->registerException) {
                $this->handleAndClearRegisterException();

                return;
            }

            $this->loadRoutesFrom(__DIR__.'/../routes/watch.php');

            if ($this->app->runningInConsole()) {
                $this->registerPublications();
                $this->registerCommands();
                $this->registerScheduleMacro();
            }
        } catch (Throwable $e) {
            Watch::unrecoverableExceptionOccurred($e);
        }
    }

    private function captureTimestamp(): void
    {
        $this->timestamp = match (true) {
            defined('LARAVEL_START') => LARAVEL_START,
            default => $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true),
        };
    }

    private function captureExecutionType(): void
    {
        $this->isRequest = ! $this->app->runningInConsole() || Env::get('WATCH_FORCE_REQUEST');
    }

    private function registerAndCaptureConfig(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/watch.php', 'watch');

        $this->config = $this->app->make(Repository::class);
        $this->platform = Platform::fromEnvironment();

        $this->watchConfig = $this->config->get('watch') ?? [];
    }

    /**
     * With the agent known to be down nothing recorded here could be sent, so
     * a request pays for this check and no hooks. Long-running processes keep
     * theirs: they would never get them back when the agent recovers.
     */
    private function shouldStandBy(): bool
    {
        return $this->isRequest
            && ! isset($_SERVER['LARAVEL_OCTANE'])
            && ($this->watchConfig['enabled'] ?? true)
            && ($this->watchConfig['transport'] ?? 'agent') === 'agent'
            && ! ($this->watchConfig['fallback_http'] ?? false)
            && CircuitBreaker::forAgent($this->agentAddress())->isOpen();
    }

    /**
     * Facades, the Sample middleware and the log channel still resolve the
     * core, which is built on demand.
     */
    private function registerStandby(): void
    {
        $this->registerLogger();
        $this->registerMiddleware();
        $this->registerServerClients();

        $this->app->singleton(Core::class, function () {
            Compatibility::boot($this->app);
            $this->buildAndRegisterCore();

            return $this->core;
        });
    }

    private function agentAddress(): string
    {
        return (string) ($this->watchConfig['agent_address'] ?? '127.0.0.1:2408');
    }

    private function registerBindings(): void
    {
        $this->registerLogger();
        $this->registerMiddleware();
        $this->registerServerClients();
        $this->buildAndRegisterCore();
    }

    private function registerLogger(): void
    {
        if (! $this->config->has('logging.channels.watch')) {
            $this->config->set('logging.channels.watch', [
                'driver' => 'custom',
                'via' => Logger::class,
                'level' => $this->watchConfig['log_level'] ?? 'warning',
            ]);
        }

        $this->app->singleton(Logger::class, fn () => new Logger($this->app->make(Core::class)));
    }

    private function registerMiddleware(): void
    {
        $this->app->singleton(RouteMiddleware::class, fn () => new RouteMiddleware($this->app->make(Core::class)));

        $this->app->scoped(GlobalMiddleware::class, fn () => new GlobalMiddleware($this->app->make(Core::class)));

        $this->app->singleton(Sample::class, fn () => new Sample($this->app->make(Core::class)));
    }

    private function buildAndRegisterCore(): void
    {
        $clock = new Clock;
        $this->sampleRate = new SampleRate;
        $uuid = new Uuid(static fn () => BaseUuid::uuid4()->toString());
        $executionState = $this->executionState($uuid->make());

        $this->app->instance(Core::class, $this->core = new Core(
            ingest: $this->makeIngest(),
            sensor: new SensorManager(
                executionState: $executionState,
                clock: $clock = new Clock,
                location: new Location(
                    basePath: $this->app->basePath(),
                    publicPath: $this->app->publicPath(),
                ),
                captureExceptionSourceCode: (bool) ($this->watchConfig['privacy']['capture_source_code'] ?? true),
                captureRequestPayload: (bool) ($this->watchConfig['privacy']['capture_payload'] ?? false),
                redactPayloadFields: $this->watchConfig['privacy']['redact_fields'] ?? [],
                redactHeaders: $this->watchConfig['privacy']['redact_headers'] ?? [],
                config: $this->config,
            ),
            executionState: $executionState,
            clock: $clock,
            uuid: $uuid,
            sampleRate: $this->sampleRate,
            config: [
                'enabled' => $this->watchConfig['enabled'] ?? true,
                'sampling' => [
                    'requests' => $this->watchConfig['sampling']['requests'] ?? 1.0,
                    'commands' => $this->watchConfig['sampling']['commands'] ?? 1.0,
                    'exceptions' => $this->watchConfig['sampling']['exceptions'] ?? 1.0,
                    'scheduled_tasks' => $this->watchConfig['sampling']['tasks'] ?? 1.0,
                    'jobs' => $this->watchConfig['sampling']['jobs'] ?? 1.0,
                ],
                'filtering' => [
                    'ignore_cache_events' => (bool) ($this->watchConfig['ignore']['cache'] ?? false),
                    'ignore_mail' => (bool) ($this->watchConfig['ignore']['mail'] ?? false),
                    'ignore_notifications' => (bool) ($this->watchConfig['ignore']['notifications'] ?? false),
                    'ignore_outgoing_requests' => (bool) ($this->watchConfig['ignore']['outgoing_requests'] ?? false),
                    'ignore_queries' => (bool) ($this->watchConfig['ignore']['queries'] ?? false),
                    'ignore_logs' => (bool) ($this->watchConfig['ignore']['logs'] ?? false),
                ],
            ],
        ));
    }

    private function makeIngest(): IngestContract
    {
        $token = (string) ($this->watchConfig['token'] ?? '');
        $buffer = new RecordsBuffer(length: $this->watchConfig['ingest']['buffer_size'] ?? 500);
        $address = $this->agentAddress();

        $ingest = match ($this->watchConfig['transport'] ?? 'agent') {
            'null' => new NullIngest,
            'http' => new HttpIngest($buffer, $this->httpSender()),
            default => new AgentIngest(
                address: $address,
                token: $token,
                buffer: $buffer,
                breaker: CircuitBreaker::forAgent($address),
                fallback: ($this->watchConfig['fallback_http'] ?? false) ? $this->httpSender() : null,
            ),
        };

        return new ShapingIngest($ingest, new RecordShaper(
            token: $token,
            sampleRate: $this->sampleRate,
            exceptionRate: (float) ($this->watchConfig['sampling']['exceptions'] ?? 1.0),
            environment: (string) $this->config->get('app.env', 'production'),
        ));
    }

    private function httpSender(): HttpSender
    {
        return $this->app->make(HttpSender::class);
    }

    private function registerServerClients(): void
    {
        $url = (string) ($this->watchConfig['server_url'] ?? '');

        // Heartbeats and the schedule sync run in the background and can wait.
        $this->app->bind(ServerClient::class, static fn () => new ServerClient($url, timeout: 5.0));

        $this->app->singleton(HttpSender::class, fn () => new HttpSender(
            server: new ServerClient($url, timeout: 1.0),
            token: (string) ($this->watchConfig['token'] ?? ''),
            serverName: $this->serverName(),
            breaker: new CircuitBreaker(name: 'http'),
            queue: (bool) ($this->watchConfig['http_queue'] ?? $this->platform->isVapor()),
            dispatch: fn (string $payload) => $this->core->ignore(fn () => $this->app->make(Bus::class)->dispatch(new SendBatch($payload))),
            runningInConsole: $this->app->runningInConsole(),
        ));
    }

    private function serverName(): string
    {
        return ($this->watchConfig['environment']['server_name'] ?? null) ?: $this->platform->server();
    }

    private function deploy(): string
    {
        return ($this->watchConfig['environment']['deploy_id'] ?? null) ?: $this->platform->deploy();
    }

    private function handleAndClearRegisterException(): void
    {
        Watch::unrecoverableExceptionOccurred($this->registerException); // @phpstan-ignore argument.type

        $this->registerException = null;
    }

    private function registerPublications(): void
    {
        $this->publishes([
            __DIR__.'/../config/watch.php' => $this->app->configPath('watch.php'),
        ], ['watch', 'watch-config']);
    }

    private function registerCommands(): void
    {
        $this->commands([
            Console\AgentCommand::class,
            Console\InstallCommand::class,
            Console\StatusCommand::class,
            Console\SyncScheduleCommand::class,
            Console\TestCommand::class,
        ]);
    }

    private function registerScheduleMacro(): void
    {
        Event::macro('watchGrace', function (int $minutes) {
            /** @var Event $this */
            ScheduledTask::setGrace($this, $minutes);

            return $this;
        });
    }

    private function registerHooks(): void
    {
        $core = $this->core;

        /** @var Dispatcher */
        $events = $this->app->make(Dispatcher::class);

        //
        // -------------------------------------------------------------------------
        // Sensor hooks
        // --------------------------------------------------------------------------
        //

        /**
         * @see Query
         */
        $events->listen(QueryExecuted::class, (new QueryExecutedListener($core))(...));

        /**
         * @see Exception
         */
        $this->callAfterResolving(ExceptionHandler::class, (new ExceptionHandlerResolvedHandler($core))(...));

        /**
         * @see QueuedJob
         */
        $events->listen([JobQueueing::class, JobQueued::class], (new QueuedJobListener($core))(...));

        /**
         * @see Notification
         */
        $events->listen([NotificationSending::class, NotificationSent::class], (new NotificationListener($core))(...));

        /**
         * @see Mail
         */
        $events->listen([MessageSending::class, MessageSent::class], (new MailListener($core))(...));

        /**
         * @see OutgoingRequest
         */
        $this->callAfterResolving(Http::class, (new HttpClientFactoryResolvedHandler($core))(...));

        /**
         * @see CacheEvent
         */
        $events->listen([
            RetrievingKey::class,
            RetrievingManyKeys::class,
            CacheHit::class,
            CacheMissed::class,
            WritingKey::class,
            WritingManyKeys::class,
            KeyWritten::class,
            KeyWriteFailed::class,
            ForgettingKey::class,
            KeyForgotten::class,
            KeyForgetFailed::class,
        ], (new CacheEventListener($core))(...));

        $events->listen(RequestReceived::class, (new OctaneListener($core))(...));
        $events->listen(RequestTerminated::class, (new OctaneRequestTerminatedListener($core))(...));

        Queue::createPayloadUsing(new CreateQueuePayloadHandler($core));

        if (Compatibility::$contextExists) {
            Context::dehydrating(new ContextDehydratingHandler($core));
        } else {
            Queue::createPayloadUsing(new PolyfillContextDehydration($core));
            $events->listen((new PolyfillContextHydration($core))(...));
        }

        //
        // -------------------------------------------------------------------------
        // Execution stage hooks
        // --------------------------------------------------------------------------
        //

        if ($this->isRequest) {
            /** @var Core<RequestState> $core */
            $this->registerRequestHooks($events, $core);
        } else {
            /** @var Core<CommandState> $core */
            $this->registerConsoleHooks($events, $core);
        }

        /** @var Core<RequestState|CommandState> $core */

        /**
         * @see ExecutionStage::Terminating
         */
        $events->listen(Terminating::class, (new TerminatingListener($core))(...));
    }

    /**
     * @param  Core<RequestState>  $core
     */
    private function registerRequestHooks(Dispatcher $events, Core $core): void
    {
        // TODO resolve the kernel inline rather than in the listener.

        /**
         * @see RequestState::$user
         *
         * TODO handle this on the queue
         */
        $events->listen(Logout::class, (new LogoutListener($core))(...));

        /**
         * @see ExecutionStage::BeforeMiddleware
         */
        $this->app->booted((new RequestBootedHandler($core))(...));

        /**
         * @see ExecutionStage::Action
         * @see ExecutionStage::Terminating
         */
        $events->listen(RouteMatched::class, (new RouteMatchedListener($core))(...));

        /**
         * @see ExecutionStage::Render
         */
        $events->listen(PreparingResponse::class, (new PreparingResponseListener($core))(...));

        /**
         * @see ExecutionStage::AfterMiddleware
         */
        $events->listen(ResponsePrepared::class, (new ResponsePreparedListener($core))(...));

        /**
         * @see ExecutionStage::Sending
         */
        $events->listen(RequestHandled::class, (new RequestHandledListener($core))(...));

        /**
         * @see ExecutionStage::End
         * @see Request
         * @see ExecutionStage::Terminating
         * @see Core::finishExecution()
         */
        $this->callAfterResolving(HttpKernelContract::class, (new HttpKernelResolvedHandler($core))(...));

        $this->registerLivewireHooks($core);
    }

    /**
     * @param  Core<CommandState>  $core
     */
    private function registerConsoleHooks(Dispatcher $events, Core $core): void
    {
        /** @var ConsoleKernelContract */
        $kernel = $this->app->make(ConsoleKernelContract::class);

        /**
         * @see CommandState::$artisan
         */
        $events->listen(ArtisanStarting::class, (new ArtisanStartingListener($core))(...));

        /**
         * @see ExecutionStage::Action
         */
        $this->app->booted((new CommandBootedHandler($core))(...));

        /**
         * @see CommandState::$name
         *
         * Commands...
         * @see ExecutionStage::Terminating
         * @see ExecutionStage::End
         * @see Command
         * @see Core::finishExecution()
         *
         * Jobs...
         * @see CommandState::$source
         * @see CommandState::flush()
         * @see CommandState::$timestamp
         * @see CommandState::$id
         * @see JobAttempt
         * @see Exception
         *
         * Scheduled tasks...
         * @see Core::finishExecution()
         */
        $events->listen(CommandStarting::class, function (CommandStarting $event) use ($events, $core, $kernel) {
            static $listener = null;

            $listener ??= new CommandStartingListener($events, $core, $kernel, $this->app->make(CacheRepository::class), $this->heartbeatListener($core));

            $listener($event);
        });
    }

    /**
     * @param  Core<CommandState>  $core
     */
    private function heartbeatListener(Core $core): ?ScheduledTaskHeartbeatListener
    {
        if (empty($this->watchConfig['server_url']) || empty($this->watchConfig['token'])) {
            return null;
        }

        return new ScheduledTaskHeartbeatListener($core, $this->app->make(Bus::class), (string) $this->config->get('app.env', 'production'));
    }

    /**
     * @param  Core<RequestState>  $core
     */
    private function registerLivewireHooks(Core $core): void
    {
        if (! class_exists(Livewire::class)) {
            return;
        }

        $this->app->booted(static function ($app) use ($core) {
            if (! $app->bound(LivewireManager::class)) {
                return;
            }

            $listener = new LivewireListener($core);

            // Livewire 2
            Livewire::listen('component.hydrate.subsequent', $listener->componentHydrateSubsequent(...));

            // Livewire 3
            Livewire::listen('hydrate', $listener->hydrate(...));
        });
    }

    private function executionState(string $trace): RequestState|CommandState
    {
        Compatibility::addTraceIdToContext($trace);

        if ($this->isRequest) {
            return new RequestState(
                timestamp: $this->timestamp,
                trace: $trace,
                id: $trace,
                currentExecutionStageStartedAtMicrotime: $this->timestamp,
                deploy: $this->deploy(),
                server: $this->serverName(),
                user: $this->userProvider(),
            );
        } else {
            return new CommandState(
                timestamp: $this->timestamp,
                trace: new LazyValue(function () {
                    return (string) Compatibility::getTraceIdFromContext(function () {
                        $trace = $this->core->uuid->make();

                        Compatibility::addTraceIdToContext($trace);

                        return $trace;
                    });
                }),
                id: $trace,
                currentExecutionStageStartedAtMicrotime: $this->timestamp,
                deploy: $this->deploy(),
                server: $this->serverName(),
                user: $this->userProvider(),
            );
        }
    }

    private function userProvider(): UserProvider
    {
        /** @var AuthManager */
        $auth = $this->app->make(AuthManager::class);

        return new UserProvider(
            fn (callable $callback) => $this->core->ignore(static fn () => $callback($auth)),
            fn () => $this->core->userDetailsResolver,
            fn () => $this->core->report(...),
            fn () => $this->guardNames(),
        );
    }

    /**
     * Every auth guard the application defines, inspected when identifying the
     * user behind a request.
     *
     * @return list<string>
     */
    private function guardNames(): array
    {
        /** @var array<string, mixed> $guards */
        $guards = $this->config->get('auth.guards', []);

        return array_values(array_filter(array_keys($guards), 'is_string'));
    }
}

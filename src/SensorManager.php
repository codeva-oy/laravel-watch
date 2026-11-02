<?php

namespace Codeva\Watch;

use Codeva\Watch\Records\CacheEvent as CacheEventRecord;
use Codeva\Watch\Records\Command;
use Codeva\Watch\Records\Exception;
use Codeva\Watch\Records\Mail;
use Codeva\Watch\Records\Notification;
use Codeva\Watch\Records\OutgoingRequest;
use Codeva\Watch\Records\Query;
use Codeva\Watch\Records\QueuedJob;
use Codeva\Watch\Records\Request as RequestRecord;
use Codeva\Watch\Sensors\CacheEventSensor;
use Codeva\Watch\Sensors\CommandSensor;
use Codeva\Watch\Sensors\ExceptionSensor;
use Codeva\Watch\Sensors\JobAttemptSensor;
use Codeva\Watch\Sensors\LogSensor;
use Codeva\Watch\Sensors\MailSensor;
use Codeva\Watch\Sensors\NotificationSensor;
use Codeva\Watch\Sensors\OutgoingRequestSensor;
use Codeva\Watch\Sensors\QuerySensor;
use Codeva\Watch\Sensors\QueuedJobSensor;
use Codeva\Watch\Sensors\RequestSensor;
use Codeva\Watch\Sensors\ScheduledTaskSensor;
use Codeva\Watch\Sensors\StageSensor;
use Codeva\Watch\Sensors\UserSensor;
use Codeva\Watch\State\CommandState;
use Codeva\Watch\State\RequestState;
use Codeva\Watch\Types\Str;
use Illuminate\Cache\Events\CacheEvent;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Monolog\LogRecord;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * @internal
 */
final class SensorManager
{
    /**
     * @var (callable(CacheEvent): ?array{0: CacheEventRecord, 1: callable(): array<mixed>})|null
     */
    public $cacheEventSensor;

    /**
     * @var (callable(Throwable, null|bool): array{0: Exception, 1: callable(): array<mixed>})|null
     */
    public $exceptionSensor;

    /**
     * @var (callable(LogRecord): array<mixed>)|null
     */
    public $logSensor;

    /**
     * @var (callable(float, float, RequestInterface, ResponseInterface): array{0: OutgoingRequest, 1: callable(): array<mixed>})|null
     */
    public $outgoingRequestSensor;

    /**
     * @var (callable(QueryExecuted, list<array{ file?: string, line?: int }>): array{0: Query, 1: callable(): array<mixed>})|null
     */
    public $querySensor;

    /**
     * @var (callable(JobQueueing|JobQueued): ?array{0: QueuedJob, 1: callable(): array<mixed>})|null
     */
    public $queuedJobSensor;

    /**
     * @var (callable(JobProcessed|JobReleasedAfterException|JobFailed): ?array<mixed>)|null
     */
    public $jobAttemptSensor;

    /**
     * @var (callable(NotificationSending|NotificationSent): ?array{0: Notification, 1: callable(): array<mixed>})|null
     */
    public $notificationSensor;

    /**
     * @var (callable(MessageSending|MessageSent): ?array{0: Mail, 1: callable(): array<mixed>})|null
     */
    public $mailSensor;

    /**
     * @var (callable(): ?array<mixed>)|null
     */
    public $userSensor;

    /**
     * @var (callable(ExecutionStage): void)|null
     */
    public $stageSensor;

    /**
     * @var (callable(ScheduledTaskFinished|ScheduledTaskSkipped|ScheduledTaskFailed): array<mixed>)|null
     */
    public $scheduledTaskSensor;

    /**
     * @var (callable(Request, Response): array{0: RequestRecord, 1: callable(): array<mixed>})|null
     */
    public $requestSensor;

    /**
     * @var (callable(InputInterface, int): array{0: Command, 1: callable(): array<mixed>})|null
     */
    public $commandSensor;

    /**
     * @param  list<string>  $redactPayloadFields
     * @param  list<string>  $redactHeaders
     */
    public function __construct(
        private RequestState|CommandState $executionState,
        private Clock $clock,
        public Location $location,
        private bool $captureExceptionSourceCode,
        private bool $captureRequestPayload,
        private array $redactPayloadFields,
        private array $redactHeaders,
        private Repository $config,
    ) {
        //
    }

    public function stage(ExecutionStage $executionStage): void
    {
        $sensor = $this->stageSensor ??= new StageSensor(
            executionState: $this->executionState,
            clock: $this->clock,
        );

        $sensor($executionStage);
    }

    /**
     * @return array{0: RequestRecord, 1: callable(): array<mixed>}
     */
    public function request(Request $request, Response $response): array
    {
        $sensor = $this->requestSensor ??= new RequestSensor(
            requestState: $this->executionState, // @phpstan-ignore argument.type
            capturePayload: $this->captureRequestPayload,
            redactPayloadFields: $this->redactPayloadFields,
            redactHeaders: $this->redactHeaders,
        );

        return $sensor($request, $response);
    }

    /**
     * @return array{0: Command, 1: callable(): array<mixed>}
     */
    public function command(InputInterface $input, int $status): array
    {
        $sensor = $this->commandSensor ??= new CommandSensor(
            commandState: $this->executionState, // @phpstan-ignore argument.type
        );

        return $sensor($input, $status);
    }

    /**
     * @param  list<array{ file?: string, line?: int }>  $trace
     * @return array{0: Query, 1: callable(): array<mixed>}
     */
    public function query(QueryExecuted $event, array $trace): array
    {
        $sensor = $this->querySensor ??= new QuerySensor(
            executionState: $this->executionState,
            clock: $this->clock,
            location: $this->location,
        );

        return $sensor($event, $trace);
    }

    /**
     * @return array{0: CacheEventRecord, 1: callable(): array<mixed>}
     */
    public function cacheEvent(CacheEvent $event): ?array
    {
        $sensor = $this->cacheEventSensor ??= new CacheEventSensor(
            executionState: $this->executionState,
            clock: $this->clock,
        );

        return $sensor($event);
    }

    /**
     * @return array{0: Mail, 1: callable(): array<mixed>}
     */
    public function mail(MessageSending|MessageSent $event): ?array
    {
        $sensor = $this->mailSensor ??= new MailSensor(
            executionState: $this->executionState,
            clock: $this->clock,
        );

        return $sensor($event);
    }

    /**
     * @return ?array{0: Notification, 1: callable(): array<mixed>}
     */
    public function notification(NotificationSending|NotificationSent $event): ?array
    {
        $sensor = $this->notificationSensor ??= new NotificationSensor(
            executionState: $this->executionState,
            clock: $this->clock,
        );

        return $sensor($event);
    }

    /**
     * @return array{0: OutgoingRequest, 1: callable(): array<mixed>}
     */
    public function outgoingRequest(float $startMicrotime, float $endMicrotime, RequestInterface $request, ResponseInterface $response): array
    {
        $sensor = $this->outgoingRequestSensor ??= new OutgoingRequestSensor(
            executionState: $this->executionState,
        );

        return $sensor($startMicrotime, $endMicrotime, $request, $response);
    }

    /**
     * @return array{0: Exception, 1: callable(): array<mixed>}
     */
    public function exception(Throwable $e, ?bool $handled): array
    {
        $sensor = $this->exceptionSensor ??= new ExceptionSensor(
            executionState: $this->executionState,
            clock: $this->clock,
            location: $this->location,
            captureSourceCode: $this->captureExceptionSourceCode,
        );

        return $sensor($e, $handled);
    }

    /**
     * @return array<mixed>
     */
    public function fatalError(Throwable $e): array
    {
        $file = $this->location->normalizeFile($e->getFile());

        return [
            'v' => 3,
            't' => 'exception',
            'timestamp' => $this->clock->microtime(),
            'deploy' => $this->executionState->deploy,
            'server' => $this->executionState->server,
            'trace_id' => $this->executionState->trace,
            'execution_source' => $this->executionState->source,
            'execution_id' => '',
            'execution_preview' => $this->executionState->executionPreview,
            'execution_stage' => $this->executionState->stage,
            'user' => $this->executionState->user->resolvedUserId(),
            'class' => $e::class,
            'file' => Str::tinyText($file),
            'line' => $e->getLine(),
            'message' => Str::text($e->getMessage()),
            'code' => (string) $e->getCode(),
            'trace' => '',
            'handled' => false,
            'php_version' => $this->executionState->phpVersion,
            'laravel_version' => $this->executionState->laravelVersion,
        ];
    }

    /**
     * @return array<mixed>
     */
    public function log(LogRecord $record): array
    {
        $sensor = $this->logSensor ??= new LogSensor(
            executionState: $this->executionState,
        );

        return $sensor($record);
    }

    /**
     * @return ?array{0: QueuedJob, 1: callable(): array<mixed>}
     */
    public function queuedJob(JobQueueing|JobQueued $event): ?array
    {
        $sensor = $this->queuedJobSensor ??= new QueuedJobSensor(
            executionState: $this->executionState,
            clock: $this->clock,
            connectionConfig: $this->config->get('queue.connections') ?? [],
        );

        return $sensor($event);
    }

    /**
     * @return ?array<mixed>
     */
    public function jobAttempt(JobProcessed|JobReleasedAfterException|JobFailed $event): ?array
    {
        $sensor = $this->jobAttemptSensor ??= new JobAttemptSensor(
            commandState: $this->executionState, // @phpstan-ignore argument.type
            clock: $this->clock,
            connectionConfig: $this->config->get('queue.connections') ?? [],
        );

        return $sensor($event);
    }

    /**
     * @return array<mixed>
     */
    public function scheduledTask(ScheduledTaskFinished|ScheduledTaskSkipped|ScheduledTaskFailed $event): array
    {
        $sensor = $this->scheduledTaskSensor ??= new ScheduledTaskSensor(
            commandState: $this->executionState, // @phpstan-ignore argument.type
            clock: $this->clock,
        );

        return $sensor($event);
    }

    /**
     * @return ?array<mixed>
     */
    public function user(): ?array
    {
        $sensor = $this->userSensor ??= new UserSensor(
            requestState: $this->executionState, // @phpstan-ignore argument.type
            clock: $this->clock,
        );

        return $sensor();
    }

    public function flush(): void
    {
        $this->cacheEventSensor = null;
        $this->exceptionSensor = null;
        $this->logSensor = null;
        $this->outgoingRequestSensor = null;
        $this->querySensor = null;
        $this->queuedJobSensor = null;
        $this->jobAttemptSensor = null;
        $this->notificationSensor = null;
        $this->mailSensor = null;
        $this->userSensor = null;
        $this->stageSensor = null;
        $this->scheduledTaskSensor = null;
        $this->requestSensor = null;
        $this->commandSensor = null;
    }
}

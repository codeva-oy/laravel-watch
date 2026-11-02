<?php

namespace Codeva\Watch\Hooks;

use Codeva\Watch\Core;
use Codeva\Watch\State\CommandState;
use Codeva\Watch\State\RequestState;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Monolog\Formatter\FormatterInterface;
use Monolog\Formatter\NormalizerFormatter;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Throwable;

/**
 * @internal
 */
final class LogRecordProcessor implements ProcessorInterface
{
    private FormatterInterface $formatter;

    /**
     * @param  Core<RequestState|CommandState>  $watch
     */
    public function __construct(
        private Core $watch,
        private string $dateFormat,
    ) {
        //
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        try {
            /** @var array<string, mixed> */
            $formatted = $this->formatter()->format($record);

            return $record->with(
                message: $formatted['message'] ?? '',
                context: $formatted['context'] ?? [],
                level: $record->level,
                channel: $formatted['channel'] ?? '',
                datetime: $record->datetime,
                extra: $formatted['extra'] ?? [],
            );
        } catch (Throwable $e) {
            $this->watch->report($e, handled: true);
        }

        return $record;
    }

    private function formatter(): FormatterInterface
    {
        return $this->formatter ??= new class($this->dateFormat) extends NormalizerFormatter
        {
            protected function formatDate(DateTimeInterface $date): string
            {
                return parent::formatDate(
                    DateTimeImmutable::createFromInterface($date)->setTimezone(new DateTimeZone('UTC'))
                );
            }
        };
    }
}

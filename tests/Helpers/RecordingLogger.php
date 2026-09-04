<?php

declare(strict_types=1);

namespace EuroSms\Tests\Helpers;

use OutOfBoundsException;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Stringable;

/**
 * A PSR-3 logger that keeps every record it is handed instead of writing it anywhere, so that a
 * test can ask what the library wrote rather than where it ended up.
 */
final class RecordingLogger implements LoggerInterface
{
    use LoggerTrait;

    /** @var list<array{level: string, message: string, context: array<string, mixed>}> $records */
    private array $records = [];

    /**
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    public function all(): array
    {
        return $this->records;
    }

    /**
     * Every record written at one level, in the order they were written.
     * @param string $level
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    public function byLevel(string $level): array
    {
        return array_values(array_filter($this->records, static fn (array $record): bool => $level === $record['level']));
    }

    /**
     * Everything that was handed over, as one string, so that a test can ask whether a secret is
     * anywhere in the log at all rather than in the one field it thought of.
     * @return string
     */
    public function dump(): string
    {
        return (string)json_encode($this->records);
    }

    /**
     * The record written at one level, counted from the first one.
     * @param string $level
     * @param int $index
     * @return array{level: string, message: string, context: array<string, mixed>}
     */
    public function record(string $level, int $index = 0): array
    {
        $records = $this->byLevel($level);

        if (!isset($records[$index])) {
            throw new OutOfBoundsException(sprintf('No record number %d written at the %s level.', $index, $level));
        }

        return $records[$index];
    }

    /**
     * @param mixed $level
     * @param string|Stringable $message
     * @param array<string, mixed> $context
     * @return void
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => is_scalar($level) ? (string)$level : '',
            'message' => (string)$message,
            'context' => $context
        ];
    }
}

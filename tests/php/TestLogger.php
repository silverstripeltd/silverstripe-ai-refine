<?php

namespace SilverstripeLtd\AiBrandVoice\Tests;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * In-memory logger used to assert queued job logging in tests.
 */
class TestLogger extends AbstractLogger
{
    /**
     * @var list<array{level: string, message: string, context: array<mixed>}>
     */
    public array $records = [];

    /**
     * Records each log call so tests can inspect the emitted messages.
     */
    public function log($level, Stringable|string $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}

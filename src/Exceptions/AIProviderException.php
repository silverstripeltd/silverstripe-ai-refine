<?php

namespace SilverstripeLtd\AiRefine\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Represents an AI provider failure and whether it should stop processing.
 */
class AIProviderException extends RuntimeException
{
    private bool $fatal;

    /**
     * Creates a provider exception and records whether processing should stop.
     */
    public function __construct(
        string $message,
        bool $fatal = false,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->fatal = $fatal;
    }

    /**
     * Indicates whether the failure should stop further queued processing.
     */
    public function isFatal(): bool
    {
        return $this->fatal;
    }
}

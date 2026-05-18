<?php

namespace SilverstripeLtd\AiRefine\ValueObjects;

/**
 * Shared rating and reasoning subset used by refine evaluation results.
 */
class RefineRatingResult
{
    /**
     * Stores the shared rating and reasoning summary returned by evaluation calls.
     */
    public function __construct(
        public readonly string $rating = '',
        public readonly string $reasoningSummary = ''
    ) {
    }
}

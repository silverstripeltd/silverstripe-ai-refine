<?php

namespace SilverstripeLtd\AiBrandVoice\ValueObjects;

/**
 * Shared rating and reasoning subset used by brand voice evaluation results.
 */
class BrandVoiceRatingResult
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

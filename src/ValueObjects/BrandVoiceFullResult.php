<?php

namespace SilverstripeLtd\AiBrandVoice\ValueObjects;

/**
 * Shared evaluation result returned for brand voice checks.
 */
class BrandVoiceFullResult extends BrandVoiceRatingResult
{
    /**
     * Stores a complete evaluation result with any structured rewrite suggestions.
     */
    public function __construct(
        string $rating = '',
        string $reasoningSummary = '',
        public readonly array $suggestions = []
    ) {
        parent::__construct($rating, $reasoningSummary);
    }
}

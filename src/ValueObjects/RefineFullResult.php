<?php

namespace SilverstripeLtd\AiRefine\ValueObjects;

/**
 * Shared evaluation result returned for refine checks.
 */
class RefineFullResult extends RefineRatingResult
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

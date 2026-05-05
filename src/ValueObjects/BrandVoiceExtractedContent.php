<?php

namespace SilverstripeLtd\AiBrandVoice\ValueObjects;

/**
 * Extracted content and its exact hash.
 */
class BrandVoiceExtractedContent
{
    /**
     * Stores extracted content, its hash, the read mode, and the available rewrite targets.
     */
    public function __construct(
        public readonly string $content,
        public readonly string $hash,
        public readonly string $mode,
        public readonly array $rewriteTargets = []
    ) {
    }

    /**
     * Reports whether the extracted payload has any usable text content.
     */
    public function isEmpty(): bool
    {
        return trim($this->content) === '';
    }
}

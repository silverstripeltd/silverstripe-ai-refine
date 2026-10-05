<?php

namespace SilverstripeLtd\AiRefine\ValueObjects;

/**
 * Summary of which selected suggestions were written to draft content and which were skipped.
 */
class RefineApplyResult
{
    /**
     * Stores the applied and skipped counts alongside the keys and reasons behind them.
     *
     * @param array<int, string> $appliedTargetKeys
     * @param array<int, array{index: int|string, reason: string, targetKey: ?string}> $skippedSuggestions
     */
    public function __construct(
        public readonly int $appliedCount = 0,
        public readonly int $skippedCount = 0,
        public readonly array $appliedTargetKeys = [],
        public readonly array $skippedSuggestions = []
    ) {
    }

    /**
     * Reports whether at least one suggestion changed draft content.
     */
    public function hasAppliedChanges(): bool
    {
        return $this->appliedCount > 0;
    }

    /**
     * Converts the result into the JSON payload shape returned by the apply endpoint.
     */
    public function toArray(): array
    {
        return [
            'appliedCount' => $this->appliedCount,
            'skippedCount' => $this->skippedCount,
            'reloadRequired' => $this->hasAppliedChanges(),
        ];
    }
}

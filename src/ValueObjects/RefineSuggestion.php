<?php

namespace SilverstripeLtd\AiRefine\ValueObjects;

/**
 * A structured rewrite suggestion returned for a specific target.
 */
class RefineSuggestion
{
    /**
     * Stores one structured rewrite suggestion returned by a provider.
     */
    public function __construct(
        public readonly string $targetKey,
        public readonly string $targetType,
        public readonly string $fieldName,
        public readonly ?int $targetId,
        public readonly string $sourceContent,
        public readonly string $suggestedContent,
        public readonly string $contentFormat = 'text',
        public readonly string $fieldLabel = '',
        public readonly string $targetTitle = '',
        public readonly string $diffSourceContent = ''
    ) {
    }

    /**
     * Copies the suggestion and fills in the resolved local target metadata.
     */
    public function withResolvedTarget(RefineRewriteTarget $target): self
    {
        return new self(
            $this->targetKey,
            $this->targetType,
            $target->fieldName,
            $target->targetId,
            $target->sourceContent,
            $this->suggestedContent,
            $target->getContentFormat(),
            $target->fieldLabel,
            $target->targetTitle,
            $target->getDiffSourceContent()
        );
    }

    /**
     * Returns the source HTML used for diff generation, with a fallback to plain source content.
     */
    public function getDiffSourceContent(): string
    {
        return $this->diffSourceContent !== '' ? $this->diffSourceContent : $this->sourceContent;
    }

    /**
     * Converts the suggestion into the JSON payload shape used by the modal.
     */
    public function toArray(): array
    {
        return [
            'targetKey' => $this->targetKey,
            'targetType' => $this->targetType,
            'targetId' => $this->targetId,
            'fieldName' => $this->fieldName,
            'sourceContent' => $this->sourceContent,
            'suggestedContent' => $this->suggestedContent,
            'contentFormat' => $this->contentFormat,
            'fieldLabel' => $this->fieldLabel,
            'targetTitle' => $this->targetTitle,
        ];
    }
}

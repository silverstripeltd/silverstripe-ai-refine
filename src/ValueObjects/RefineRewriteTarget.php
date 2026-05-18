<?php

namespace SilverstripeLtd\AiRefine\ValueObjects;

/**
 * A server-known rewrite target that can safely receive AI suggestions.
 */
class RefineRewriteTarget
{
    public const TYPE_PAGE_TITLE = 'page_title';
    public const TYPE_PAGE_CONTENT = 'page_content';
    public const TYPE_ELEMENT_HTML = 'element_html';
    public const TYPE_ELEMENT_TEXT = 'element_text';

    /**
     * Stores the server-known metadata for one rewriteable content target.
     */
    public function __construct(
        public readonly string $targetKey,
        public readonly string $targetType,
        public readonly string $fieldName,
        public readonly ?int $targetId,
        public readonly string $sourceContent,
        public readonly string $fieldLabel = '',
        public readonly string $targetTitle = '',
        public readonly string $diffSourceContent = ''
    ) {
    }

    /**
     * Validates whether a target type is one of the supported rewrite categories.
     */
    public static function isValidTargetType(string $targetType): bool
    {
        return in_array($targetType, [
            self::TYPE_PAGE_TITLE,
            self::TYPE_PAGE_CONTENT,
            self::TYPE_ELEMENT_HTML,
            self::TYPE_ELEMENT_TEXT,
        ], true);
    }

    /**
     * Reports whether this target expects HTML content rather than plain text.
     */
    public function isHtmlTarget(): bool
    {
        return in_array($this->targetType, [self::TYPE_PAGE_CONTENT, self::TYPE_ELEMENT_HTML], true);
    }

    /**
     * Returns the content format label sent to provider prompts and modal payloads.
     */
    public function getContentFormat(): string
    {
        return $this->isHtmlTarget() ? 'html' : 'text';
    }

    /**
     * Returns the best source content to use when generating a diff preview.
     */
    public function getDiffSourceContent(): string
    {
        return $this->diffSourceContent !== '' ? $this->diffSourceContent : $this->sourceContent;
    }

    /**
     * Reports whether a target type belongs to an Elemental block field.
     */
    public static function isElementTargetType(string $targetType): bool
    {
        return in_array($targetType, [self::TYPE_ELEMENT_HTML, self::TYPE_ELEMENT_TEXT], true);
    }

    /**
     * Converts the target into the prompt payload sent to providers.
     */
    public function toPromptPayload(): array
    {
        return [
            'targetKey' => $this->targetKey,
            'targetType' => $this->targetType,
            'targetId' => $this->targetId,
            'fieldName' => $this->fieldName,
            'contentFormat' => $this->getContentFormat(),
            'sourceContent' => $this->sourceContent,
        ];
    }
}

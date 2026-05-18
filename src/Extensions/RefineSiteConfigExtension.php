<?php

namespace SilverstripeLtd\AiRefine\Extensions;

use SilverStripe\Core\Extension;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\TextareaField;

/**
 * Adds refine configuration to SiteConfig.
 */
class RefineSiteConfigExtension extends Extension
{
    private const REFINE_HELP = 'Define how AI should refine your content, including voice, style, and content rules. '
        . 'This is used by AI whenever content is reviewed and refined. You can draft refine guidance with AI tools '
        . 'and paste it here.';

    private const REFINE_PLACEHOLDER = <<<'TEXT'
Our refine guidance keeps content professional yet approachable.
We write in plain English and avoid jargon, acronyms, and overly technical language unless absolutely necessary.

Tone: Confident, helpful, and warm. We speak as a knowledgeable friend, not a faceless corporation.

Audience: General public. Assume no prior expertise. If a concept needs explaining, explain it simply.

Style rules:
- Use active voice over passive voice
- Keep sentences short and scannable
- Use "you" and "we" to speak directly to the reader
- Avoid cliches and marketing buzzwords
- Be specific rather than vague - use concrete examples where possible

Content structure:
- Lead with the most important information
- Use headings and bullet points to break up long text
- Every page should have a clear purpose and call to action
TEXT;

    private const EMPTY_STATE_MESSAGE = 'No refine has been defined. Configure your refine in '
        . 'Settings > Refine.';

    private static $db = [
        'RefineDefinition' => 'Text',
    ];

    /**
     * Adds the refine guidance field to Site Settings.
     */
    public function updateCMSFields(FieldList $fields): void
    {
        $fields->findOrMakeTab('Root.Refine', 'Refine');

        $field = TextareaField::create(
            'RefineDefinition',
            'Refine guidance'
        )
            ->setDescription(self::REFINE_HELP)
            ->setRows(18)
            ->setAttribute('placeholder', $this->getRefineDefinitionPlaceholder());

        $fields->addFieldToTab('Root.Refine', $field);
    }

    /**
     * Normalises saved refine text before it is written.
     */
    public function onBeforeWrite(): void
    {
        $this->owner->RefineDefinition = $this->normaliseRefineDefinition(
            (string) $this->owner->RefineDefinition
        );
    }

    /**
     * Validates refine length constraints before the record is saved.
     */
    public function updateValidate(ValidationResult $result): void
    {
        $value = $this->normaliseRefineDefinition((string) $this->owner->RefineDefinition);
        $length = mb_strlen($value);

        if ($value === '') {
            return;
        }

        if ($length < 50) {
            $result->addError('Refine guidance must be at least 50 characters long.');
            return;
        }

        if ($length > 10000) {
            $result->addError('Refine guidance must be 10,000 characters or fewer.');
        }
    }

    /**
     * Reports whether Site Settings currently has a usable refine guidance.
     */
    public function hasRefineDefinition(): bool
    {
        return $this->normaliseRefineDefinition((string) $this->owner->RefineDefinition) !== '';
    }

    /**
     * Returns the example content shown when the refine field is empty.
     */
    public function getRefineDefinitionPlaceholder(): string
    {
        return self::REFINE_PLACEHOLDER;
    }

    /**
     * Returns the CMS message shown when no refine has been configured.
     */
    public function getRefineEmptyStateMessage(): string
    {
        return self::EMPTY_STATE_MESSAGE;
    }

    /**
     * Trims and normalises refine text into a stable stored format.
     */
    public function normaliseRefineDefinition(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace(
            '/[\x{0009}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}]/u',
            ' ',
            $value
        ) ?? $value;
        $value = preg_replace('/^[ ]+/m', '', $value) ?? $value;
        $value = preg_replace("/\n{3,}/", "\n\n", $value) ?? $value;
        return trim($value);
    }
}

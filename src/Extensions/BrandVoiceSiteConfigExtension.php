<?php

namespace SilverstripeLtd\AiBrandVoice\Extensions;

use SilverStripe\Core\Extension;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\TextareaField;

/**
 * Adds brand voice configuration to SiteConfig.
 */
class BrandVoiceSiteConfigExtension extends Extension
{
    private const BRAND_VOICE_HELP = 'Define your brand\'s tone of voice, writing style, and content guidelines. '
        . 'This will be used by AI to evaluate page content for compliance. You can generate a brand voice guide '
        . 'using ChatGPT or similar tools and paste it here.';

    private const BRAND_VOICE_PLACEHOLDER = <<<'TEXT'
Our brand voice is professional yet approachable.
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

    private const EMPTY_STATE_MESSAGE = 'No brand voice has been defined. Configure your brand voice in '
        . 'Settings > Brand Voice.';

    private static $db = [
        'BrandVoiceDefinition' => 'Text',
    ];

    /**
     * Adds the brand voice definition field to Site Settings.
     */
    public function updateCMSFields(FieldList $fields): void
    {
        $fields->findOrMakeTab('Root.BrandVoice', 'Brand Voice');

        $field = TextareaField::create(
            'BrandVoiceDefinition',
            'Brand Voice Definition'
        )
            ->setDescription(self::BRAND_VOICE_HELP)
            ->setRows(18)
            ->setAttribute('placeholder', $this->getBrandVoiceDefinitionPlaceholder());

        $fields->addFieldToTab('Root.BrandVoice', $field);
    }

    /**
     * Normalises saved brand voice text before it is written.
     */
    public function onBeforeWrite(): void
    {
        $this->owner->BrandVoiceDefinition = $this->normaliseBrandVoiceDefinition(
            (string) $this->owner->BrandVoiceDefinition
        );
    }

    /**
     * Validates brand voice length constraints before the record is saved.
     */
    public function updateValidate(ValidationResult $result): void
    {
        $value = $this->normaliseBrandVoiceDefinition((string) $this->owner->BrandVoiceDefinition);
        $length = mb_strlen($value);

        if ($value === '') {
            return;
        }

        if ($length < 50) {
            $result->addError('Brand Voice Definition must be at least 50 characters long.');
            return;
        }

        if ($length > 10000) {
            $result->addError('Brand Voice Definition must be 10,000 characters or fewer.');
        }
    }

    /**
     * Reports whether Site Settings currently has a usable brand voice definition.
     */
    public function hasBrandVoiceDefinition(): bool
    {
        return $this->normaliseBrandVoiceDefinition((string) $this->owner->BrandVoiceDefinition) !== '';
    }

    /**
     * Returns the example content shown when the brand voice field is empty.
     */
    public function getBrandVoiceDefinitionPlaceholder(): string
    {
        return self::BRAND_VOICE_PLACEHOLDER;
    }

    /**
     * Returns the CMS message shown when no brand voice has been configured.
     */
    public function getBrandVoiceEmptyStateMessage(): string
    {
        return self::EMPTY_STATE_MESSAGE;
    }

    /**
     * Trims and normalises brand voice text into a stable stored format.
     */
    public function normaliseBrandVoiceDefinition(string $value): string
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

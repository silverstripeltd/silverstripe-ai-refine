<?php

namespace SilverstripeLtd\AiRefine\Tests\Extensions;

use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\TextareaField;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * Covers SiteConfig refine field behaviour.
 */
class RefineSiteConfigExtensionTest extends SapphireTest
{
    protected static $extra_dataobjects = [
        SiteConfig::class,
    ];

    /**
     * Clears the refine definition after each test.
     */
    protected function tearDown(): void
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->RefineDefinition = '';
        $siteConfig->write();

        parent::tearDown();
    }

    /**
     * Confirms saved refine text is normalised on write.
     */
    public function testRefineDefinitionNormalisesOnWrite(): void
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->RefineDefinition = " \tOur refine uses clear language.\u{00A0}\n\n\n"
            . "    We keep paragraphs short and useful.\n\tWe avoid jargon whenever possible.  ";
        $siteConfig->write();

        $siteConfig = SiteConfig::current_site_config();

        $this->assertSame(
            "Our refine uses clear language. \n\n"
            . "We keep paragraphs short and useful.\n"
            . "We avoid jargon whenever possible.",
            $siteConfig->RefineDefinition
        );
    }

    /**
     * Confirms validation rejects refine definitions that are too short.
     */
    public function testRefineDefinitionValidationRejectsShortValues(): void
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->RefineDefinition = 'Too short';

        $validation = $siteConfig->validate();

        $this->assertFalse($validation->isValid());
        $this->assertStringContainsString(
            'at least 50 characters',
            $validation->getMessages()[0]['message']
        );
    }

    /**
     * Confirms the helper methods recognise whether a usable definition exists.
     */
    public function testHasRefineDefinitionHelper(): void
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->RefineDefinition = '   ';

        $this->assertFalse($siteConfig->hasRefineDefinition());

        $siteConfig->RefineDefinition = 'Our content is direct, helpful, human, and easy to scan for everyone.';

        $this->assertTrue($siteConfig->hasRefineDefinition());
        $this->assertStringContainsString(
            'No refine has been defined',
            $siteConfig->getRefineEmptyStateMessage()
        );
    }

    /**
     * Confirms the CMS exposes the refine textarea with guidance text.
     */
    public function testCmsFieldsIncludeRefineDefinitionTextarea(): void
    {
        $siteConfig = SiteConfig::current_site_config();
        $fields = $siteConfig->getCMSFields();
        $field = $fields->dataFieldByName('RefineDefinition');

        $this->assertInstanceOf(TextareaField::class, $field);
        $this->assertSame('Writing style and tone rules', $field->Title());
        $this->assertStringContainsString(
            'Define how AI should refine your content',
            (string) $field->getDescription()
        );
        $this->assertStringContainsString(
            'Our refine guidance keeps content professional yet approachable.',
            (string) $field->getAttribute('placeholder')
        );
    }
}

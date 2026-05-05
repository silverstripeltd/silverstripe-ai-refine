<?php

namespace SilverstripeLtd\AiBrandVoice\Tests\Extensions;

use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\TextareaField;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * Covers SiteConfig brand voice field behaviour.
 */
class BrandVoiceSiteConfigExtensionTest extends SapphireTest
{
    protected static $extra_dataobjects = [
        SiteConfig::class,
    ];

    /**
     * Clears the brand voice definition after each test.
     */
    protected function tearDown(): void
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->BrandVoiceDefinition = '';
        $siteConfig->write();

        parent::tearDown();
    }

    /**
     * Confirms saved brand voice text is normalised on write.
     */
    public function testBrandVoiceDefinitionNormalisesOnWrite(): void
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->BrandVoiceDefinition = " \tOur brand voice uses clear language.\u{00A0}\n\n\n"
            . "    We keep paragraphs short and useful.\n\tWe avoid jargon whenever possible.  ";
        $siteConfig->write();

        $siteConfig = SiteConfig::current_site_config();

        $this->assertSame(
            "Our brand voice uses clear language. \n\n"
            . "We keep paragraphs short and useful.\n"
            . "We avoid jargon whenever possible.",
            $siteConfig->BrandVoiceDefinition
        );
    }

    /**
     * Confirms validation rejects brand voice definitions that are too short.
     */
    public function testBrandVoiceDefinitionValidationRejectsShortValues(): void
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->BrandVoiceDefinition = 'Too short';

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
    public function testHasBrandVoiceDefinitionHelper(): void
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->BrandVoiceDefinition = '   ';

        $this->assertFalse($siteConfig->hasBrandVoiceDefinition());

        $siteConfig->BrandVoiceDefinition = 'Our content is direct, helpful, human, and easy to scan for everyone.';

        $this->assertTrue($siteConfig->hasBrandVoiceDefinition());
        $this->assertStringContainsString(
            'No brand voice has been defined',
            $siteConfig->getBrandVoiceEmptyStateMessage()
        );
    }

    /**
     * Confirms the CMS exposes the brand voice textarea with guidance text.
     */
    public function testCmsFieldsIncludeBrandVoiceDefinitionTextarea(): void
    {
        $siteConfig = SiteConfig::current_site_config();
        $fields = $siteConfig->getCMSFields();
        $field = $fields->dataFieldByName('BrandVoiceDefinition');

        $this->assertInstanceOf(TextareaField::class, $field);
        $this->assertSame('Brand Voice Definition', $field->Title());
        $this->assertStringContainsString(
            'Define your brand\'s tone of voice',
            (string) $field->getDescription()
        );
        $this->assertStringContainsString(
            'Our brand voice is professional yet approachable.',
            (string) $field->getAttribute('placeholder')
        );
    }
}

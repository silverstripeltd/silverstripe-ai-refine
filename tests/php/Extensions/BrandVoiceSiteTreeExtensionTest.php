<?php

namespace SilverstripeLtd\AiBrandVoice\Tests\Extensions;

use SilverstripeLtd\AiBrandVoice\Models\BrandVoiceAnalysis;
use SilverstripeLtd\AiBrandVoice\Tests\RestrictedBrandVoicePage;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\HiddenField;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * Covers SiteTree brand voice extension behaviour.
 */
class BrandVoiceSiteTreeExtensionTest extends SapphireTest
{
    protected static $extra_dataobjects = [
        BrandVoiceAnalysis::class,
        RestrictedBrandVoicePage::class,
        SiteConfig::class,
    ];

    /**
     * Logs in an admin so CMS field assertions can run.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->logInWithPermission('ADMIN');
    }

    /**
     * Clears the configured brand voice after each test.
     */
    protected function tearDown(): void
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->BrandVoiceDefinition = '';
        $siteConfig->write();

        parent::tearDown();
    }

    /**
     * Confirms a new analysis record is created with the expected polymorphic parent link.
     */
    public function testGetOrCreateBrandVoiceAnalysisCreatesPolymorphicRecord(): void
    {
        $page = SiteTree::create(['Title' => 'Brand voice page']);
        $page->write();

        $analysis = $page->getOrCreateBrandVoiceAnalysis();

        $this->assertTrue($analysis->exists());
        $this->assertSame($page->ID, $analysis->ParentID);
        $this->assertSame($page->ClassName, $analysis->ParentClass);
        $this->assertSame($analysis->ID, $page->getBrandVoiceAnalysis()->ID);
    }

    /**
     * Confirms CMS context fields are added even before a brand voice is configured.
     */
    public function testCmsFieldsAddToolbarContextWithoutBrandVoiceDefinition(): void
    {
        $page = SiteTree::create(['Title' => 'Button test']);
        $page->write();

        $fields = $page->getCMSFields();
        $recordClass = $fields->dataFieldByName('AiBrandVoiceRecordClass');
        $actions = $page->getCMSActions();

        $this->assertInstanceOf(HiddenField::class, $recordClass);
        $this->assertSame($page->ClassName, $recordClass->dataValue());
        $this->assertNull($actions->fieldByName('MajorActions')->fieldByName('action_BrandVoiceAction'));
    }

    /**
     * Confirms CMS context fields remain available when a brand voice is configured.
     */
    public function testCmsFieldsAddToolbarContextWhenBrandVoiceConfigured(): void
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->BrandVoiceDefinition = 'We write in clear, practical, friendly language '
            . 'and keep every page easy to follow.';
        $siteConfig->write();

        $page = SiteTree::create(['Title' => 'Button test']);
        $page->write();

        $fields = $page->getCMSFields();
        $recordClass = $fields->dataFieldByName('AiBrandVoiceRecordClass');

        $this->assertInstanceOf(HiddenField::class, $recordClass);
        $this->assertSame($page->ClassName, $recordClass->dataValue());
    }

    /**
     * Confirms CMS context fields are omitted when the current record cannot be edited.
     */
    public function testCmsFieldsHideToolbarContextWhenRecordCannotEdit(): void
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->BrandVoiceDefinition = 'We write in clear, practical, friendly language '
            . 'and keep every page easy to follow.';
        $siteConfig->write();

        $page = RestrictedBrandVoicePage::create(['Title' => 'Restricted button test']);
        $page->write();

        $fields = $page->getCMSFields();
        $actions = $page->getCMSActions();

        $this->assertNull($fields->dataFieldByName('AiBrandVoiceRecordClass'));
        $this->assertNull($actions->fieldByName('MajorActions')->fieldByName('action_BrandVoiceAction'));
    }
}

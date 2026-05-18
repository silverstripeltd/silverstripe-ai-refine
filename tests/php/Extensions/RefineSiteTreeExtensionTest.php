<?php

namespace SilverstripeLtd\AiRefine\Tests\Extensions;

use SilverstripeLtd\AiRefine\Models\RefineAnalysis;
use SilverstripeLtd\AiRefine\Tests\RestrictedRefinePage;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\HiddenField;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * Covers SiteTree refine extension behaviour.
 */
class RefineSiteTreeExtensionTest extends SapphireTest
{
    protected static $extra_dataobjects = [
        RefineAnalysis::class,
        RestrictedRefinePage::class,
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
     * Clears the configured refine after each test.
     */
    protected function tearDown(): void
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->RefineDefinition = '';
        $siteConfig->write();

        parent::tearDown();
    }

    /**
     * Confirms a new analysis record is created with the expected polymorphic parent link.
     */
    public function testGetOrCreateRefineAnalysisCreatesPolymorphicRecord(): void
    {
        $page = SiteTree::create(['Title' => 'Refine page']);
        $page->write();

        $analysis = $page->getOrCreateRefineAnalysis();

        $this->assertTrue($analysis->exists());
        $this->assertSame($page->ID, $analysis->ParentID);
        $this->assertSame($page->ClassName, $analysis->ParentClass);
        $this->assertSame($analysis->ID, $page->getRefineAnalysis()->ID);
    }

    /**
     * Confirms CMS context fields are added even before a refine is configured.
     */
    public function testCmsFieldsAddToolbarContextWithoutRefineDefinition(): void
    {
        $page = SiteTree::create(['Title' => 'Button test']);
        $page->write();

        $fields = $page->getCMSFields();
        $recordClass = $fields->dataFieldByName('AiRefineRecordClass');
        $actions = $page->getCMSActions();

        $this->assertInstanceOf(HiddenField::class, $recordClass);
        $this->assertSame($page->ClassName, $recordClass->dataValue());
        $this->assertNull($actions->fieldByName('MajorActions')->fieldByName('action_RefineAction'));
    }

    /**
     * Confirms CMS context fields remain available when a refine is configured.
     */
    public function testCmsFieldsAddToolbarContextWhenRefineConfigured(): void
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->RefineDefinition = 'We write in clear, practical, friendly language '
            . 'and keep every page easy to follow.';
        $siteConfig->write();

        $page = SiteTree::create(['Title' => 'Button test']);
        $page->write();

        $fields = $page->getCMSFields();
        $recordClass = $fields->dataFieldByName('AiRefineRecordClass');

        $this->assertInstanceOf(HiddenField::class, $recordClass);
        $this->assertSame($page->ClassName, $recordClass->dataValue());
    }

    /**
     * Confirms CMS context fields are omitted when the current record cannot be edited.
     */
    public function testCmsFieldsHideToolbarContextWhenRecordCannotEdit(): void
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->RefineDefinition = 'We write in clear, practical, friendly language '
            . 'and keep every page easy to follow.';
        $siteConfig->write();

        $page = RestrictedRefinePage::create(['Title' => 'Restricted button test']);
        $page->write();

        $fields = $page->getCMSFields();
        $actions = $page->getCMSActions();

        $this->assertNull($fields->dataFieldByName('AiRefineRecordClass'));
        $this->assertNull($actions->fieldByName('MajorActions')->fieldByName('action_RefineAction'));
    }
}

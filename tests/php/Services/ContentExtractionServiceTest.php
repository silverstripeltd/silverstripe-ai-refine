<?php

namespace SilverstripeLtd\AiRefine\Tests\Services;

use DNADesign\Elemental\Extensions\ElementalPageExtension;
use DNADesign\Elemental\Models\ElementContent;
use SilverstripeLtd\AiRefine\Services\ContentExtractionService;
use SilverstripeLtd\AiRefine\Tests\CETestDraftDiffPage;
use SilverstripeLtd\AiRefine\Tests\CETestElementalPage;
use SilverstripeLtd\AiRefine\Tests\CETestExtension;
use SilverstripeLtd\AiRefine\Tests\CETestHiddenElement;
use SilverstripeLtd\AiRefine\Tests\CETestRecord;
use SilverstripeLtd\AiRefine\Tests\CETestUntemplatedBlock;
use SilverstripeLtd\AiRefine\ValueObjects\RefineRewriteTarget;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;

/**
 * Tests content extraction behaviour for Live and Draft reads.
 */
class ContentExtractionServiceTest extends SapphireTest
{
    protected static $extra_dataobjects = [
        CETestRecord::class,
        CETestDraftDiffPage::class,
        CETestElementalPage::class,
        CETestHiddenElement::class,
        CETestUntemplatedBlock::class,
        ElementContent::class,
    ];

    protected static $required_extensions = [
        CETestElementalPage::class => [
            ElementalPageExtension::class,
        ],
    ];

    /**
     * Authenticates as a CMS user so interactive Elemental canView() checks behave like the modal.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->logInWithPermission('ADMIN');
    }

    /**
     * Clears content extraction extensions after each test.
     */
    protected function tearDown(): void
    {
        Config::modify()->set(ContentExtractionService::class, 'extensions', []);

        parent::tearDown();
    }

    /**
     * Confirms draft extraction reads the latest draft state.
     */
    public function testExtractForDraftCheckUsesDraftVersion(): void
    {
        $service = new ContentExtractionService();
        $page = SiteTree::create([
            'Title' => 'Live title',
            'Content' => '<p>Live content</p>',
        ]);
        $page->write();
        $page->publishSingle();

        $page->Title = 'Draft title';
        $page->Content = '<p>Draft content</p>';
        $page->write();

        $result = $service->extractForDraftCheck($page);

        $this->assertSame(ContentExtractionService::READ_MODE_DRAFT, $result->mode);
        $this->assertStringContainsString('Draft title', $result->content);
        $this->assertStringContainsString('Draft content', $result->content);
        $this->assertStringNotContainsString('Live title', $result->content);
        $this->assertSame(md5($result->content), $result->hash);
        $this->assertCount(2, $result->rewriteTargets);
        $this->assertSame('page:title', $result->rewriteTargets[0]->targetKey);
        $this->assertSame('Page name', $result->rewriteTargets[0]->fieldLabel);
        $this->assertSame('', $result->rewriteTargets[0]->targetTitle);
        $this->assertSame(RefineRewriteTarget::TYPE_PAGE_CONTENT, $result->rewriteTargets[1]->targetType);
        $this->assertSame('Draft content', $result->rewriteTargets[1]->sourceContent);
        $this->assertSame('<p>Draft content</p>', $result->rewriteTargets[1]->getDiffSourceContent());
        $this->assertSame('Content', $result->rewriteTargets[1]->fieldLabel);
    }

    /**
     * Confirms live extraction reads the published state and applies extensions.
     */
    public function testExtractForLiveAnalysisUsesLiveVersion(): void
    {
        Config::modify()->merge(ContentExtractionService::class, 'extensions', [
            CETestExtension::class,
        ]);

        $service = new ContentExtractionService();
        $page = CETestDraftDiffPage::create([
            'Title' => 'Live title',
            'Content' => '<p>Live content</p>',
        ]);
        $page->write();
        $page->publishSingle();

        $page->Title = 'Draft title';
        $page->Content = '<p>Draft content</p>';
        $page->write();

        $result = $service->extractForLiveAnalysis($page);

        $this->assertSame(ContentExtractionService::READ_MODE_LIVE, $result->mode);
        $this->assertStringContainsString('Live title', $result->content);
        $this->assertStringContainsString('Live content', $result->content);
        $this->assertStringContainsString('Appended from extension', $result->content);
        $this->assertStringNotContainsString('Draft content', $result->content);
    }

    /**
     * Confirms live extraction skips draft-only pages with no published version.
     */
    public function testExtractForLiveAnalysisSkipsDraftOnlyPages(): void
    {
        $service = new ContentExtractionService();
        $page = SiteTree::create([
            'Title' => 'Draft title',
            'Content' => '<p>Draft content</p>',
        ]);
        $page->write();

        $this->assertNull($service->extractForLiveAnalysis($page));
    }

    /**
     * Confirms non-versioned records fall back to search content extraction.
     */
    public function testExtractForNonVersionedRecordUsesSearchContent(): void
    {
        Config::modify()->merge(ContentExtractionService::class, 'extensions', [
            CETestExtension::class,
        ]);

        $service = new ContentExtractionService();
        $record = CETestRecord::create([
            'Title' => 'Record title',
            'Content' => '<p>Fallback HTML</p>',
        ]);
        $record->write();

        $result = $service->extractForLiveAnalysis($record);

        $this->assertStringContainsString('Record title', $result->content);
        $this->assertStringContainsString('Elemental content', $result->content);
        $this->assertStringContainsString('Appended from extension', $result->content);
        $this->assertStringNotContainsString('Fallback HTML', $result->content);
        $this->assertSame(md5($result->content), $result->hash);
        $this->assertCount(2, $result->rewriteTargets);
        $this->assertSame('page:title', $result->rewriteTargets[0]->targetKey);
        $this->assertSame('page:content', $result->rewriteTargets[1]->targetKey);
        $this->assertSame('Fallback HTML', $result->rewriteTargets[1]->sourceContent);
        $this->assertSame('<p>Fallback HTML</p>', $result->rewriteTargets[1]->getDiffSourceContent());
    }

    /**
     * Confirms draft extraction builds structured rewrite targets for Elemental blocks.
     */
    public function testExtractForDraftCheckBuildsStructuredElementTargets(): void
    {
        Versioned::set_stage(Versioned::DRAFT);

        $service = new ContentExtractionService();
        $page = CETestElementalPage::create([
            'Title' => 'Elemental title',
            'Content' => '<p>Legacy content</p>',
        ]);
        $page->write();

        $page->ElementalArea()->Elements()->add(ElementContent::create([
            'HTML' => '<p>First block</p>',
        ]));
        $page->ElementalArea()->Elements()->add(ElementContent::create([
            'HTML' => '<p>Second <strong>block</strong></p>',
        ]));

        $result = $service->extractForDraftCheck($page);

        $this->assertStringContainsString('Elemental title', $result->content);
        $this->assertStringContainsString('First block', $result->content);
        $this->assertMatchesRegularExpression('/Second\\s+block/', $result->content);
        $this->assertCount(3, $result->rewriteTargets);
        $this->assertSame('page:title', $result->rewriteTargets[0]->targetKey);
        $this->assertStringStartsWith('element:', $result->rewriteTargets[1]->targetKey);
        $this->assertSame(RefineRewriteTarget::TYPE_ELEMENT_HTML, $result->rewriteTargets[1]->targetType);
        $this->assertSame('HTML', $result->rewriteTargets[1]->fieldName);
        $this->assertSame('First block', $result->rewriteTargets[1]->sourceContent);
        $this->assertSame('<p>First block</p>', $result->rewriteTargets[1]->getDiffSourceContent());
        $this->assertSame('Second *block*', $result->rewriteTargets[2]->sourceContent);
        $this->assertSame(
            '<p>Second <strong>block</strong></p>',
            $result->rewriteTargets[2]->getDiffSourceContent()
        );
        $this->assertStringNotContainsString('Legacy content', implode(' ', array_map(
            static fn(RefineRewriteTarget $target): string => $target->sourceContent,
            $result->rewriteTargets
        )));
    }

    /**
     * Confirms interactive draft extraction excludes Elemental blocks that fail canView().
     */
    public function testExtractForDraftCheckExcludesHiddenElementTargets(): void
    {
        Versioned::set_stage(Versioned::DRAFT);

        $service = new ContentExtractionService();
        $page = CETestElementalPage::create([
            'Title' => 'Permission filtered page',
        ]);
        $page->write();

        $visibleElement = ElementContent::create([
            'HTML' => '<p>Visible block</p>',
        ]);
        $hiddenElement = CETestHiddenElement::create([
            'HTML' => '<p>Hidden block</p>',
        ]);
        $page->ElementalArea()->Elements()->add($visibleElement);
        $page->ElementalArea()->Elements()->add($hiddenElement);

        $result = $service->extractForDraftCheck($page);
        $targetKeys = array_map(
            static fn(RefineRewriteTarget $target): string => $target->targetKey,
            $result->rewriteTargets
        );
        $targetSources = array_map(
            static fn(RefineRewriteTarget $target): string => $target->sourceContent,
            $result->rewriteTargets
        );

        $this->assertSame([
            'page:title',
            sprintf('element:%d:html', $visibleElement->ID),
        ], $targetKeys);
        $this->assertContains('Visible block', $targetSources);
        $this->assertNotContains('Hidden block', $targetSources);
    }

    /**
     * Confirms template-free Elemental blocks fall back to CMS search extraction.
     */
    public function testExtractForDraftCheckFallsBackToTemplateFreeElementalSearch(): void
    {
        Versioned::set_stage(Versioned::DRAFT);

        $service = new ContentExtractionService();
        $page = CETestElementalPage::create([
            'Title' => 'Fallback title',
            'Content' => '<p>Legacy fallback</p>',
        ]);
        $page->write();

        $page->ElementalArea()->Elements()->add(CETestUntemplatedBlock::create([
            'Title' => 'My content block',
            'MyField' => 'Untemplated block title',
            'MyBigField' => 'Untemplated block copy',
        ]));
        $page->ElementalArea()->Elements()->add(ElementContent::create([
            'HTML' => '<p>Supported block</p>',
        ]));

        $result = $service->extractForDraftCheck($page);

        $this->assertStringContainsString('Fallback title', $result->content);
        $this->assertStringContainsString('Untemplated block title', $result->content);
        $this->assertStringContainsString('Untemplated block copy', $result->content);
        $this->assertStringContainsString('Supported block', $result->content);
        $this->assertStringNotContainsString('Legacy fallback', $result->content);
        $this->assertCount(4, $result->rewriteTargets);
        $this->assertSame('page:title', $result->rewriteTargets[0]->targetKey);
        $this->assertSame(RefineRewriteTarget::TYPE_ELEMENT_TEXT, $result->rewriteTargets[1]->targetType);
        $this->assertSame('MyField', $result->rewriteTargets[1]->fieldName);
        $this->assertSame('My field', $result->rewriteTargets[1]->fieldLabel);
        $this->assertSame('My content block', $result->rewriteTargets[1]->targetTitle);
        $this->assertSame('Untemplated block title', $result->rewriteTargets[1]->sourceContent);
        $this->assertSame(RefineRewriteTarget::TYPE_ELEMENT_TEXT, $result->rewriteTargets[2]->targetType);
        $this->assertSame('MyBigField', $result->rewriteTargets[2]->fieldName);
        $this->assertSame('My big field', $result->rewriteTargets[2]->fieldLabel);
        $this->assertSame('My content block', $result->rewriteTargets[2]->targetTitle);
        $this->assertSame('Untemplated block copy', $result->rewriteTargets[2]->sourceContent);
        $this->assertSame(RefineRewriteTarget::TYPE_ELEMENT_HTML, $result->rewriteTargets[3]->targetType);
        $this->assertSame('HTML', $result->rewriteTargets[3]->fieldName);
        $this->assertSame('HTML', $result->rewriteTargets[3]->fieldLabel);
        $this->assertSame('Content', $result->rewriteTargets[3]->targetTitle);
        $this->assertSame('Supported block', $result->rewriteTargets[3]->sourceContent);
        $this->assertSame('<p>Supported block</p>', $result->rewriteTargets[3]->getDiffSourceContent());
    }

    /**
     * Confirms extraction extensions can add extra rewrite targets.
     */
    public function testExtractForDraftCheckAppliesRewriteTargetExtensions(): void
    {
        Config::modify()->merge(ContentExtractionService::class, 'extensions', [
            CETestExtension::class,
        ]);

        $service = new ContentExtractionService();
        $page = SiteTree::create([
            'Title' => 'Extended page',
            'Content' => '<p>Draft content</p>',
        ]);
        $page->write();

        $result = $service->extractForDraftCheck($page);

        $this->assertCount(3, $result->rewriteTargets);
        $this->assertSame('extension:summary', $result->rewriteTargets[2]->targetKey);
        $this->assertSame(RefineRewriteTarget::TYPE_PAGE_CONTENT, $result->rewriteTargets[2]->targetType);
        $this->assertSame('Content', $result->rewriteTargets[2]->fieldName);
        $this->assertSame($page->ID, $result->rewriteTargets[2]->targetId);
        $this->assertSame('Extension supplied summary', $result->rewriteTargets[2]->sourceContent);
        $this->assertSame('Extension summary', $result->rewriteTargets[2]->fieldLabel);
        $this->assertSame('Extension provided target', $result->rewriteTargets[2]->targetTitle);
    }
}

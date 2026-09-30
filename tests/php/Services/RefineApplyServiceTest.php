<?php

namespace SilverstripeLtd\AiRefine\Tests\Services;

use DNADesign\Elemental\Extensions\ElementalPageExtension;
use DNADesign\Elemental\Models\ElementContent;
use SilverstripeLtd\AiRefine\Exceptions\RefineApplyException;
use SilverstripeLtd\AiRefine\Services\ContentExtractionService;
use SilverstripeLtd\AiRefine\Services\RefineApplyService;
use SilverstripeLtd\AiRefine\Tests\CETestElementalPage;
use SilverstripeLtd\AiRefine\Tests\CETestLockedElement;
use SilverstripeLtd\AiRefine\Tests\TestLogger;
use SilverstripeLtd\AiRefine\ValueObjects\RefineApplyResult;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

/**
 * Exercises draft apply writes for page fields and Elemental blocks.
 */
class RefineApplyServiceTest extends SapphireTest
{
    protected static $extra_dataobjects = [
        CETestElementalPage::class,
        CETestLockedElement::class,
        ElementContent::class,
    ];

    protected static $required_extensions = [
        CETestElementalPage::class => [
            ElementalPageExtension::class,
        ],
    ];

    private TestLogger $logger;

    /**
     * Authenticates as a CMS user so Elemental permission checks behave like the modal.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->logInWithPermission('ADMIN');
        $this->logger = new TestLogger();
    }

    /**
     * Confirms page suggestions are written to draft only and live content stays untouched.
     */
    public function testApplyToDraftWritesPageSuggestionsToDraftOnly(): void
    {
        $page = $this->createPage('Original title', '<p>Original body</p>');
        $page->publishSingle();

        $result = $this->createService()->applyToDraft($this->getDraftRecord(SiteTree::class, $page->ID), [
            [
                'apply' => true,
                'targetKey' => 'page:title',
                'targetType' => 'page_title',
                'targetId' => $page->ID,
                'fieldName' => 'Title',
                'suggestedContent' => 'Updated title',
            ],
            [
                'apply' => true,
                'targetKey' => 'page:content',
                'targetType' => 'page_content',
                'fieldName' => 'Content',
                'suggestedContent' => '<p>Updated body</p>',
            ],
        ]);

        $this->assertInstanceOf(RefineApplyResult::class, $result);
        $this->assertSame(2, $result->appliedCount);
        $this->assertSame(0, $result->skippedCount);
        $this->assertSame(['page:title', 'page:content'], $result->appliedTargetKeys);
        $this->assertSame([], $result->skippedSuggestions);
        $this->assertTrue($result->hasAppliedChanges());
        $draftPage = $this->getDraftRecord(SiteTree::class, $page->ID);
        $this->assertSame('Updated title', $draftPage->Title);
        $this->assertSame('<p>Updated body</p>', $draftPage->Content);
        $livePage = $this->getLiveRecord(SiteTree::class, $page->ID);
        $this->assertSame('Original title', $livePage->Title);
        $this->assertSame('<p>Original body</p>', $livePage->Content);
    }

    /**
     * Confirms only suggestions flagged for apply are written and unflagged ones are ignored.
     */
    public function testApplyToDraftIgnoresSuggestionsNotFlaggedForApply(): void
    {
        $page = $this->createPage('Original title', '<p>Original body</p>');

        $result = $this->createService()->applyToDraft($page, [
            [
                'apply' => '0',
                'targetKey' => 'page:title',
                'suggestedContent' => 'Updated title',
            ],
            [
                'rewrite' => '1',
                'targetKey' => 'page:content',
                'suggestedContent' => '<p>Updated body</p>',
            ],
            [
                'targetKey' => 'page:title',
                'suggestedContent' => 'No flag at all',
            ],
        ]);

        $this->assertSame(1, $result->appliedCount);
        $this->assertSame(0, $result->skippedCount);
        $this->assertSame(['page:content'], $result->appliedTargetKeys);
        $draftPage = $this->getDraftRecord(SiteTree::class, $page->ID);
        $this->assertSame('Original title', $draftPage->Title);
        $this->assertSame('<p>Updated body</p>', $draftPage->Content);
    }

    /**
     * Confirms an Elemental block suggestion is sanitised and written to the block's draft content.
     */
    public function testApplyToDraftWritesElementSuggestions(): void
    {
        $page = $this->createElementalPage('Elemental page', ['<p>Current block</p>']);
        $element = $page->ElementalArea()->Elements()->first();
        $targetKey = sprintf('element:%d:html', $element->ID);

        $result = $this->createService()->applyToDraft($page, [
            [
                'apply' => true,
                'targetKey' => $targetKey,
                'targetType' => 'element_html',
                'targetId' => $element->ID,
                'fieldName' => 'HTML',
                'suggestedContent' => '<p onclick="alert(1)">Updated <strong>block</strong></p>',
            ],
        ]);

        $this->assertSame(1, $result->appliedCount);
        $this->assertSame([$targetKey], $result->appliedTargetKeys);
        $updatedElement = $this->getDraftRecord(ElementContent::class, $element->ID);
        $this->assertSame('<p>Updated <strong>block</strong></p>', $updatedElement->HTML);
    }

    /**
     * Confirms HTML fields keep safe markup while plain text fields have tags stripped.
     */
    public function testApplyToDraftSanitisesSuggestedContentPerFieldType(): void
    {
        $page = $this->createPage('Original title', '<p>Original body</p>');

        $result = $this->createService()->applyToDraft($page, [
            [
                'apply' => true,
                'targetKey' => 'page:title',
                'suggestedContent' => '<strong>Updated</strong> title<script>alert(1)</script>',
            ],
            [
                'apply' => true,
                'targetKey' => 'page:content',
                'suggestedContent' => '<p>Updated <em>body</em></p><script>alert(1)</script>',
            ],
        ]);

        $this->assertSame(2, $result->appliedCount);
        $draftPage = $this->getDraftRecord(SiteTree::class, $page->ID);
        $this->assertSame('Updated titlealert(1)', $draftPage->Title);
        $this->assertSame('<p>Updated <em>body</em></p>', $draftPage->Content);
    }

    /**
     * Confirms unknown, duplicate, malformed and mismatched entries are skipped with a recorded reason.
     */
    public function testApplyToDraftSkipsInvalidEntriesAndReportsReasons(): void
    {
        $page = $this->createPage('Original title', '<p>Original body</p>');

        $result = $this->createService()->applyToDraft($page, [
            'not-an-array',
            [
                'apply' => true,
                'targetKey' => '',
                'suggestedContent' => 'Missing key',
            ],
            [
                'apply' => true,
                'targetKey' => 'page:unknown',
                'suggestedContent' => 'Unknown target',
            ],
            [
                'apply' => true,
                'targetKey' => 'page:title',
            ],
            [
                'apply' => true,
                'targetKey' => 'page:content',
                'targetType' => 'page_title',
                'suggestedContent' => '<p>Mismatched type</p>',
            ],
            [
                'apply' => true,
                'targetKey' => 'page:title',
                'suggestedContent' => 'Applied title',
            ],
            [
                'apply' => true,
                'targetKey' => 'page:title',
                'suggestedContent' => 'Duplicate title',
            ],
        ]);

        $this->assertSame(1, $result->appliedCount);
        $this->assertSame(6, $result->skippedCount);
        $this->assertSame(['page:title'], $result->appliedTargetKeys);
        $this->assertSame([
            ['index' => 0, 'reason' => 'invalid-payload', 'targetKey' => null],
            ['index' => 1, 'reason' => 'missing-target-key', 'targetKey' => null],
            ['index' => 2, 'reason' => 'mismatched-target', 'targetKey' => 'page:unknown'],
            ['index' => 3, 'reason' => 'missing-suggested-content', 'targetKey' => 'page:title'],
            ['index' => 4, 'reason' => 'target-metadata-mismatch', 'targetKey' => 'page:content'],
            ['index' => 6, 'reason' => 'duplicate-target', 'targetKey' => 'page:title'],
        ], $result->skippedSuggestions);
        $this->assertCount(6, $this->logger->records);
        $draftPage = $this->getDraftRecord(SiteTree::class, $page->ID);
        $this->assertSame('Applied title', $draftPage->Title);
        $this->assertSame('<p>Original body</p>', $draftPage->Content);
    }

    /**
     * Confirms deleted and foreign blocks are skipped without touching valid targets.
     */
    public function testApplyToDraftSkipsDeletedAndForeignElements(): void
    {
        $page = $this->createElementalPage('Elemental page', ['<p>Current block</p>', '<p>Deleted block</p>']);
        $currentElement = $page->ElementalArea()->Elements()->sort('ID')->first();
        $deletedElement = $page->ElementalArea()->Elements()->sort('ID')->last();
        $deletedTargetKey = sprintf('element:%d:html', $deletedElement->ID);
        $deletedElement->delete();
        $foreignPage = $this->createElementalPage('Foreign page', ['<p>Foreign block</p>']);
        $foreignElement = $foreignPage->ElementalArea()->Elements()->first();

        $result = $this->createService()->applyToDraft($page, [
            [
                'apply' => true,
                'targetKey' => sprintf('element:%d:html', $currentElement->ID),
                'suggestedContent' => '<p>Updated current block</p>',
            ],
            [
                'apply' => true,
                'targetKey' => $deletedTargetKey,
                'targetType' => 'element_html',
                'targetId' => $deletedElement->ID,
                'suggestedContent' => '<p>Updated deleted block</p>',
            ],
            [
                'apply' => true,
                'targetKey' => sprintf('element:%d:html', $foreignElement->ID),
                'targetType' => 'element_html',
                'targetId' => $foreignElement->ID,
                'suggestedContent' => '<p>Updated foreign block</p>',
            ],
        ]);

        $this->assertSame(1, $result->appliedCount);
        $this->assertSame(2, $result->skippedCount);
        $reasons = array_column($result->skippedSuggestions, 'reason');
        $this->assertSame(['deleted-target', 'foreign-target'], $reasons);
        $this->assertSame(
            '<p>Updated current block</p>',
            $this->getDraftRecord(ElementContent::class, $currentElement->ID)->HTML
        );
        $this->assertSame(
            '<p>Foreign block</p>',
            $this->getDraftRecord(ElementContent::class, $foreignElement->ID)->HTML
        );
    }

    /**
     * Confirms a block that denies editing aborts the whole apply before anything is written.
     */
    public function testApplyToDraftThrowsWhenTargetElementCannotBeEdited(): void
    {
        $page = Versioned::withVersionedMode(function (): CETestElementalPage {
            Versioned::set_stage(Versioned::DRAFT);
            $page = CETestElementalPage::create(['Title' => 'Locked page']);
            $page->write();
            $page->ElementalArea()->Elements()->add(CETestLockedElement::create(['HTML' => '<p>Locked block</p>']));
            return DataObject::get(CETestElementalPage::class)->byID($page->ID);
        });
        $lockedElement = $page->ElementalArea()->Elements()->first();
        $service = $this->createService();
        $suggestions = [
            [
                'apply' => true,
                'targetKey' => 'page:title',
                'suggestedContent' => 'Updated locked page',
            ],
            [
                'apply' => true,
                'targetKey' => sprintf('element:%d:html', $lockedElement->ID),
                'suggestedContent' => '<p>Updated locked block</p>',
            ],
        ];

        $exception = null;
        try {
            $service->applyToDraft($page, $suggestions);
        } catch (RefineApplyException $caught) {
            $exception = $caught;
        }

        $this->assertInstanceOf(RefineApplyException::class, $exception);
        $this->assertSame('Locked page', $this->getDraftRecord(CETestElementalPage::class, $page->ID)->Title);
        $this->assertSame(
            '<p>Locked block</p>',
            $this->getDraftRecord(CETestLockedElement::class, $lockedElement->ID)->HTML
        );
    }

    /**
     * Confirms an empty suggestion list applies nothing and leaves the record unchanged.
     */
    public function testApplyToDraftWithNoSuggestionsAppliesNothing(): void
    {
        $page = $this->createPage('Original title', '<p>Original body</p>');
        $lastEdited = $page->LastEdited;

        $result = $this->createService()->applyToDraft($page, []);

        $this->assertSame(0, $result->appliedCount);
        $this->assertSame(0, $result->skippedCount);
        $this->assertFalse($result->hasAppliedChanges());
        $this->assertSame(
            ['appliedCount' => 0, 'skippedCount' => 0, 'reloadRequired' => false],
            $result->toArray()
        );
        $this->assertSame($lastEdited, $this->getDraftRecord(SiteTree::class, $page->ID)->LastEdited);
    }

    /**
     * Builds the service under test with the shared extraction service and capturing logger.
     */
    private function createService(): RefineApplyService
    {
        return new RefineApplyService(new ContentExtractionService(), $this->logger);
    }

    /**
     * Creates a simple draft page fixture.
     */
    private function createPage(string $title, string $content): SiteTree
    {
        $page = SiteTree::create([
            'Title' => $title,
            'Content' => $content,
        ]);
        $page->write();
        return $page;
    }

    /**
     * Creates a draft Elemental page populated with the supplied HTML blocks.
     */
    private function createElementalPage(string $title, array $blocks): CETestElementalPage
    {
        return Versioned::withVersionedMode(function () use ($title, $blocks): CETestElementalPage {
            Versioned::set_stage(Versioned::DRAFT);
            $page = CETestElementalPage::create(['Title' => $title]);
            $page->write();
            foreach ($blocks as $html) {
                $page->ElementalArea()->Elements()->add(ElementContent::create(['HTML' => $html]));
            }
            return DataObject::get(CETestElementalPage::class)->byID($page->ID);
        });
    }

    /**
     * Loads a record from the draft stage.
     */
    private function getDraftRecord(string $className, int $id): ?DataObject
    {
        return Versioned::withVersionedMode(function () use ($className, $id): ?DataObject {
            Versioned::set_stage(Versioned::DRAFT);
            return DataObject::get($className)->byID($id);
        });
    }

    /**
     * Loads a record from the live stage.
     */
    private function getLiveRecord(string $className, int $id): ?DataObject
    {
        return Versioned::withVersionedMode(function () use ($className, $id): ?DataObject {
            Versioned::set_stage(Versioned::LIVE);
            return DataObject::get($className)->byID($id);
        });
    }
}

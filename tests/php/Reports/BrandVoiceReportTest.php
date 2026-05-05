<?php

namespace SilverstripeLtd\AiBrandVoice\Tests\Reports;

use SilverstripeLtd\AiBrandVoice\Models\BrandVoiceAnalysis;
use SilverstripeLtd\AiBrandVoice\Reports\BrandVoiceReport;
use SilverstripeLtd\AiBrandVoice\Services\ContentExtractionService;
use SilverstripeLtd\AiBrandVoice\Tests\RestrictedBrandVoicePage;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Model\List\PaginatedList;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * Covers the brand voice CMS report.
 */
class BrandVoiceReportTest extends SapphireTest
{
    protected static $extra_dataobjects = [
        BrandVoiceAnalysis::class,
        SiteConfig::class,
        RestrictedBrandVoicePage::class,
    ];

    /**
     * Logs in an admin and seeds a valid brand voice definition.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->logInWithPermission('ADMIN');
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->BrandVoiceDefinition = 'We write with a clear, practical, and steady voice that keeps '
            . 'content concise while still sounding helpful and human.';
        $siteConfig->write();
    }

    /**
     * Clears the configured brand voice after each report test.
     */
    protected function tearDown(): void
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->BrandVoiceDefinition = '';
        $siteConfig->write();

        parent::tearDown();
    }

    /**
     * Confirms the report sorts ratings correctly and applies each filter mode.
     */
    public function testReportSortsWorstFirstAndFiltersRatings(): void
    {
        $poor = $this->createPageWithAnalysis('Alpha poor', 'Poor', str_repeat('Poor reasoning. ', 20));
        $needsWork = $this->createPageWithAnalysis('Bravo needs work', 'NeedsWork', 'Needs work reasoning');
        $adequate = $this->createPageWithAnalysis('Charlie adequate', 'Adequate', 'Adequate reasoning');
        $good = $this->createPageWithAnalysis('Delta good', 'Good', 'Good reasoning');
        $excellent = $this->createPageWithAnalysis('Echo excellent', 'Excellent', 'Excellent reasoning');
        $outOfDate = $this->createPageWithAnalysis('Golf out of date', 'Good', 'Stale reasoning');
        $notAnalysed = SiteTree::create(['Title' => 'Foxtrot not analysed']);
        $notAnalysed->write();
        $outOfDate->Content = '<p>Updated draft copy</p>';
        $outOfDate->write();

        $report = new BrandVoiceReport();
        $rows = $report->sourceRecords(['Rating' => 'All'], null, ['limit' => 20, 'start' => 0]);

        $this->assertInstanceOf(PaginatedList::class, $rows);
        $this->assertSame([
            'Alpha poor',
            'Bravo needs work',
            'Charlie adequate',
            'Delta good',
            'Golf out of date',
            'Echo excellent',
            'Foxtrot not analysed',
        ], array_map(static fn($row) => $row->Title, $rows->toArray()));
        $this->assertSame('Needs work', $rows->toArray()[1]->Rating);
        $this->assertSame('Current', $rows->toArray()[0]->AnalysisStatus);
        $this->assertSame('Out of date', $rows->toArray()[4]->AnalysisStatus);
        $this->assertSame('Not analysed', $rows->toArray()[6]->Rating);
        $this->assertSame('Not analysed', $rows->toArray()[6]->AnalysisStatus);
        $this->assertSame(
            rtrim(mb_substr(str_repeat('Poor reasoning. ', 20), 0, 150)) . '...',
            $rows->toArray()[0]->Reasoning
        );
        $this->assertNotSame($poor->getBrandVoiceAnalysis()->ReasoningSummary, $rows->toArray()[0]->Reasoning);

        $needsWorkRows = $report->sourceRecords(['Rating' => 'NeedsWork'], null, ['limit' => 20, 'start' => 0]);
        $this->assertCount(1, $needsWorkRows);
        $this->assertSame('Bravo needs work', $needsWorkRows->toArray()[0]->Title);

        $notAnalysedRows = $report->sourceRecords(
            ['Rating' => 'NotAnalysed'],
            null,
            ['limit' => 20, 'start' => 0]
        );
        $this->assertCount(1, $notAnalysedRows);
        $this->assertSame('Foxtrot not analysed', $notAnalysedRows->toArray()[0]->Title);
        $this->assertSame('Never', $report->columns()['AnalysedAt']['formatting'](null));

        $this->assertNotNull($poor);
        $this->assertNotNull($needsWork);
        $this->assertNotNull($adequate);
        $this->assertNotNull($good);
        $this->assertNotNull($excellent);
        $this->assertNotNull($outOfDate);
    }

    /**
     * Confirms omitting pagination limits still returns every visible matching row.
     */
    public function testReportReturnsAllVisibleRowsWhenLimitIsOmitted(): void
    {
        for ($index = 1; $index <= 12; $index++) {
            $this->createPageWithAnalysis(sprintf('Paged good %02d', $index), 'Good', 'Good reasoning');
        }

        $report = new BrandVoiceReport();
        $rows = $report->sourceRecords(['Rating' => 'Good']);
        $createdRows = array_values(array_filter(
            $rows->toArray(),
            static fn($row): bool => str_starts_with($row->Title, 'Paged good ')
        ));

        $this->assertInstanceOf(PaginatedList::class, $rows);
        $this->assertCount(12, $createdRows);
        $this->assertGreaterThanOrEqual(12, $rows->getTotalItems());
        $this->assertSame('Paged good 01', $createdRows[0]->Title);
        $this->assertSame('Paged good 12', $createdRows[11]->Title);
    }

    /**
     * Confirms the report shows an empty-state banner when no brand voice is configured.
     */
    public function testReportShowsBannerWhenNoBrandVoiceConfigured(): void
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->BrandVoiceDefinition = '';
        $siteConfig->write();

        $report = new BrandVoiceReport();
        $fields = $report->getCMSFields();

        $banner = $fields->fieldByName('BrandVoiceBanner');
        $this->assertInstanceOf(LiteralField::class, $banner);
        $this->assertStringContainsString(
            'No brand voice has been defined. Configure your brand voice in Settings &gt; Brand Voice.',
            (string) $banner->getContent()
        );
        $this->assertNull($fields->fieldByName('Report'));
    }

    /**
     * Confirms visibility filtering happens after pagination metadata is calculated.
     */
    public function testReportAppliesCanViewFilteringAfterPagination(): void
    {
        $hidden = RestrictedBrandVoicePage::create(['Title' => 'AAA hidden needs work']);
        $hidden->write();
        $hiddenAnalysis = $hidden->getOrCreateBrandVoiceAnalysis();
        $hiddenAnalysis->Rating = 'NeedsWork';
        $hiddenAnalysis->ReasoningSummary = 'Hidden reasoning';
        $hiddenAnalysis->AnalysedAt = '2026-04-22 09:00:00';
        $hiddenAnalysis->write();

        $visibleFirstPage = $this->createPageWithAnalysis(
            'BBB visible needs work',
            'NeedsWork',
            'Visible reasoning'
        );
        $visibleSecondPage = $this->createPageWithAnalysis(
            'CCC visible needs work',
            'NeedsWork',
            'Visible reasoning'
        );

        $report = new BrandVoiceReport();
        $rows = $report->sourceRecords(['Rating' => 'NeedsWork'], null, ['limit' => 2, 'start' => 0]);
        $allRows = $report->sourceRecords(['Rating' => 'NeedsWork'], null, ['limit' => 50, 'start' => 0]);

        $this->assertCount(1, $rows);
        $this->assertSame($allRows->getTotalItems(), $rows->getTotalItems());
        $this->assertGreaterThanOrEqual(3, $rows->getTotalItems());
        $this->assertSame('BBB visible needs work', $rows->toArray()[0]->Title);
        $this->assertNotSame('CCC visible needs work', $rows->toArray()[0]->Title);

        $this->assertNotNull($visibleFirstPage);
        $this->assertNotNull($visibleSecondPage);
    }

    /**
     * Creates a page fixture with persisted brand voice analysis data.
     */
    private function createPageWithAnalysis(string $title, string $rating, string $reasoning): SiteTree
    {
        $page = SiteTree::create([
            'Title' => $title,
            'Content' => '<p>Published copy</p>',
        ]);
        $page->write();

        $analysis = $page->getOrCreateBrandVoiceAnalysis();
        $analysis->ContentHash = Injector::inst()
            ->get(ContentExtractionService::class)
            ->extractForDraftCheck($page)
            ->hash;
        $analysis->Rating = $rating;
        $analysis->ReasoningSummary = $reasoning;
        $analysis->AnalysedAt = '2026-04-22 09:00:00';
        $analysis->write();
        return $page;
    }
}

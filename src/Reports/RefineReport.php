<?php

namespace SilverstripeLtd\AiRefine\Reports;

use ArrayAccess;
use SilverstripeLtd\AiRefine\Models\RefineAnalysis;
use SilverstripeLtd\AiRefine\Services\ContentExtractionService;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\Model\List\PaginatedList;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataQuery;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Reports\Report;
use SilverStripe\Security\Security;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * CMS report for persisted refine compliance results.
 */
class RefineReport extends Report
{
    private const ANALYSIS_STATUS_CURRENT = 'Current';
    private const ANALYSIS_STATUS_OUT_OF_DATE = 'Out of date';
    private const ANALYSIS_STATUS_NOT_ANALYSED = 'Not analysed';
    private const FILTER_ALL = 'All';
    private const FILTER_NOT_ANALYSED = 'NotAnalysed';
    private const REASONING_LIMIT = 150;

    protected $title = 'Refine compliance';
    protected $description = 'Ratings are based on published (Live) page content, evaluated by a background job.';

    /**
     * Builds the report filter controls shown above the grid.
     */
    public function parameterFields(): FieldList
    {
        return FieldList::create(
            DropdownField::create(
                'Rating',
                'Rating',
                $this->getRatingFilterOptions()
            )->setValue(self::FILTER_ALL)
        );
    }

    /**
     * Returns either the standard report fields or the empty-state banner.
     */
    public function getCMSFields(): FieldList
    {
        if ($this->hasConfiguredRefine()) {
            return parent::getCMSFields();
        }

        $fields = FieldList::create();
        if ($description = $this->description()) {
            $fields->push(LiteralField::create('ReportDescription', '<p>' . $description . '</p>'));
        }

        $fields->push(LiteralField::create(
            'RefineBanner',
            sprintf(
                '<p class="message notice">%s</p>',
                Convert::raw2xml($this->getEmptyStateMessage())
            )
        ));
        return $fields;
    }

    /**
     * Defines the visible report columns and their formatting callbacks.
     */
    public function columns(): array
    {
        return [
            'Title' => [
                'title' => 'Page title',
                'formatting' => function (?string $value, ArrayData $item): string {
                    return sprintf(
                        '<a href="%s">%s</a>',
                        Convert::raw2att((string) $item->PageEditLink),
                        Convert::raw2xml((string) $value)
                    );
                },
            ],
            'Rating' => [
                'title' => 'Rating',
            ],
            'AnalysisStatus' => [
                'title' => 'Analysis status',
            ],
            'Reasoning' => [
                'title' => 'Reasoning',
            ],
            'AnalysedAt' => [
                'title' => 'Last analysed',
                'formatting' => function (?string $value): string {
                    if (!$value) {
                        return 'Never';
                    }

                    $field = DBDatetime::create();
                    $field->setValue($value);
                    return $field->Nice();
                },
            ],
        ];
    }

    /**
     * Builds the filtered report rows and wraps them in pagination metadata.
     */
    public function sourceRecords(array $params = [], ?string $sort = null, array|int|null $limit = null): PaginatedList
    {
        $request = Controller::curr() ? Controller::curr()->getRequest() : [];
        if (!$this->hasConfiguredRefine()) {
            return $this->createRowList(ArrayList::create(), $request ?? []);
        }

        $list = $this->applyRatingFilter(
            $this->getBaseList(),
            $params['Rating'] ?? self::FILTER_ALL
        )->sort([
            'RefineSort' => 'ASC',
            'Title' => 'ASC',
        ]);

        if ($limit === null) {
            return $this->createRowList(
                $this->getVisibleRows($list),
                $request ?? []
            );
        }

        $paginated = PaginatedList::create($list, $request ?? []);
        $this->applyLimit($paginated, $limit);
        return $this->createRowList(
            $this->getVisibleRows($paginated),
            $request ?? [],
            $paginated->getPageLength(),
            $paginated->getPageStart(),
            $paginated->getTotalItems()
        );
    }

    /**
     * Builds the base SiteTree query with joined analysis data and sort weighting.
     */
    private function getBaseList(): DataList
    {
        return SiteTree::get()
            ->leftJoin(
                'RefineAnalysis',
                '"RefineAnalysis"."ParentID" = "SiteTree"."ID"'
                . ' AND "RefineAnalysis"."ParentClass" = "SiteTree"."ClassName"'
            )
            ->alterDataQuery(function (DataQuery $query): void {
                $query->selectField('"RefineAnalysis"."Rating"', 'RefineAnalysisRating');
                $query->selectField('"RefineAnalysis"."ReasoningSummary"', 'RefineAnalysisReasoningSummary');
                $query->selectField('"RefineAnalysis"."AnalysedAt"', 'RefineAnalysisAnalysedAt');
                $query->selectField('"RefineAnalysis"."ContentHash"', 'RefineAnalysisContentHash');
                $query->selectField(
                    'CASE'
                    . ' WHEN "RefineAnalysis"."Rating" = \'Poor\' THEN 1'
                    . ' WHEN "RefineAnalysis"."Rating" = \'NeedsWork\' THEN 2'
                    . ' WHEN "RefineAnalysis"."Rating" = \'Adequate\' THEN 3'
                    . ' WHEN "RefineAnalysis"."Rating" = \'Good\' THEN 4'
                    . ' WHEN "RefineAnalysis"."Rating" = \'Excellent\' THEN 5'
                    . ' ELSE 6'
                    . ' END',
                    'RefineSort'
                );
            });
    }

    /**
     * Applies the requested rating filter to the report query.
     */
    private function applyRatingFilter(DataList $list, string $rating): DataList
    {
        if ($rating === self::FILTER_ALL || $rating === '') {
            return $list;
        }

        if ($rating === self::FILTER_NOT_ANALYSED) {
            return $list->where(
                '"RefineAnalysis"."ID" IS NULL'
                . ' OR "RefineAnalysis"."AnalysedAt" IS NULL'
                . ' OR "RefineAnalysis"."Rating" IS NULL'
            );
        }
        return $list
            ->where(['"RefineAnalysis"."Rating" = ?' => $rating])
            ->where('"RefineAnalysis"."AnalysedAt" IS NOT NULL');
    }

    /**
     * Applies a requested page length and offset to the paginated list.
     */
    private function applyLimit(PaginatedList $list, array|int|null $limit): void
    {
        if (is_array($limit) && isset($limit['limit'])) {
            $list->setPageLength((int) $limit['limit']);
            if (isset($limit['start'])) {
                $list->setPageStart((int) $limit['start']);
            }
            return;
        }

        if (is_int($limit)) {
            $list->setPageLength($limit);
        }
    }

    /**
     * Returns the dropdown options used for report rating filtering.
     */
    private function getRatingFilterOptions(): array
    {
        return RefineAnalysis::getRatingLabels() + [
            self::FILTER_NOT_ANALYSED => 'Not analysed',
            self::FILTER_ALL => 'All',
        ];
    }

    /**
     * Resolves the report label for a stored rating value.
     */
    private function getRatingLabel(string $rating, string $analysedAt): string
    {
        if ($rating === '' || $analysedAt === '') {
            return self::ANALYSIS_STATUS_NOT_ANALYSED;
        }
        return RefineAnalysis::getRatingLabel($rating);
    }

    /**
     * Determines whether the stored analysis matches the current draft content hash.
     */
    private function getAnalysisStatus(SiteTree $page, string $contentHash, string $analysedAt): string
    {
        if ($analysedAt === '') {
            return self::ANALYSIS_STATUS_NOT_ANALYSED;
        }

        $currentHash = $this->getContentExtractionService()->extractForDraftCheck($page)->hash;
        return $contentHash === $currentHash
            ? self::ANALYSIS_STATUS_CURRENT
            : self::ANALYSIS_STATUS_OUT_OF_DATE;
    }

    /**
     * Shortens long reasoning text so report rows stay scannable.
     */
    private function truncateReasoning(string $reasoning): string
    {
        $reasoning = trim($reasoning);
        if ($reasoning === '' || mb_strlen($reasoning) <= self::REASONING_LIMIT) {
            return $reasoning;
        }
        return rtrim(mb_substr($reasoning, 0, self::REASONING_LIMIT)) . '...';
    }

    /**
     * Wraps the final rows in a paginated list with explicit totals.
     */
    private function createRowList(
        ArrayList $rows,
        array|ArrayAccess $request,
        ?int $pageLength = null,
        ?int $pageStart = null,
        ?int $totalItems = null
    ): PaginatedList {
        $list = PaginatedList::create($rows, $request);
        $list->setLimitItems(false);
        $list->setPageStart($pageStart ?? 0);
        $list->setTotalItems($totalItems ?? $rows->count());
        $list->setPageLength($pageLength ?? max($rows->count(), 1));
        return $list;
    }

    /**
     * Filters visible pages and maps them into the report row payload.
     */
    private function getVisibleRows(iterable $pages): ArrayList
    {
        $currentUser = Security::getCurrentUser();
        $rows = [];
        foreach ($pages as $page) {
            if (!$page->canView($currentUser)) {
                continue;
            }

            $rows[] = ArrayData::create([
                'Title' => $this->getPageTitle($page),
                'PageEditLink' => $page->CMSEditLink(),
                'Rating' => $this->getRatingLabel(
                    (string) $page->getField('RefineAnalysisRating'),
                    (string) $page->getField('RefineAnalysisAnalysedAt')
                ),
                'Reasoning' => $this->truncateReasoning(
                    (string) $page->getField('RefineAnalysisReasoningSummary')
                ),
                'AnalysisStatus' => $this->getAnalysisStatus(
                    $page,
                    (string) $page->getField('RefineAnalysisContentHash'),
                    (string) $page->getField('RefineAnalysisAnalysedAt')
                ),
                'AnalysedAt' => $page->getField('RefineAnalysisAnalysedAt'),
            ]);
        }
        return ArrayList::create($rows);
    }

    /**
     * Resolves a readable page title for report rows.
     */
    private function getPageTitle(SiteTree $page): string
    {
        $title = trim((string) $page->Title);
        return $title !== '' ? $title : $page->ClassName;
    }

    /**
     * Reports whether Site Settings currently has a configured refine.
     */
    private function hasConfiguredRefine(): bool
    {
        $siteConfig = SiteConfig::current_site_config();
        return (bool) (
            $siteConfig
            && $siteConfig->hasMethod('hasRefineDefinition')
            && $siteConfig->hasRefineDefinition()
        );
    }

    /**
     * Returns the empty-state message shown when no refine is configured.
     */
    private function getEmptyStateMessage(): string
    {
        $siteConfig = SiteConfig::current_site_config();

        if ($siteConfig && $siteConfig->hasMethod('getRefineEmptyStateMessage')) {
            return $siteConfig->getRefineEmptyStateMessage();
        }
        return 'No refine has been defined. Configure your refine in Settings > Refine.';
    }

    /**
     * Resolves the extraction service used for stale-content checks.
     */
    private function getContentExtractionService(): ContentExtractionService
    {
        return Injector::inst()->get(ContentExtractionService::class);
    }
}

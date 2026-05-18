# CMS Report

## Overview

A Silverstripe CMS report showing refine compliance across the site. Accessible from the Reports section of the CMS. Aimed at website owners who want a high-level view of how well their content aligns with the defined refine.

## Report class

- Class: `RefineReport` (namespace: `SilverstripeLtd\AiRefine\Reports\RefineReport`)
- Extends: `SilverStripe\Reports\Report`
- Title: "Refine Compliance"
- Description: "Ratings are generated from published (Live) page content by a background job. Analysis status compares that saved result against the page's current CMS content."

## Columns

| Column | Description |
|--------|-------------|
| Page title | Linked to the page edit form in the CMS |
| Rating | One of: **Excellent**, **Good**, **Adequate**, **Needs work**, **Poor**, **Not analysed** |
| Analysis status | **Current** when the saved analysis hash matches the page's current CMS content hash, **Out of date** when it does not, otherwise **Not analysed**. The current hash uses the CMS draft-aware extraction path, so unpublished draft changes can mark a row out of date even when Live content is unchanged. |
| Reasoning | Truncated `ReasoningSummary` (first ~150 characters with ellipsis) |
| Last analysed | `AnalysedAt` datetime, or "Never" |

### Rating display

- "Needs work" is the display label for the `NeedsWork` enum value
- Ratings are displayed as plain text labels (no colour coding)

## Filtering

- Dropdown filter by rating: Excellent / Good / Adequate / Needs work / Poor / Not analysed / All
- Default view: **All**

## Sorting

- Default sort: Rating priority (Poor first, then Needs work, Adequate, Good, Excellent, Not analysed last), then page title alphabetically
- Worst-first ordering so the website owner sees the most problematic pages at the top

## Empty refine state

If `RefineDefinition` on SiteConfig is empty, the report shows an informational banner instead of the table:

> "No refine has been defined. Configure your refine in Settings > Refine."

## Permissions

- Visible to anyone with access to the CMS Reports section (standard Silverstripe permission: `CMS_ACCESS_ReportAdmin`)
- No additional permission codes needed
- **Per-page filtering:** After fetching the current page of results, remove any pages where `canView()` returns false for the current user. This is a post-fetch filter on the paginated set only (not applied to the full query). Some pages on a given pagination page may be hidden, resulting in fewer rows than the page size - this is acceptable.

## Data source

The report is driven from `SiteTree` records with an optional join to `RefineAnalysis`. Pages without an analysis record appear as "Not analysed" with empty reasoning and "Never" for last analysed. This ensures all pages are visible in the report, not just those the background job has processed.

Persisted ratings and reasoning still come from the background job's Live-content analysis. Only the "Analysis status" column is draft-aware.

## Performance

No AI calls happen at report load time, but the report does compute current CMS content hashes for the visible page of results so it can mark stale analyses. The background job still handles re-evaluation.

The report uses pagination (`PaginatedList`) for large sites, which keeps the hash comparison limited to the current page of rows.

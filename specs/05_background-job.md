# Background Job

## Overview

A `QueuedJob` subclass that bulk-evaluates brand voice compliance for pages. Uses the `symbiote/silverstripe-queuedjobs` module.

## Job class

- Class: `EvaluateBrandVoiceJob` (namespace: `SilverstripeLtd\AiBrandVoice\Jobs\EvaluateBrandVoiceJob`)
- Extends: `AbstractQueuedJob`
- Type: `QueuedJob::QUEUED`

## Not enabled by default

The job is **not** automatically scheduled. An administrator must manually create and schedule it via the Queued Jobs CMS interface.

## Pre-flight check

Before processing any pages, the job checks if `BrandVoiceDefinition` is set on `SiteConfig`. If empty, the job logs "No brand voice definition configured - skipping all pages" and completes without processing.

## Pages targeted

The job processes pages that meet either condition:

1. **Not analysed:** Page has no `BrandVoiceAnalysis` record, or `AnalysedAt` is null
2. **Stale:** Page has `BrandVoiceAnalysis` but the content hash no longer matches (content has changed since last analysis)

Pages are processed in `SiteTree.ID` order (deterministic, simple).
The job caps the number of pages per run via `AI_BRAND_VOICE_JOB_BATCH_SIZE` (default: 50).

## Content source

The background job only iterates over `SiteTree` records. It reads **Live** (published) content only via the content extraction pipeline (`specs/02_content-extraction.md`). Pages with no Live record (draft-only, never published) are **skipped**.

## Processing

For each page:

1. Read Live content via the content extraction pipeline (`specs/02_content-extraction.md`). Skip if the page has no Live record.
2. Compute MD5 hash of extracted content. Compare to stored `ContentHash`. Skip if unchanged (analysis is up-to-date).
3. Check if content is non-empty. If empty, store on the `BrandVoiceAnalysis` record: `GenerationNote` = "Insufficient content", `ContentHash` = MD5 of the empty string, `AnalysedAt` = current datetime, clear any previous `Rating` and `ReasoningSummary`. Skip to next page (do not call the AI provider). This ensures empty pages are treated as analysed and not retried every cycle - they will only be re-evaluated when their content changes (hash changes).
4. Call the AI provider with the shared **rewrite-aware** prompt (`specs/04_prompts.md`), passing the extracted content, page title, brand voice definition from SiteConfig, and any structured rewrite targets. Persist `rating` and `reasoningSummary`, and discard `suggestions`.
5. Store results on the `BrandVoiceAnalysis` record:
   - `Rating` - the compliance rating
   - `ReasoningSummary` - AI's explanation
   - `ContentHash` - MD5 of the Live content just evaluated
   - `AnalysedAt` - current datetime
   - Clear any previous `GenerationNote`
6. Wait for the configured delay before processing the next page.

This is an intentional tradeoff. Even though the background job does not store rewrites, including rewrite in the prompt produces better audit behaviour because the model surfaces gaps and weaker sections it can miss in a score-only prompt.

## Rate limiting

- Configurable delay between API calls: `AI_BRAND_VOICE_RATE_LIMIT_DELAY` environment variable (default: 6 seconds)

## Error handling

- **Non-fatal provider exceptions** (`AIProviderException` with `fatal = false`): Log the error, skip the page, continue to next. These are transient or page-specific failures (timeouts, rate limits, malformed responses).
- **Fatal provider exceptions** (`AIProviderException` with `fatal = true`): Job stops immediately. These indicate broken configuration (missing/invalid API key, authentication failure) that will affect every page. Error is visible in the Queued Jobs CMS interface. The job re-queues a fresh instance so it can be retried after configuration is fixed.
- **Other per-page errors:** Log the error, skip the page, continue to next.

## Logging

- Log each page processed (page ID, title, success/failure/skipped)
- Log summary at completion (total processed, succeeded, failed, skipped)
- Uses standard Silverstripe logging (PSR-3 `LoggerInterface`)

## Re-queue behaviour

At the end of a run, the job re-queues a fresh instance scheduled for a later run (default 8 hours via `AI_BRAND_VOICE_JOB_REQUEUE_DELAY`). This keeps periodic re-evaluation available without manual re-creation.

## Concurrency

- Only one instance of the job should run at a time (standard `QueuedJob` behaviour)
- On-demand checks and background job are independent (different content sources, different storage) so there are no concurrency conflicts between them

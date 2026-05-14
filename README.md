# AI Brand Voice Module for Silverstripe CMS

A Silverstripe CMS 6 module that lets users define a corporate content style guide and uses AI to evaluate whether page content adheres to it. The module analyses each page against the style guide and surfaces compliance status and recommendations in the CMS.

The background job and the on-demand modal intentionally use the same rewrite-aware evaluation prompt. The background job discards `suggestions` and stores only rating and reasoning, but keeping rewrite in the prompt produces stronger audit judgements than a cheaper score-only prompt. In the CMS, editors can review those structured suggestions and apply selected ones back to Draft content before the page reloads.

## Installation

This module is hosted on a private GitHub repository and is not listed on Packagist. To install it, add the following to your project's `composer.json`:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "git@github.com:silverstripeltd/silverstripe-ai-brand-voice.git"
        }
    ],
    // ...
    "require": {
        "silverstripeltd/ai-brand-voice": "dev-main"
    }
}
```

## Development

When working on this module, AI tools (e.g. Claude Code, Copilot) should be run from the **project root**, not from within this directory. The module's `CLAUDE.md` should be symlinked to the project root so that AI tools pick it up automatically:

```bash
cd path/to/project

if [ -f CLAUDE.md ] || [ -L CLAUDE.md ]; then rm -f CLAUDE.md; fi
ln -s vendor/silverstripeltd/ai-brand-voice/CLAUDE.md CLAUDE.md
```

`CLAUDE.md` contains the project identity, hard constraints, directory structure, and the module-specific testing, spec-editing, and command conventions in one place so the AI does not have to discover separate skill files at runtime.

Note that `CLAUDE.md` contains instructions for a specific Docker setup - you will probably need to update that file to match your local, standardised environment.

### Running tests and linting

From the project root:

- PHP unit tests:
  - `ssh webserver "cd /var/www && rm -rf /tmp/pu-cache && mkdir -p /tmp/pu-cache && SS_TEMP_PATH=/tmp/pu-cache nice -n 19 ionice -c 3 taskset -c 0 vendor/bin/phpunit vendor/silverstripeltd/ai-brand-voice/tests/ --fail-on-warning"`
- PHP linting:
  - `ssh webserver "cd /var/www/vendor/silverstripeltd/ai-brand-voice && nice -n 19 ionice -c 3 taskset -c 0 ../../bin/phpcs --ignore=*/thirdparty/*,*/node_modules/* --extensions=php ."`
- JS linting:
  - `ssh webserver "cd /var/www/vendor/silverstripeltd/ai-brand-voice && NODE_OPTIONS=--max-old-space-size=512 nice -n 19 ionice -c 3 taskset -c 0 yarn lint"`
- JS build:
  - `ssh webserver "cd /var/www/vendor/silverstripeltd/ai-brand-voice/client && NODE_OPTIONS=--max-old-space-size=512 nice -n 19 ionice -c 3 taskset -c 0 yarn install && NODE_OPTIONS=--max-old-space-size=512 nice -n 19 ionice -c 3 taskset -c 0 yarn build"`

## Configuration

All configuration is via environment variables (e.g. in your webserver env or `.env`). Restart your webserver after changing any values.

### Provider

Set the AI provider and API key. Gemini, OpenAI, and Anthropic are supported out of the box. Custom providers can be added by extending `AbstractAIProvider`.

```bash
AI_BRAND_VOICE_PROVIDER=gemini              # gemini (default), openai, or anthropic
AI_BRAND_VOICE_API_KEY=your-api-key         # API key for the chosen provider
```

### Model

Control which model is used and how it generates responses. All optional - sensible defaults are used if omitted.

```bash
AI_BRAND_VOICE_MODEL=gemini-3.1-flash-lite  # Model identifier (provider-specific)
AI_BRAND_VOICE_THINKING_LEVEL=low           # Thinking effort: none, low, medium, or high
AI_BRAND_VOICE_TEMPERATURE=0.0              # Sampling temperature (0.0–1.0)
AI_BRAND_VOICE_MAX_TOKENS=20000             # Max tokens in AI response
AI_BRAND_VOICE_REQUEST_TIMEOUT=15           # Timeout per AI request in seconds
```

`AI_BRAND_VOICE_TEMPERATURE` defaults to `0.0` on purpose. This module is primarily an auditing and compliance tool, so repeatable ratings are preferred over creative variation or reroll-style behaviour. If a project wants looser, more exploratory responses, it can still override the value explicitly.

`AI_BRAND_VOICE_MAX_TOKENS` defaults to a higher value because both the background job and the on-demand modal use the same rewrite-aware prompt. That costs more tokens, but it is a deliberate tradeoff because omitting rewrite changes the model's audit behaviour and makes the results weaker.

### Queued jobs

The `GenerateAiBrandVoiceJob` processes pages in batches. These settings control rate limiting and scheduling.

```bash
AI_BRAND_VOICE_RATE_LIMIT_DELAY=6           # Min seconds between API request starts (see below)
AI_BRAND_VOICE_JOB_BATCH_SIZE=50            # Max pages processed per job run
AI_BRAND_VOICE_JOB_REQUEUE_DELAY=28800      # Seconds before scheduling next run (default: 8 hours)
```

The rate limit delay is measured from the **start** of each API request, not from when it finishes. If a request takes longer than the delay, the next request starts immediately with no extra wait.

## Usage

### SiteConfig dependency

Brand Voice depends on `SiteConfig.BrandVoiceDefinition`. Editors configure this in **Settings > Brand Voice**, and both the background job and the on-demand modal use that same definition as the source of truth.

If the definition is empty:

- the background job skips all pages
- the CMS report shows a setup banner instead of compliance data
- the toolbar button still opens the modal so editors see the missing-configuration guidance instead of a silently missing action

### On-demand workflow

The on-demand workflow is a review-and-apply modal aimed at CMS editors working on a specific page:

1. Open the page in the CMS and click **Brand Voice** in the preview toolbar.
2. Click **Check Brand Voice** to evaluate the page's saved Draft content against the configured brand voice.
3. Review the returned rating, reasoning summary, and per-target diff cards.
4. Select only the suggestions you want to accept.
5. Click **Apply suggestions** to write those changes back to Draft content.

The modal does not edit suggestion text inline and does not persist on-demand results to `BrandVoiceAnalysis`. Instead, it acts as a focused review surface for applying selected rewrites to the current page draft.

### Draft and Live behaviour

This module intentionally uses Draft and Live differently depending on the workflow:

- **Background analysis and report data** are based on published **Live** content. The background job skips draft-only pages and stores ratings for the public version of the site.
- **The on-demand modal** reads saved **Draft** content so editors can review and improve unpublished changes before publishing.
- **Apply** writes selected suggestions back to **Draft** only. It never publishes content.
- **The CMS report** still compares the saved analysis to the page's current CMS content hash, so unpublished draft edits can mark a page as out of date even when the stored rating came from Live.

This split is intentional: site owners get a report about what is currently published, while editors get an on-demand tool for improving what they are about to publish.

## CMS report

The **Brand Voice Compliance** report gives website owners and CMS administrators a worst-first overview of how published content aligns with the configured brand voice.

- Ratings and reasoning come from the background job's Live-content analysis.
- The **Analysis status** column compares that stored analysis against the page's current CMS content hash, so draft edits can show a row as **Out of date** before anything is published.
- If no brand voice has been configured in Site Settings, the report shows an informational setup banner instead of the table.

Together, the report and modal cover two different jobs: the report audits the published site, while the modal supports page-by-page editorial review and application on Draft.

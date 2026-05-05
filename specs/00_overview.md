# System Overview

One-page summary of the AI brand voice module architecture. Read this first, then dive into individual specs.

## What it does

Lets CMS users define a corporate content style guide (brand voice) and uses AI to evaluate page content against it. Results are surfaced in the CMS via a report (for website owners) and an on-demand check (for content editors). This is primarily an **evaluation** module - it assesses existing content, returns structured rewrite suggestions, and can write reviewed suggestions back to **Draft** content on the server. It never publishes content automatically.

## Two entry points

1. **CMS report** (website owner) - shows all pages with their brand voice compliance rating, pre-computed by a background job against **Live** content. The stored rating reflects published content, while the report's "Analysis status" compares that saved result against the page's current CMS content so unpublished draft changes can still mark a row "Out of date". Gives a high-level view of how aligned the site is with the defined brand voice.
2. **On-demand check** (content editor) - button on the page edit form opens a modal. Editor clicks a button to trigger an AI evaluation of their **Draft** content. The modal shows rating, reasoning, and per-target suggestions for the page title, page content, and supported text or HTML fields on Elemental blocks. Results are cached on the Entwine instance for the editing session, not persisted to DB, and selected suggestions can be applied back to Draft records before the CMS reloads.

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│ SiteConfig                                                  │
│                                                             │
│  Brand Voice Definition (free-text field)                   │
└──────────────────────────────┬──────────────────────────────┘
                               │
               ┌───────────────┴───────────────┐
               ▼                               ▼
┌──────────────────────────┐   ┌──────────────────────────────┐
│ Background Job           │   │ On-demand Check (CMS modal)  │
│ (specs/05)               │   │ (specs/07, specs/08)         │
│                          │   │                              │
│ Reads LIVE content       │   │ Reads DRAFT content          │
│ Persists rating +        │   │ Shows rating + reasoning +   │
│ reasoning only           │   │ structured suggestions       │
│ Stored on DataObject     │   │ Applies to Draft via ORM     │
│                          │   │ Cached on Entwine instance   │
└──────────┬───────────────┘   └──────────────┬───────────────┘
           │                                  │
           ▼                                  ▼
┌──────────────────────────┐   ┌──────────────────────────────┐
│ Content Extraction       │   │ AI Provider (specs/03)       │
│ (specs/02)               │   │                              │
│                          │   │ Gemini / OpenAI / Anthropic  │
│ Flat score payload +     │   │ via provider abstraction     │
│ structured rewrite       │   │                              │
│ targets for Draft apply  │   │ Shared rewrite-aware prompt  │
│ + MD5 content hash       │   │ for both job and modal       │
│                          │   │                              │
└──────────────────────────┘   └──────────────────────────────┘

┌──────────────────────────────────────────────────────────────┐
│ Storage                                                      │
│                                                              │
│ BrandVoiceAnalysis DataObject (specs/01)                     │
│  → Polymorphic has_one to parent (SiteTree)                  │
│  → Rating, ReasoningSummary, ContentHash, AnalysedAt         │
│  → Background job results only (on-demand is ephemeral)      │
│                                                              │
│ CMS Report (specs/06)                                        │
│  → Rating, reasoning (truncated), last analysed              │
│  → Filterable by rating level                                │
│  → "Not analysed" for unprocessed pages                      │
└──────────────────────────────────────────────────────────────┘
```

## Spec index

| # | Spec | What it covers |
|---|------|---------------|
| 00 | This file | System overview and architecture |
| 01 | `data-architecture` | BrandVoiceAnalysis DataObject, SiteConfig brand voice field, polymorphic relationship |
| 02 | `content-extraction` | Flat evaluation extraction, structured rewrite targets, Live vs Draft per context, content hashing |
| 03 | `ai-providers` | Shared provider abstraction, env vars, result objects |
| 04 | `prompts` | Shared rewrite-aware prompt, output format, audit rationale |
| 05 | `background-job` | Queued job, Live content, rate limiting, staleness, re-queue |
| 06 | `cms-report` | Columns, rating filter, "Not analysed" state, permissions |
| 07 | `cms-ux` | On-demand button, modal, per-target review/apply flow, dirty-state protection |
| 08 | `api-endpoints` | Controller, XHR endpoints for schema, check, and draft apply |

## Key design decisions

- **Two separate content reading modes** - background job reads Live to generate persisted ratings, on-demand reads Draft for editor checks, and the report's stale-state indicator compares the saved analysis hash against the page's current CMS content so editors can spot unpublished drift
- **Non-persisted on-demand results** - on-demand check results are cached on the Entwine instance for the editing session, not persisted to DB. Applying suggestions writes directly to Draft page and element records, while the report still reflects background job analysis of Live content only.
- **Shared rewrite-aware evaluation prompt** - both background and on-demand evaluation use the rewrite-capable prompt. The background job discards `suggestions`, but keeping rewrite in the prompt produces stronger audit judgements because the model surfaces gaps it would miss in a score-only pass.
- **Provider abstraction** - supports Gemini, OpenAI, and Anthropic via a common interface, configured through environment variables
- **Audit-first evaluation defaults** - provider temperature defaults to `0.0` so rating calls are more repeatable across report and on-demand checks. This module is primarily an auditing tool, not a creative workflow where rerolling different answers is desirable.
- **Polymorphic relationship** - pages only for now, but designed for future DataObject support
- **No Versioned on analysis record** - no publish/unpublish lifecycle needed; the rating is internal CMS data
- **Structured rewrite target mapping** - the AI receives server-generated rewrite targets keyed to real draft fields so the modal can review and selectively apply suggestions without coupling to Elemental's client-side editor state
- **Draft-only apply flow** - applying suggestions always writes to Draft via the ORM, never publishes, and reloads the CMS so inline editors pick up fresh server state
- **Operational bootstrap task** - `create-generic-brand-voice` can seed or refresh Site Settings with a reusable starter definition so new projects have a supported setup path before editors open the modal

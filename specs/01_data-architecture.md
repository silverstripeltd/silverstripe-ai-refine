# Data Architecture

## RefineAnalysis DataObject

Stores the background job's analysis result for a page. On-demand (modal) results are NOT stored here - they are cached on the Entwine instance for the editing session only.

### Schema

- **Class name:** `RefineAnalysis` (namespace: `SilverstripeLtd\AiRefine\Models\RefineAnalysis`)
- **Relationship:** `RefineAnalysis` has_one `Parent` (polymorphic)
  - `ParentID` (Int) + `ParentClass` (Varchar) - standard Silverstripe polymorphic pattern
  - The page does NOT have a has_one to the analysis; instead, the analysis points back to the page
  - Polymorphic design supports future extension to other DataObject types without migration
- **Not Versioned** - no Draft/Live lifecycle. The analysis is internal CMS data, not rendered on the frontend. Background job writes directly to the record.

### Fields

| Field | Type | Description |
|-------|------|-------------|
| `Rating` | Enum('Excellent','Good','Adequate','NeedsWork','Poor') | Refine compliance rating |
| `ReasoningSummary` | Text | AI's explanation of the rating - what's on-brand, what's off |
| `ContentHash` | Varchar(32) | MD5 hash of the extracted content at time of analysis |
| `AnalysedAt` | DBDatetime | When the analysis was last run |
| `GenerationNote` | Varchar(255) | Internal note when analysis was skipped (e.g. "Insufficient content") |

### Reasons for separate DataObject

- Avoids cluttering SiteTree with new columns
- Background job can write analysis results without touching page state
- Cleaner separation of concerns
- Polymorphic relationship enables future extension to other DataObjects

### Extension on SiteTree

An Extension is applied to `SiteTree` that:

- Provides helper methods to fetch (`getRefineAnalysis()`) and get-or-create (`getOrCreateRefineAnalysis()`) the `RefineAnalysis` record for a page
- Adds the "Tone" button context to the CMS edit form (see `specs/07_cms-ux.md`)
- Leaves button visibility and missing-configuration guidance to the CMS UX layer so editors can still open the modal and see the setup message when Site Settings is empty

### Lifecycle

- **First access:** The `RefineAnalysis` record is created when the background job first processes a page
- **Module removal:** The analysis table and extension are simply removed. No impact on page content.

## SiteConfig - Refine Definition

### Field

A single `RefineDefinition` field on `SiteConfig` via an Extension:

| Field | Type | Description |
|-------|------|-------------|
| `RefineDefinition` | Text | Free-text refine / style guide definition |

### CMS presentation

- Located in a "Refine" tab on SiteConfig
- Label: "Refine Definition"
- Help text: "Define your brand's tone of voice, writing style, and content guidelines. This will be used by AI to evaluate page content for compliance. You can generate a refine guide using ChatGPT or similar tools and paste it here."
- Placeholder text shows a sample generic refine (see below)
- TextareaField - no rich text, plain text only

### Input normalisation

On save, the field value is cleaned up to handle messy copy-paste formatting:

- Collapse runs of 3+ consecutive line breaks down to 2 (preserves intentional paragraph breaks)
- Strip leading spaces and tabs from each line
- Replace tabs and non-ASCII whitespace characters (e.g. non-breaking spaces, em spaces) with regular spaces
- Trim leading/trailing whitespace from the entire value

### Length constraints

- **Minimum:** 50 characters - anything shorter is unlikely to be a meaningful style guide
- **Maximum:** 10,000 characters - keeps the prompt within reasonable token limits
- Validation on save with a clear error message

### Sample refine

Shown as placeholder text on the field:

```

### Bootstrap task

The module also ships a build task, `create-generic-brand-voice`, that seeds or updates `SiteConfig.RefineDefinition` with the module's reusable starter definition. This gives a supported bootstrap path for new projects without changing the underlying field model.
Our refine is professional yet approachable. We write in plain English and avoid jargon, acronyms, and overly technical language unless absolutely necessary.

Tone: Confident, helpful, and warm. We speak as a knowledgeable friend, not a faceless corporation.

Audience: General public. Assume no prior expertise. If a concept needs explaining, explain it simply.

Style rules:
- Use active voice over passive voice
- Keep sentences short and scannable
- Use "you" and "we" to speak directly to the reader
- Avoid clichés and marketing buzzwords
- Be specific rather than vague - use concrete examples where possible

Content structure:
- Lead with the most important information
- Use headings and bullet points to break up long text
- Every page should have a clear purpose and call to action
```

### Empty state behaviour

When `RefineDefinition` is empty:
- The "Tone" button is still shown for editable saved pages so the modal can explain that configuration is missing
- The background job logs a message ("No refine definition configured - skipping all pages") and processes zero pages
- The CMS report shows an informational banner: "No refine has been defined. Configure your refine in Settings > Refine."

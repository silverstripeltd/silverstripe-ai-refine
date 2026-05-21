# Content Extraction

## Extraction approach

The module implements its own content extraction service and returns a `RefineExtractedContent` value object containing:

- `content` - the flat text payload sent to the AI for scoring
- `hash` - the MD5 of that flat payload for stale detection
- `mode` - `draft` or `live`
- `rewriteTargets` - structured per-field targets used for review and Draft apply

The flat scoring payload and the structured rewrite targets are built in parallel. The service does **not** try to reverse-map `getElementsForSearch()` output back to individual blocks after flattening.

### Flat evaluation payload pipeline

1. Read the page `Title` and prepend it when non-empty.
2. Build the primary body text:
   - If the page exposes `getElementsForSearch()`, use that flattened Elemental search text.
   - If `getElementsForSearch()` throws `MissingTemplateException` because a custom Elemental block has no template, fall back to `getContentFromElementsForCmsSearch()` and normalise its delimiters to plain whitespace.
   - Otherwise, if the page has a `Content` field, strip HTML with `Convert::html2raw()`.
3. Join the title and body with blank lines.
4. Run the `updateExtractedContent` extension hook so project code can append more flat evaluation text.

This flat payload is the exact string used for both provider input and hash generation.

### Structured rewrite target pipeline

The service also builds a structured target list for server-side review and apply:

1. If the page has a non-empty `Title`, add a `page:title` target mapped to the page `Title` field.
2. If the page exposes `getElementalRelations()`, iterate each ElementalArea relation and collect supported text and HTML DB fields from each Elemental block.
3. Preserve the existing `element:{ID}:html` key for `ElementContent.HTML`. For other supported block fields, add `element:{ID}:field:{lowercaseFieldName}` targets.
4. Use `element_html` for HTML-capable block fields and `element_text` for plain text block fields.
5. If no supported element targets were found and the page has a `Content` field, add a `page:content` target mapped to the page `Content` field.
5. Run the `updateExtractedRewriteTargets` extension hook so project code can add or amend targets before prompt generation.

Each rewrite target carries:

- `targetKey` - stable server-known identifier, such as `page:title` or `element:42:html`
- `targetType` - one of `page_title`, `page_content`, `element_html`, or `element_text`
- `fieldName` - ORM field to write back to
- `targetId` - page or element ID where applicable
- `sourceContent` - the source content sent to the AI provider and shown in the modal

For rewrite targets, HTML-type fields (`page_content` and `element_html`) retain their raw HTML so the AI can preserve heading levels, lists, links, and inline formatting when rewriting. Plain text fields have whitespace normalised before prompting and apply review. The flat evaluation payload (used for hashing and empty-content checks) is still stripped to plain text.

### Non-elemental pages

Non-elemental pages are handled throughout:

- The flat payload still uses the page `Title` plus stripped `Content`.
- The rewrite target list still includes `page:title` when present.
- If there are no supported Elemental block field targets, the page `Content` field becomes the `page:content` rewrite target.

## Content length

No truncation. Modern AI models have large context windows. If content exceeds the model's context window, the API returns an error handled as a provider exception (see `specs/03_ai-providers.md`).

## Versioned awareness

The parent DataObject may or may not have the `Versioned` extension. The content extraction service checks for Versioned support and adapts accordingly:

### Versioned parent (e.g. SiteTree)

This module reads different versions depending on the context:

**Background job → Live content**

The background job evaluates **Live** (published) content. The website owner uses the report to understand how the live site aligns with the refine.

- Content extraction wraps the read in `Versioned::withVersionedMode()` set to `LIVE`.
- Pages with no Live record (draft-only pages) are **skipped** by the background job - they're not published, so they're not part of the live site.
- The content hash is computed on the Live flat payload.

**On-demand check and apply → Draft content**

The on-demand modal evaluates and applies against **Draft** content. The editor is checking their saved work-in-progress before publishing.

- Content extraction wraps the read in `Versioned::withVersionedMode()`, reading Draft first, falling back to Live if Draft doesn't exist.
- Both the flat evaluation payload and the structured rewrite targets are generated from that same resolved record.
- This ensures the editor is checking and applying against saved Draft content, not the published version.

### Non-Versioned parent

If the parent DataObject does not have the `Versioned` extension, content is read directly from the record (no staging). Both the background job and on-demand check read the same content - there is no Live/Draft distinction.

## Content hashing

The extracted content string is used to compute an MD5 hash for stale detection (see `specs/05_background-job.md`). The hash must be computed on exactly the same flat content that is sent to the AI provider.

For the background job, the hash is computed on **Live** content (since that's what the job evaluates). Staleness is detected by re-extracting Live content and comparing hashes.

`rewriteTargets` are **not** part of the hash. They exist to support structured review and Draft apply, while stale detection remains based on the flat evaluation payload only.

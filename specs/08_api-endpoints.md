# API Endpoints

## Overview

Server-side endpoints for the CMS modal's on-demand refine check and Draft apply workflow. Implemented as a Silverstripe admin controller.

## Controller

- Class: `RefineController` (namespace: `SilverstripeLtd\AiRefine\Controllers\RefineController`)
- Registered as an admin route using the standard Silverstripe admin controller pattern

## Endpoints

### GET `/admin/ai-refine/schema/{ID}`

Fetch the FormSchema for the refine modal.

- **ID:** SiteTree page ID
- **Auth:** CMS session
- **Behaviour:**
  1. Validate the page exists and user has `canEdit()` permission.
  2. Return the FormSchema JSON describing the modal layout plus schema meta for labels, messages, `checkUrl`, `applyUrl`, and client state such as `refineConfigured` and `supportsApply`.
- **Response:** Standard Silverstripe FormSchema response
- **Error responses:**
  - 403 - user cannot edit the page
  - 404 - page not found

The Entwine adapter fetches this schema when mounting the React component. The schema defines the modal metadata server-side so the React component remains a thin renderer.

### POST `/admin/ai-refine/check/{ID}`

Trigger an on-demand refine check for a page.

- **ID:** SiteTree page ID
- **Auth:** CMS session + CSRF token
- **Behaviour:**
  1. Validate the page exists and user has `canEdit()` permission.
  2. Check that `RefineDefinition` is set on SiteConfig and return an error if empty.
  3. Extract **Draft** content from the page (see `specs/02_content-extraction.md`).
  4. If content is empty, return an error response.
  5. Call the AI provider with the **rating + rewrite** prompt (`specs/04_prompts.md`), including the server-generated rewrite target list.
  6. Resolve the provider response back onto the known Draft rewrite targets.
  7. Return the result as JSON without persisting it to `RefineAnalysis`. If the rating is `Excellent`, strip all suggestions before returning so the modal shows the fully-aligned path without contradictory rewrites.
- **Response:**
  ```json
  {
    "rating": "Good",
    "ratingLabel": "Good",
    "reasoningSummary": "The content maintains a professional yet approachable tone...",
    "suggestions": [
      {
        "targetKey": "page:title",
        "targetType": "page_title",
        "targetId": 123,
        "fieldName": "Title",
        "fieldLabel": "Page name",
        "targetTitle": "",
        "sourceContent": "Original page title",
        "suggestedContent": "Suggested page title",
        "contentFormat": "text",
        "diffHtml": "<del>Original page title</del><ins>Suggested page title</ins>"
      },
      {
        "targetKey": "element:42:html",
        "targetType": "element_html",
        "targetId": 42,
        "fieldName": "HTML",
        "fieldLabel": "HTML",
        "targetTitle": "Content",
        "sourceContent": "Current block text",
        "suggestedContent": "<p>Suggested block HTML</p>",
        "contentFormat": "html",
        "diffHtml": "<del><p>Current block text</p></del><ins><p>Suggested block HTML</p></ins>"
      }
    ]
  }
  ```
- **Error responses:**
  - 400 - missing or invalid parameters, empty refine definition, or empty page content
  - 403 - user cannot edit the page or CSRF token invalid
  - 404 - page not found
  - 500 - AI provider failure, with provider detail in development and a generic message in production

### POST `/admin/ai-refine/apply/{ID}`

Apply selected refine suggestions to Draft content for a page.

- **ID:** SiteTree page ID
- **Auth:** CMS session + CSRF token
- **Request body:** JSON payload containing a `suggestions` array. The modal sends only the selected suggestions and marks them with `apply: true`. The controller parses JSON first and falls back to standard form post vars for non-JS or test helpers.
- **Behaviour:**
  1. Validate the page exists and user has `canEdit()` permission.
  2. Parse the payload and require `suggestions` to be an array.
  3. Re-extract the page's **Draft** rewrite targets on the server and index them by `targetKey`.
  4. Ignore any suggestion that is not explicitly opted in via a truthy `apply`, `rewrite`, or `shouldRewrite` flag.
  5. For each opted-in suggestion, validate:
     - the payload entry is an object
     - `targetKey` is present and unique in the request
     - `suggestedContent` is a string
     - the target exists in the current Draft rewrite target list
     - any supplied `targetType`, `fieldName`, and `targetId` still match the server-known target metadata
  6. Apply page field suggestions directly to the Draft page record and write it once if any page fields changed.
  7. Apply `element_html` and `element_text` suggestions only after loading the Elemental block record by ID and verifying its `ParentID` belongs to one of the target page's ElementalAreas.
  8. Skip invalid, deleted, duplicated, foreign, or mismatched suggestions, log the skip reason, and continue processing the rest.
  9. Return counts so the modal can show full-success or partial-success messaging and decide whether to reload.
- **Response:**
  ```json
  {
    "appliedCount": 2,
    "skippedCount": 1,
    "reloadRequired": true
  }
  ```
- **Error responses:**
  - 400 - invalid request parameters or missing `suggestions` payload
  - 403 - user cannot edit the page or CSRF token invalid
  - 404 - page not found

Applying suggestions writes to Draft records only. It never publishes content.

### Apply suggestion sanitisation

AI-generated suggestions are sanitised before being written to Draft fields, replicating the server-side protections of a normal CMS save:

- **HTML fields** (`DBHTMLText`, `DBHTMLVarchar`) - the suggestion is run through Silverstripe's `HTMLEditorSanitiser` (using the active `HTMLEditorConfig` allowlist) followed by the framework's `XssSanitiser` with default settings. This strips dangerous elements (`script`, `embed`, `object`, `style`, `svg`), event handler attributes (`on*`), and dangerous URL schemes (`javascript:`, `data:text/html`, `vbscript:`).
- **Plain text fields** - all HTML tags are stripped entirely via `strip_tags()`.

### Error response format

```json
{
  "error": "Human-readable error message"
}
```

## Diff HTML sanitisation

The `diffHtml` field in check responses is a read-only diff preview. It is aggressively sanitised in two stages to prevent XSS and keep rendering predictable:

1. **Pre-diff flattening** - before the source content enters `HtmlDiff::compareHtml()`, it is flattened to plain `<p>` tags. All other elements are unwrapped (their text content is kept, the tag is removed) and all attributes on `<p>` tags are stripped. This prevents any original markup - including stray `<del>` or `<ins>` tags that could be confused with diff markers - from reaching the diff library.

2. **Post-diff sanitisation** - the diff library output is processed through:
   - Silverstripe's `XssSanitiser` with inner-HTML removal for dangerous elements
   - An element allowlist limited to `<p>`, `<del>`, and `<ins>` only. All other elements are unwrapped.
   - Attribute stripping on all remaining elements - no attributes are ever returned.

The result is that `diffHtml` only ever contains `<p>`, `<del>`, and `<ins>` tags with no attributes.

## CSRF protection

The `check` and `apply` endpoints require a valid CSRF token, which is standard for Silverstripe admin controller POST requests. The React component includes the token in the XHR request header.

## FormSchema

The modal uses Silverstripe's FormSchema mechanism to define its layout server-side. This keeps the React component thin, and the returned schema meta also carries the action URLs, labels, and messaging for the review and apply flow.

This module intentionally uses FormSchema only for schema meta, not as a full record-editing form. The modal reviews content owned by other modules and writes selected suggestions back to Draft page or Elemental records via separate JSON `check` and `apply` endpoints, so a full FormSchema form is not the right fit here.

## No GET endpoint

There is no GET endpoint to fetch previous results. On-demand results are cached on the Entwine instance rather than persisted to DB. The modal does not need to load stored data from the server on open, and the apply flow always revalidates against fresh Draft rewrite targets on the server before writing.

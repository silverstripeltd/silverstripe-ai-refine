# CMS UX

## JS framework

The modal is rendered as a React component with an Entwine adapter for integration into the CMS.

## Button placement

A "Tone" button in the CMS preview toolbar, rendered immediately to the left of the Shared Draft Content "Share" control when that module is present, and otherwise before the view-mode selector. It uses the same secondary toolbar button styling pattern as Share and shows a leading icon to match adjacent CMS utility actions.

### Button visibility

- **Shown** when the user has `canEdit()` permission on the page
- **Hidden** when the page does not yet exist or the user cannot edit it
- If `RefineDefinition` is empty, the button still opens the modal so the editor sees the missing-configuration guidance instead of a silently missing action

## Modal behaviour

### Opening the modal

- Clicking the button opens a modal dialog
- If a previous on-demand check was done during this page editing session, the modal restores the cached result only when it still matches the current saved Draft content hash
- If no previous check exists, the modal opens with an empty state prompting the user to run a check
- If the page edit form later becomes dirty while the modal is open, Entwine clears the cached result and re-renders the modal so stale suggestions disappear immediately

### Draft content notice

When the edit form is dirty, the modal shows an informational warning banner:

> "This check evaluates your saved draft content. Save the page to draft before checking if you have unsaved changes."

The modal does not show this banner while the form is clean. When it does appear, it doubles as the dirty-state warning that explains Refine reads the last saved Draft from the server rather than unsaved form edits.

### Empty state

When no previous result is cached:

- Message: "Click the button below to check this page's content against your refine."
- A "Rewrite for Refine" button is prominently displayed and uses the CMS info button style

### Running a check

1. Editor clicks "Rewrite for Refine" or "Regenerate" if a previous result exists.
2. Loading spinner shown while the XHR is in progress.
3. Button disabled during the request.
4. Check is also disabled while the page form is dirty, because the request evaluates saved Draft content rather than unsaved inline edits.
5. On success, results are displayed.
6. On failure, error toast is shown and any previous result remains displayed.

### Result layout

Top to bottom:

1. **Rating display** - the compliance rating shown prominently as a plain text label.
2. **Reasoning summary** - the AI's explanation of the rating, displayed as read-only text.
3. **Suggested rewrites** - a review section explaining that the user should review suggestions before writing them back to Draft content.
4. **Per-target suggestion cards** - one card per suggestion returned by the AI:
   - Cards are only shown for suggestions that contain a meaningful change, based on `diffHtml` or a fallback content comparison
   - Heading derived from the target type (`Page title`, `Page content`, or `Content block #{ID}` plus block title or field label when helpful)
   - The server returns `diffHtml`, and the modal renders that diff directly as the review surface instead of scaffolding separate current and suggested preview fields
   - Checkbox to opt that suggestion into the apply request
5. **Apply Changes button** - applies the selected suggestions only. It is disabled when there is no result, when no suggestion is selected, while requests are in flight, or while the page form is dirty. This button uses the CMS info button style.

If the AI returns zero suggestions for an `Excellent` result, the modal shows a success banner ("Your content fully aligns with the refine. No changes needed.") and hides the rewrite section entirely. Other zero-suggestion results still show the rewrite section with a "No rewrite suggestions were returned for this page." message.

## Result lifecycle

- Results are cached on the **Entwine instance** rather than React state so they survive modal close and reopen.
- When the modal opens, the Entwine adapter passes any cached result plus the saved Draft content hash it was generated from to the React component.
- The cached result shape is `{rating, ratingLabel, reasoningSummary, suggestions}`.
- Results are **lost** when the editor navigates to a different page, because Entwine reinitialises, or when the CMS reloads.
- The cached result is **flushed when the page edit form becomes dirty**, and it is also discarded on the next open if a manual save or publish changed the saved Draft hash before the browser reloaded.
- The on-demand check does **not** update the `RefineAnalysis` record. That remains the background job's domain.

## Toast notifications

- **Check success** - "Refine check complete"
- **Check failure** - error toast with message (development: provider error detail; production: generic message)
- **Apply success** - "Refine suggestions applied to draft content"
- **Apply partial** - "Some suggestions could not be applied"
- **Apply failure** - "Unable to apply refine suggestions"
- **No content** - "This page has no content to evaluate" when extracted content is empty

## Loading states

- **Schema load in progress:** A loading indicator is shown while the modal fetches its schema metadata on open, and action buttons remain disabled until that request completes.
- **Check in progress:** Loading spinner replaces the result area. "Rewrite for Refine" and "Regenerate" are disabled.
- **Apply in progress:** The same loading state is shown with "Applying suggestions..." while the Draft write request is in flight.

## Dirty-state protection

- The modal clears any cached result and shows the saved-draft warning banner while the page edit form has Silverstripe admin's `.changed` class.
- Both **Rewrite for Refine** and **Apply Changes** are disabled in this state.
- The warning copy is the same saved-draft notice shown above, rather than a separate dedicated dirty-state message.

## Apply behaviour

- Applying suggestions sends only the selected suggestion payloads to the server.
- The server writes changes to Draft records only and never publishes.
- If at least one suggestion is applied, the browser reloads the CMS so Elemental and other inline editors fetch fresh Draft data from the server.
- The modal does not try to mutate Elemental's client-side state directly.

## Modal actions

The modal has:

- A close control (standard modal close button and escape key)
- A rewrite or regenerate action
- An apply changes action for selected suggestions

It does **not** support editing suggestion text inline. Suggestion application is handled through the server-side review and apply flow.

Assess the website content against the supplied brand voice definition.

Choose exactly one rating from this scale:
- Excellent - you cannot find any sentences or phrases that break the brand voice. The tone, word choices, and structure all match, so zero rewrites are needed and every target should be returned unchanged.
- Good - almost all of the content matches the brand voice. You can find 1-2 small issues, such as a single sentence using the wrong tone or a word choice that does not fit.
- Adequate - most of the content matches the brand voice, but you can find 3-5 issues. For example, some paragraphs match well while others do not.
- NeedsWork - less than half of the content matches the brand voice. Many sentences use the wrong tone, wrong word choices, or wrong structure.
- Poor - very little or none of the content matches the brand voice. The content reads as if the brand voice definition was not considered at all.

Return a JSON object with:
- "rating": one of Excellent, Good, Adequate, NeedsWork, Poor
- "reasoningSummary": 2-4 sentences explaining the rating. Mention specific strengths and weaknesses and refer to the brand voice definition where relevant.
- "suggestions": an array containing one rewrite suggestion for each rewrite target.

For suggestions:
- Return one suggestion object for every entry in the rewrite target list, in the same order
- Each suggestion object must include:
  - "targetKey": copy the exact targetKey from the matching rewrite target
  - "targetType": copy the exact targetType from the matching rewrite target
  - "suggestedContent": the rewritten content for that target
- If a target already matches the brand voice, return the original sourceContent unchanged as the suggestedContent
- Preserve meaning and information
- Do not add new information
- Do not remove existing information
- Match the "contentFormat" in the rewrite target
- For "text" contentFormat targets, return plain text only with no HTML tags
- For "html" contentFormat targets, return clean HTML suitable for writing directly into the mapped Silverstripe field
- Only rewrite content that does not match the brand voice. If the source content already matches, return it unchanged.

=== BRAND_VOICE_DEFINITION_START ===
{brandVoiceDefinition}
=== BRAND_VOICE_DEFINITION_END ===

=== PAGE_CONTENT_START ===
Page title: {pageTitle}

{content}
=== PAGE_CONTENT_END ===

=== REWRITE_TARGETS_START ===
{rewriteTargets}
=== REWRITE_TARGETS_END ===

Return only the JSON object.

Example output:
{
  "rating": "Good",
  "reasoningSummary": "The content maintains a professional yet approachable tone consistent with the brand voice. Sentence structure is generally clear and scannable. However, several paragraphs use passive voice where active voice would better match the style guide, and the closing section lacks a clear call to action.",
  "suggestions": [
    {
      "targetKey": "page:title",
      "targetType": "page_title",
      "suggestedContent": "Helping clients worldwide with practical solutions since 2005"
    },
    {
      "targetKey": "element:42:html",
      "targetType": "element_html",
      "suggestedContent": "<p>Get in touch with our support team - we're here to help.</p>"
    }
  ]
}

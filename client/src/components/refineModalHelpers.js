import Config from 'lib/Config';
import { joinUrlPaths } from 'lib/urls';

export const CONTROLLER_CONFIG_KEY = 'SilverstripeLtd\\AiRefine\\Controllers\\RefineController';

export const defaultSchemaConfig = {
  title: 'Refine page content with AI',
  ratingLabels: {
    Excellent: 'Excellent',
    Good: 'Good',
    Adequate: 'Adequate',
    NeedsWork: 'Needs work',
    Poor: 'Poor',
  },
  messages: {
    draftNotice: 'This check evaluates your saved draft content. Save the page to draft before checking if you have unsaved changes.',
    emptyState: 'Click the button below to check the content on this page against your writing style and tone rules.',
    missingRefine: 'No refine has been defined. Configure your refine in Settings > Refine.',
    allAligned: 'Your content already matches the refine rules. No changes needed.',
    noContent: 'This page has no content to evaluate',
    checkSuccess: 'Refine check complete',
    checkFailure: 'Refine check failed',
    reviewNotice: 'Review each suggestion below before writing it back to draft content. Applying suggestions updates the saved draft record on the server and then reloads the CMS.',
    noSuggestions: 'No rewrite suggestions were returned for this page.',
    dirtyState: 'Check and apply are disabled while this page has unsaved changes. Save or discard them first. '
      + 'Refine uses saved draft content as the source of truth, and applying suggestions reloads the page.',
    applySuccess: 'Refine suggestions applied to draft content',
    applyPartial: 'Some suggestions could not be applied',
    applyFailure: 'Unable to apply refine suggestions',
    copySuccess: 'Copied to clipboard',
    copyFailure: 'Unable to copy to clipboard',
  },
  labels: {
    check: 'Refine',
    recheck: 'Regenerate',
    apply: 'Apply changes',
    copy: 'Copy to clipboard',
    rating: 'Refine rating',
    reasoning: 'Reasoning summary',
    rewrite: 'Suggested rewrites',
    current: 'Current draft content',
    suggested: 'Suggested draft content',
    applySuggestion: 'Apply this suggestion',
  },
  fields: {
    reasoning: { rows: 5, readOnly: true },
    rewrite: { rows: 18, readOnly: true, copyable: true },
  },
  actions: {
    checkUrl: '',
    applyUrl: '',
  },
  state: {
    refineConfigured: true,
    contentHash: '',
    supportsApply: false,
    storesResultsServerSide: false,
  },
  errors: {
    provider: {
      mode: 'generic',
      genericMessage: 'There was an error connecting to the AI provider',
    },
  },
};

/**
 * Reads the controller config section that seeds the Refine modal.
 */
export const getControllerConfig = () => Config.getSection(CONTROLLER_CONFIG_KEY) || {};

/**
 * Resolves the modal presentation config from defaults and server overrides.
 */
export const getModalConfig = () => ({
  className: 'ai-refine-modal',
  modalClassName: 'ai-refine-modal',
  size: 'xl',
  ...(getControllerConfig().form?.refineCheck || {}),
});

/**
 * Builds one controller endpoint URL for the current record.
 */
const buildRecordActionUrl = (fqcn, recordId, configuredUrl, fallbackUrl) => {
  const base = joinUrlPaths(configuredUrl || fallbackUrl, recordId.toString());
  return `${base}?fqcn=${encodeURIComponent(fqcn)}`;
};

/**
 * Builds the schema endpoint URL for the current record.
 */
export const buildSchemaUrl = (fqcn, recordId, modalConfig = getModalConfig()) => (
  buildRecordActionUrl(fqcn, recordId, modalConfig.schemaUrl, '/admin/ai-refine/schema')
);

/**
 * Builds the check endpoint URL for the current record.
 */
export const buildCheckUrl = (fqcn, recordId, modalConfig = getModalConfig()) => (
  buildRecordActionUrl(fqcn, recordId, modalConfig.checkUrl, '/admin/ai-refine/check')
);

/**
 * Builds the apply endpoint URL for the current record.
 */
export const buildApplyUrl = (fqcn, recordId, modalConfig = getModalConfig()) => (
  buildRecordActionUrl(fqcn, recordId, modalConfig.applyUrl, '/admin/ai-refine/apply')
);

/**
 * Returns the schema fetch headers expected by FormSchemaController.
 */
export const getSchemaHeaders = () => ({
  'X-FormSchema-Request': 'schema,state',
});

/**
 * Returns the common JSON headers for authenticated check requests.
 */
export const getCheckHeaders = () => ({
  Accept: 'application/json',
  'X-SecurityID': Config.get('SecurityID') || '',
});

/**
 * Returns the JSON headers for apply requests that post suggestion payloads.
 */
export const getApplyHeaders = () => ({
  ...getCheckHeaders(),
  'Content-Type': 'application/json',
});

/**
 * Pulls the most useful error message out of varied API response shapes.
 */
export const getResponseErrorMessage = (payload, fallback) => {
  if (payload?.error) {
    return payload.error;
  }
  if (Array.isArray(payload?.errors) && payload.errors[0]?.value) {
    return payload.errors[0].value;
  }
  if (payload?.message) {
    return payload.message;
  }
  return fallback;
};

/**
 * Chooses the initial or repeat check label based on whether a result already exists.
 */
export const getCheckButtonLabel = (result, schemaConfig = defaultSchemaConfig) => (
  result ? schemaConfig.labels?.recheck || defaultSchemaConfig.labels.recheck : schemaConfig.labels?.check || defaultSchemaConfig.labels.check
);

/**
 * Maps the API rating enum to a display label with schema overrides.
 */
export const getRatingLabel = (rating, schemaConfig = defaultSchemaConfig) => {
  const resolvedRating = typeof rating === 'string' ? rating.trim() : '';
  if (!resolvedRating) {
    return '';
  }

  return schemaConfig.ratingLabels?.[resolvedRating]
    || defaultSchemaConfig.ratingLabels[resolvedRating]
    || resolvedRating;
};

/**
 * Collapses text whitespace so plain-text suggestions compare consistently.
 */
const normaliseWhitespace = (value) => (
  typeof value === 'string' ? value.replace(/\s+/g, ' ').trim() : ''
);

/**
 * Detects whether a suggestion actually changes content and should stay selectable.
 */
export const suggestionHasRecommendedChange = (suggestion) => {
  const diffHtml = typeof suggestion?.diffHtml === 'string' ? suggestion.diffHtml : '';
  if (/<(?:del|ins)\b/i.test(diffHtml)) {
    return true;
  }
  if (diffHtml !== '') {
    return false;
  }

  const isHtmlContent = suggestion?.contentFormat === 'html';
  const sourceValue = typeof suggestion?.sourceContent === 'string' ? suggestion.sourceContent : '';
  const suggestedValue = typeof suggestion?.suggestedContent === 'string' ? suggestion.suggestedContent : '';
  const sourceContent = isHtmlContent ? sourceValue.trim() : normaliseWhitespace(sourceValue);
  const suggestedContent = isHtmlContent ? suggestedValue.trim() : normaliseWhitespace(suggestedValue);

  return sourceContent !== suggestedContent;
};

/**
 * Normalises the result suggestions field to an array for modal state.
 */
export const getResultSuggestions = (result) => (
  Array.isArray(result?.suggestions) ? result.suggestions : []
);

/**
 * Seeds the selected suggestion list from actionable suggestions in a result.
 */
export const getInitialSelectedTargetKeys = (result) => (
  getResultSuggestions(result)
    .filter((suggestion) => suggestionHasRecommendedChange(suggestion))
    .map(({ targetKey }) => targetKey)
    .filter((targetKey) => typeof targetKey === 'string' && targetKey.trim() !== '')
);

/**
 * Chooses the best available field label for a suggestion heading.
 */
const getSuggestionFieldLabel = (suggestion, index) => (
  suggestion?.fieldLabel || suggestion?.fieldName || `Field ${index + 1}`
);

/**
 * Appends one unique heading fragment when it has meaningful content.
 */
const appendHeadingPart = (parts, value) => {
  const trimmedValue = typeof value === 'string' ? value.trim() : '';
  if (!trimmedValue) {
    return parts;
  }

  if (parts.some((part) => part.toLowerCase() === trimmedValue.toLowerCase())) {
    return parts;
  }

  return [...parts, trimmedValue];
};

/**
 * Builds the human-friendly heading shown above each rewrite suggestion.
 */
export const getSuggestionHeading = (suggestion, index) => {
  switch (suggestion?.targetType) {
    case 'page_title':
      return 'Page title';
    case 'page_content':
      return 'Page content';
    case 'element_html':
    case 'element_text': {
      const fieldLabel = getSuggestionFieldLabel(suggestion, index);
      const shouldIncludeFieldLabel = suggestion?.targetType !== 'element_html'
        || !['html', 'content'].includes(fieldLabel.toLowerCase());
      let headingParts = [`Content block #${suggestion?.targetId || index + 1}`];

      headingParts = appendHeadingPart(headingParts, suggestion?.targetTitle);
      if (shouldIncludeFieldLabel) {
        headingParts = appendHeadingPart(headingParts, fieldLabel);
      }

      return headingParts.join(' - ');
    }
    default:
      return getSuggestionFieldLabel(suggestion, index);
  }
};

/**
 * Normalises the draft check payload into the modal result shape.
 */
export const buildCheckResult = (payload) => ({
  rating: payload?.rating || '',
  ratingLabel: payload?.ratingLabel || '',
  reasoningSummary: payload?.reasoningSummary || '',
  suggestions: getResultSuggestions(payload),
});

/**
 * Strips transient diff markup before posting selected suggestions back to the server.
 */
export const buildApplyRequestBody = (selectedSuggestions) => ({
  suggestions: selectedSuggestions.map((suggestion) => {
    const { diffHtml, ...applySuggestion } = suggestion;

    return {
      ...applySuggestion,
      apply: true,
    };
  }),
});

/**
 * Merges server schema metadata onto the client defaults for resilient rendering.
 */
export const mergeSchemaConfig = (schemaPayload) => {
  const serverConfig = schemaPayload?.meta?.refine || {};
  return {
    ...defaultSchemaConfig,
    ...serverConfig,
    messages: {
      ...defaultSchemaConfig.messages,
      ...(serverConfig.messages || {}),
    },
    ratingLabels: {
      ...defaultSchemaConfig.ratingLabels,
      ...(serverConfig.ratingLabels || {}),
    },
    labels: {
      ...defaultSchemaConfig.labels,
      ...(serverConfig.labels || {}),
    },
    fields: {
      ...defaultSchemaConfig.fields,
      ...(serverConfig.fields || {}),
    },
    actions: {
      ...defaultSchemaConfig.actions,
      ...(serverConfig.actions || {}),
    },
    state: {
      ...defaultSchemaConfig.state,
      ...(serverConfig.state || {}),
    },
    errors: {
      ...defaultSchemaConfig.errors,
      ...(serverConfig.errors || {}),
    },
  };
};

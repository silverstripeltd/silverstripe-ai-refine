/* eslint-env jest */
/* eslint-disable import/first */
jest.mock('lib/Config', () => ({
  __esModule: true,
  default: {
    get: (key) => (key === 'SecurityID' ? 'security-token' : null),
    getSection: () => ({
      form: {
        brandVoiceCheck: {
          schemaUrl: '/admin/ai-brand-voice/schema',
          checkUrl: '/admin/ai-brand-voice/check',
        },
      },
    }),
  },
}), { virtual: true });

jest.mock('lib/urls', () => ({
  joinUrlPaths: (...parts) => parts.join('/'),
}), { virtual: true });

import {
  buildApplyRequestBody,
  buildApplyUrl,
  buildCheckResult,
  buildCheckUrl,
  buildSchemaUrl,
  defaultSchemaConfig,
  getInitialSelectedTargetKeys,
  getApplyHeaders,
  getCheckButtonLabel,
  getCheckHeaders,
  getRatingLabel,
  getResultSuggestions,
  getResponseErrorMessage,
  getSchemaHeaders,
  getSuggestionHeading,
  mergeSchemaConfig,
  suggestionHasRecommendedChange,
} from '../../src/components/brandVoiceModalHelpers';

test('buildSchemaUrl encodes fqcn and appends record id', () => {
  const url = buildSchemaUrl('My\\Page', 42);
  expect(url).toBe('/admin/ai-brand-voice/schema/42?fqcn=My%5CPage');
});

test('buildCheckUrl encodes fqcn and appends record id', () => {
  const url = buildCheckUrl('My\\Page', 42);
  expect(url).toBe('/admin/ai-brand-voice/check/42?fqcn=My%5CPage');
});

test('buildApplyUrl encodes fqcn and appends record id', () => {
  const url = buildApplyUrl('My\\Page', 42);
  expect(url).toBe('/admin/ai-brand-voice/apply/42?fqcn=My%5CPage');
});

test('getSchemaHeaders requests schema and state', () => {
  expect(getSchemaHeaders()).toEqual({
    'X-FormSchema-Request': 'schema,state',
  });
});

test('getCheckHeaders includes the security header', () => {
  expect(getCheckHeaders()).toEqual({
    Accept: 'application/json',
    'X-SecurityID': 'security-token',
  });
});

test('getApplyHeaders includes json content type and the security header', () => {
  expect(getApplyHeaders()).toEqual({
    Accept: 'application/json',
    'Content-Type': 'application/json',
    'X-SecurityID': 'security-token',
  });
});

test('getResponseErrorMessage prefers explicit error values', () => {
  expect(getResponseErrorMessage({ error: 'Primary message' }, 'Fallback')).toBe('Primary message');
  expect(getResponseErrorMessage({ errors: [{ value: 'Schema error' }] }, 'Fallback')).toBe('Schema error');
  expect(getResponseErrorMessage({ message: 'Secondary message' }, 'Fallback')).toBe('Secondary message');
  expect(getResponseErrorMessage({}, 'Fallback')).toBe('Fallback');
});

test('getCheckButtonLabel switches between initial and iterative labels', () => {
  expect(getCheckButtonLabel(null, defaultSchemaConfig)).toBe('Rewrite for brand voice');
  expect(getCheckButtonLabel({ rating: 'Good' }, defaultSchemaConfig)).toBe('Regenerate');
});

test('getRatingLabel uses schema overrides and falls back to the enum value', () => {
  expect(getRatingLabel('NeedsWork', defaultSchemaConfig)).toBe('Needs work');
  expect(getRatingLabel('Custom', defaultSchemaConfig)).toBe('Custom');

  expect(getRatingLabel('NeedsWork', {
    ...defaultSchemaConfig,
    ratingLabels: {
      ...defaultSchemaConfig.ratingLabels,
      NeedsWork: 'Needs attention',
    },
  })).toBe('Needs attention');
});

test('mergeSchemaConfig overlays server config onto defaults', () => {
  const merged = mergeSchemaConfig({
    meta: {
      brandVoice: {
        title: 'Custom title',
        messages: {
          emptyState: 'Custom empty state',
        },
        labels: {
          apply: 'Apply updates',
        },
        actions: {
          applyUrl: '/custom/apply/url',
        },
        state: {
          brandVoiceConfigured: false,
          supportsApply: true,
        },
      },
    },
  });

  expect(merged.title).toBe('Custom title');
  expect(merged.ratingLabels.NeedsWork).toBe('Needs work');
  expect(merged.messages.allAligned).toBe('Your content fully aligns with the brand voice. No changes needed.');
  expect(merged.messages.emptyState).toBe('Custom empty state');
  expect(merged.messages.applySuccess).toBe('Brand voice suggestions applied to draft content');
  expect(merged.labels.apply).toBe('Apply updates');
  expect(merged.actions.applyUrl).toBe('/custom/apply/url');
  expect(merged.state.brandVoiceConfigured).toBe(false);
  expect(merged.state.supportsApply).toBe(true);
});

test('suggestionHasRecommendedChange uses diff markup when available', () => {
  expect(suggestionHasRecommendedChange({
    diffHtml: '<del>Original</del> <ins>Updated</ins>',
    sourceContent: 'Original',
    suggestedContent: 'Updated',
    contentFormat: 'text',
  })).toBe(true);

  expect(suggestionHasRecommendedChange({
    diffHtml: 'Original',
    sourceContent: 'Original',
    suggestedContent: 'Original',
    contentFormat: 'text',
  })).toBe(false);
});

test('suggestionHasRecommendedChange normalises whitespace for text suggestions when diff markup is absent', () => {
  expect(suggestionHasRecommendedChange({
    sourceContent: 'Original   paragraph\nwith spacing',
    suggestedContent: 'Original paragraph with spacing',
    contentFormat: 'text',
  })).toBe(false);

  expect(suggestionHasRecommendedChange({
    sourceContent: 'Original paragraph',
    suggestedContent: 'Updated paragraph',
    contentFormat: 'text',
  })).toBe(true);
});

test('suggestionHasRecommendedChange compares trimmed raw html when diff markup is absent', () => {
  expect(suggestionHasRecommendedChange({
    sourceContent: '  <p>Same html</p>  ',
    suggestedContent: '<p>Same html</p>',
    contentFormat: 'html',
  })).toBe(false);

  expect(suggestionHasRecommendedChange({
    sourceContent: '<p>Same html</p>',
    suggestedContent: '<p>Different html</p>',
    contentFormat: 'html',
  })).toBe(true);
});

test('getResultSuggestions and buildCheckResult normalise modal result payloads', () => {
  expect(getResultSuggestions({ suggestions: ['one'] })).toEqual(['one']);
  expect(getResultSuggestions({ suggestions: null })).toEqual([]);

  expect(buildCheckResult({
    rating: 'Good',
    ratingLabel: 'Good',
    reasoningSummary: 'Mostly aligned.',
    suggestions: [{ targetKey: 'page:title' }],
  })).toEqual({
    rating: 'Good',
    ratingLabel: 'Good',
    reasoningSummary: 'Mostly aligned.',
    suggestions: [{ targetKey: 'page:title' }],
  });
});

test('getInitialSelectedTargetKeys keeps only actionable suggestions with valid keys', () => {
  expect(getInitialSelectedTargetKeys({
    suggestions: [
      {
        targetKey: 'page:title',
        diffHtml: '<del>Original</del><ins>Updated</ins>',
      },
      {
        targetKey: 'page:content',
        sourceContent: 'Original',
        suggestedContent: 'Original',
        contentFormat: 'text',
      },
      {
        targetKey: '   ',
        diffHtml: '<del>Ignored</del><ins>Ignored</ins>',
      },
    ],
  })).toEqual(['page:title']);
});

test('getSuggestionHeading builds clear per-target headings', () => {
  expect(getSuggestionHeading({
    targetType: 'page_title',
  }, 0)).toBe('Page title');

  expect(getSuggestionHeading({
    targetType: 'element_html',
    targetId: 12,
    fieldLabel: 'HTML',
    targetTitle: 'Content',
  }, 0)).toBe('Content block #12 - Content');

  expect(getSuggestionHeading({
    targetType: 'element_text',
    targetId: 9,
    fieldLabel: 'My big field',
    targetTitle: 'My content block',
  }, 0)).toBe('Content block #9 - My content block - My big field');
});

test('buildApplyRequestBody strips diffHtml before posting suggestions', () => {
  expect(buildApplyRequestBody([{
    targetKey: 'page:title',
    targetType: 'page_title',
    suggestedContent: 'Updated title',
    diffHtml: '<del>Old</del><ins>New</ins>',
  }])).toEqual({
    suggestions: [{
      apply: true,
      targetKey: 'page:title',
      targetType: 'page_title',
      suggestedContent: 'Updated title',
    }],
  });
});

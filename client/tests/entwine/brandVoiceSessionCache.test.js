/* eslint-env jest */
import {
  createBrandVoiceSessionCache,
  hasRecordContext,
} from '../../src/entwine/brandVoiceSessionCache';

test('createBrandVoiceSessionCache stores and returns the latest result', () => {
  const cache = createBrandVoiceSessionCache();
  const result = {
    rating: 'Good',
    reasoningSummary: 'Mostly aligned.',
    suggestions: [{
      targetKey: 'page:title',
      targetType: 'page_title',
      fieldName: 'Title',
      targetId: 3,
      sourceContent: 'Original title',
      suggestedContent: 'Updated title',
    }],
  };

  expect(cache.getResult()).toBeNull();
  expect(cache.getContentHash()).toBe('');
  expect(cache.setResult(result, 'draft-hash-1')).toEqual(result);
  expect(cache.getResult()).toEqual(result);
  expect(cache.getContentHash()).toBe('draft-hash-1');
  expect(cache.getSnapshot()).toEqual({
    result,
    contentHash: 'draft-hash-1',
  });
});

test('createBrandVoiceSessionCache clears cached results when invalidated', () => {
  const cache = createBrandVoiceSessionCache({
    result: {
      rating: 'Good',
      reasoningSummary: 'Mostly aligned.',
      suggestions: [{
        targetKey: 'page:title',
        targetType: 'page_title',
        fieldName: 'Title',
        targetId: 3,
        sourceContent: 'Original title',
        suggestedContent: 'Updated title',
      }],
    },
    contentHash: 'draft-hash-2',
  });

  expect(cache.clear()).toBeNull();
  expect(cache.getResult()).toBeNull();
  expect(cache.getContentHash()).toBe('');
});

test('hasRecordContext validates fqcn and record id inputs', () => {
  expect(hasRecordContext('SilverStripe\\CMS\\Model\\SiteTree', 3)).toBe(true);
  expect(hasRecordContext('', 3)).toBe(false);
  expect(hasRecordContext('SilverStripe\\CMS\\Model\\SiteTree', 0)).toBe(false);
  expect(hasRecordContext('SilverStripe\\CMS\\Model\\SiteTree', 3.5)).toBe(false);
});

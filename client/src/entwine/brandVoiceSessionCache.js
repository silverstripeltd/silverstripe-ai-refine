/**
 * Creates a lightweight cache for the latest modal result within one CMS view.
 */
const normaliseSnapshot = (initialValue = null) => {
  if (initialValue && typeof initialValue === 'object' && Object.prototype.hasOwnProperty.call(initialValue, 'result')) {
    return {
      result: initialValue.result || null,
      contentHash: typeof initialValue.contentHash === 'string' ? initialValue.contentHash : '',
    };
  }
  return {
    result: initialValue || null,
    contentHash: '',
  };
};

/**
 * Creates a lightweight cache for the latest modal result within one CMS view.
 */
export const createBrandVoiceSessionCache = (initialValue = null) => {
  let { result: cachedResult, contentHash: cachedContentHash } = normaliseSnapshot(initialValue);

  return {
    getResult: () => cachedResult,
    getContentHash: () => cachedContentHash,
    getSnapshot: () => ({
      result: cachedResult,
      contentHash: cachedContentHash,
    }),
    setResult: (result, contentHash = '') => {
      cachedResult = result;
      cachedContentHash = typeof contentHash === 'string' ? contentHash : '';
      return cachedResult;
    },
    clear: () => {
      cachedResult = null;
      cachedContentHash = '';
      return cachedResult;
    },
  };
};

/**
 * Checks whether the current button points at a saved record that can be queried.
 */
export const hasRecordContext = (fqcn, recordId) => (
  typeof fqcn === 'string'
  && fqcn.trim() !== ''
  && Number.isInteger(recordId)
  && recordId > 0
);

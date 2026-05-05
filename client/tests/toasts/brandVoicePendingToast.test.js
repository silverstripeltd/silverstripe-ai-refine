/* eslint-env jest */
import {
  clearPendingBrandVoiceToast,
  consumePendingBrandVoiceToast,
  PENDING_BRAND_VOICE_TOAST_KEY,
  storePendingBrandVoiceToast,
} from '../../src/toasts/brandVoicePendingToast';

beforeEach(() => {
  window.sessionStorage.clear();
});

test('stores a pending toast and consumes it once', () => {
  expect(storePendingBrandVoiceToast({
    type: 'success',
    message: 'Brand voice suggestions applied to draft content',
  })).toBe(true);

  expect(window.sessionStorage.getItem(PENDING_BRAND_VOICE_TOAST_KEY)).toBeTruthy();
  expect(consumePendingBrandVoiceToast()).toEqual({
    type: 'success',
    message: 'Brand voice suggestions applied to draft content',
  });
  expect(window.sessionStorage.getItem(PENDING_BRAND_VOICE_TOAST_KEY)).toBeNull();
  expect(consumePendingBrandVoiceToast()).toBeNull();
});

test('rejects invalid toasts and clears pending entries', () => {
  expect(storePendingBrandVoiceToast({
    type: 'info',
    message: 'Nope',
  })).toBe(false);

  window.sessionStorage.setItem(PENDING_BRAND_VOICE_TOAST_KEY, JSON.stringify({
    type: 'warning',
    message: 'Some suggestions could not be applied',
  }));

  expect(clearPendingBrandVoiceToast()).toBe(true);
  expect(window.sessionStorage.getItem(PENDING_BRAND_VOICE_TOAST_KEY)).toBeNull();
});

test('consumes malformed stored json as null and clears the pending entry', () => {
  window.sessionStorage.setItem(PENDING_BRAND_VOICE_TOAST_KEY, '{not-json');

  expect(consumePendingBrandVoiceToast()).toBeNull();
  expect(window.sessionStorage.getItem(PENDING_BRAND_VOICE_TOAST_KEY)).toBeNull();
});

test('returns false when session storage access fails', () => {
  const originalSessionStorage = window.sessionStorage;

  try {
    Object.defineProperty(window, 'sessionStorage', {
      configurable: true,
      get: () => {
        throw new Error('blocked');
      },
    });

    expect(storePendingBrandVoiceToast({
      type: 'success',
      message: 'Blocked',
    })).toBe(false);
    expect(clearPendingBrandVoiceToast()).toBe(false);
    expect(consumePendingBrandVoiceToast()).toBeNull();
  } finally {
    Object.defineProperty(window, 'sessionStorage', {
      configurable: true,
      value: originalSessionStorage,
    });
  }
});

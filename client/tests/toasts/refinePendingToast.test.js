/* eslint-env jest */
import {
  clearPendingRefineToast,
  consumePendingRefineToast,
  PENDING_REFINE_TOAST_KEY,
  storePendingRefineToast,
} from '../../src/toasts/refinePendingToast';

beforeEach(() => {
  window.sessionStorage.clear();
});

test('stores a pending toast and consumes it once', () => {
  expect(storePendingRefineToast({
    type: 'success',
    message: 'Refine suggestions applied to draft content',
  })).toBe(true);

  expect(window.sessionStorage.getItem(PENDING_REFINE_TOAST_KEY)).toBeTruthy();
  expect(consumePendingRefineToast()).toEqual({
    type: 'success',
    message: 'Refine suggestions applied to draft content',
  });
  expect(window.sessionStorage.getItem(PENDING_REFINE_TOAST_KEY)).toBeNull();
  expect(consumePendingRefineToast()).toBeNull();
});

test('rejects invalid toasts and clears pending entries', () => {
  expect(storePendingRefineToast({
    type: 'info',
    message: 'Nope',
  })).toBe(false);

  window.sessionStorage.setItem(PENDING_REFINE_TOAST_KEY, JSON.stringify({
    type: 'warning',
    message: 'Some suggestions could not be applied',
  }));

  expect(clearPendingRefineToast()).toBe(true);
  expect(window.sessionStorage.getItem(PENDING_REFINE_TOAST_KEY)).toBeNull();
});

test('consumes malformed stored json as null and clears the pending entry', () => {
  window.sessionStorage.setItem(PENDING_REFINE_TOAST_KEY, '{not-json');

  expect(consumePendingRefineToast()).toBeNull();
  expect(window.sessionStorage.getItem(PENDING_REFINE_TOAST_KEY)).toBeNull();
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

    expect(storePendingRefineToast({
      type: 'success',
      message: 'Blocked',
    })).toBe(false);
    expect(clearPendingRefineToast()).toBe(false);
    expect(consumePendingRefineToast()).toBeNull();
  } finally {
    Object.defineProperty(window, 'sessionStorage', {
      configurable: true,
      value: originalSessionStorage,
    });
  }
});

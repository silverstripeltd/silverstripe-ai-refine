export const PENDING_REFINE_TOAST_KEY = 'ai-refine.pending-toast';

/**
 * Returns sessionStorage when the browser allows it for pending toast state.
 */
const getSessionStorage = () => {
  if (typeof window === 'undefined') {
    return null;
  }

  try {
    return window.sessionStorage;
  } catch {
    return null;
  }
};

/**
 * Checks whether a toast payload is safe to persist and replay after reload.
 */
const isValidToast = (toast) => (
  typeof toast?.message === 'string'
  && toast.message.trim() !== ''
  && ['success', 'warning', 'error'].includes(toast.type)
);

/**
 * Removes any stored refine toast that is waiting for the next page load.
 */
export const clearPendingRefineToast = () => {
  const storage = getSessionStorage();
  if (!storage) {
    return false;
  }

  try {
    storage.removeItem(PENDING_REFINE_TOAST_KEY);
    return true;
  } catch {
    return false;
  }
};

/**
 * Stores a toast payload so it can be replayed after the CMS reloads.
 */
export const storePendingRefineToast = (toast) => {
  const storage = getSessionStorage();
  if (!storage || !isValidToast(toast)) {
    return false;
  }

  try {
    storage.setItem(PENDING_REFINE_TOAST_KEY, JSON.stringify({
      type: toast.type,
      message: toast.message.trim(),
    }));
    return true;
  } catch {
    return false;
  }
};

/**
 * Reads and clears the next pending toast so it only appears once.
 */
export const consumePendingRefineToast = () => {
  const storage = getSessionStorage();
  if (!storage) {
    return null;
  }

  try {
    const rawValue = storage.getItem(PENDING_REFINE_TOAST_KEY);
    storage.removeItem(PENDING_REFINE_TOAST_KEY);
    if (!rawValue) {
      return null;
    }

    const parsedValue = JSON.parse(rawValue);

    return isValidToast(parsedValue)
      ? { type: parsedValue.type, message: parsedValue.message.trim() }
      : null;
  } catch {
    return null;
  }
};

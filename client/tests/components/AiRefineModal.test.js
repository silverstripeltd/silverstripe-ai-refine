/* eslint-env jest */
/* eslint-disable import/first */
jest.mock('lib/Config', () => ({
  __esModule: true,
  default: {
    get: () => null,
    getSection: () => ({}),
  },
}), { virtual: true });

jest.mock('lib/urls', () => ({
  joinUrlPaths: (...parts) => parts.join('/'),
}), { virtual: true });

jest.mock('state/toasts/ToastsActions', () => ({}), { virtual: true });

jest.mock('redux', () => ({
  bindActionCreators: (actions) => actions,
}), { virtual: true });

jest.mock('react-redux', () => ({
  connect: () => (Component) => Component,
}), { virtual: true });

jest.mock('reactstrap', () => {
  const React = jest.requireActual('react');

  return {
    Button: ({ children, ...props }) => {
      delete props.outline;
      return React.createElement('button', props, children);
    },
    Modal: ({ children }) => React.createElement('div', null, children),
    ModalBody: ({ children }) => React.createElement('div', null, children),
    ModalHeader: ({ children, close }) => React.createElement(
      'div',
      null,
      React.createElement('h1', null, children),
      close,
    ),
    Spinner: (props) => React.createElement('div', props, 'Loading'),
  };
});

import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { AiRefineModal } from '../../src/components/AiRefineModal';
import { PENDING_REFINE_TOAST_KEY } from '../../src/toasts/refinePendingToast';

/**
 * Builds the modal props fixture with toast spies and optional overrides.
 */
const createProps = (overrides = {}) => ({
  fqcn: 'SilverStripe\\CMS\\Model\\SiteTree',
  recordId: 99,
  initialResult: null,
  initialContentHash: '',
  isFormDirty: false,
  onClosed: jest.fn(),
  onResultChange: jest.fn(),
  onStaleResult: jest.fn(),
  actions: {
    toasts: {
      error: jest.fn(),
      success: jest.fn(),
      warning: jest.fn(),
    },
  },
  ...overrides,
});

afterEach(() => {
  delete global.fetch;
  window.sessionStorage.clear();
});

test('renders a visible close button in the modal header', async () => {
  global.fetch = jest.fn().mockResolvedValue({
    ok: true,
    json: async () => ({
      meta: {
        refine: {
          title: 'Refine page content with AI',
        },
      },
    }),
  });

  const props = createProps();

  render(<AiRefineModal {...props} />);

  expect(screen.getByText('Refine page content with AI')).not.toBeNull();
  const closeButton = screen.getByRole('button', { name: 'Close' });

  expect(closeButton.className).toContain('btn-close');
  expect(closeButton.className).toContain('modal__close-button');
  expect(closeButton.querySelector('.font-icon-cancel')).not.toBeNull();

  fireEvent.click(closeButton);

  expect(props.onClosed).toHaveBeenCalledTimes(1);

  await waitFor(() => expect(global.fetch).toHaveBeenCalledTimes(1));
  expect(screen.queryByText(/save the page to draft before checking if you have unsaved changes/i)).toBeNull();
});

test('disables check and apply while the edit form is dirty', async () => {
  global.fetch = jest.fn().mockResolvedValue({
    ok: true,
    json: async () => ({
      meta: {
        refine: {
          labels: {
            apply: 'Apply changes',
          },
          state: {
            contentHash: 'draft-hash-1',
            supportsApply: true,
          },
        },
      },
    }),
  });

  render(<AiRefineModal {...createProps({
    initialContentHash: 'draft-hash-1',
    isFormDirty: true,
    initialResult: {
      rating: 'Good',
      reasoningSummary: 'Mostly aligned.',
      suggestions: [{
        targetKey: 'page:title',
        targetType: 'page_title',
        targetId: 99,
        fieldName: 'Title',
        sourceContent: 'Original title',
        suggestedContent: 'Updated title',
        diffHtml: '<del>Original title</del> <ins>Updated title</ins>',
      }],
    },
  })}
  />);

  await waitFor(() => expect(global.fetch).toHaveBeenCalledTimes(1));

  expect(screen.queryByText(/save the page to draft before checking if you have unsaved changes/i)).not.toBeNull();
  await waitFor(() => {
    expect(screen.getByRole('button', { name: 'Regenerate' }).disabled).toBe(true);
    expect(screen.getByRole('button', { name: 'Apply changes' }).disabled).toBe(true);
    expect(screen.getByRole('checkbox', { name: 'Apply Page title' }).disabled).toBe(true);
  });
});

test('checks content, renders per-suggestion review cards, and applies selected suggestions', async () => {
  const reload = jest.fn();
  const originalLocation = window.location;

  Object.defineProperty(window, 'location', {
    configurable: true,
    value: {
      ...originalLocation,
      reload,
    },
  });

  global.fetch = jest.fn()
    .mockResolvedValueOnce({
      ok: true,
      json: async () => ({
        meta: {
          refine: {
            actions: {
              checkUrl: '/schema-check-url',
              applyUrl: '/schema-apply-url',
            },
            labels: {
              apply: 'Apply changes',
            },
            state: {
              contentHash: 'draft-hash-1',
              supportsApply: true,
            },
          },
        },
      }),
    })
    .mockResolvedValueOnce({
      ok: true,
      json: async () => ({
        rating: 'Strong',
        reasoningSummary: 'Closer to the brief.',
        suggestions: [{
          targetKey: 'element:12:html',
          targetType: 'element_html',
          targetId: 12,
          fieldName: 'HTML',
          fieldLabel: 'HTML',
          sourceContent: 'Current paragraph',
          suggestedContent: '<p>Updated paragraph</p>',
          targetTitle: 'Content',
          contentFormat: 'html',
          diffHtml: '<del>Current paragraph</del><ins><p>Updated paragraph</p></ins>',
        }],
      }),
    })
    .mockResolvedValueOnce({
      ok: true,
      json: async () => ({
        appliedCount: 1,
        skippedCount: 0,
        reloadRequired: true,
      }),
    });

  const props = createProps();

  try {
    const { container } = render(<AiRefineModal {...props} />);

    await waitFor(() => expect(global.fetch).toHaveBeenCalledTimes(1));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Refine' }).disabled).toBe(false));
    expect(screen.getByRole('button', { name: 'Refine' }).getAttribute('color')).toBe('info');
    expect(screen.queryByRole('button', { name: 'Apply changes' })).toBeNull();

    fireEvent.click(screen.getByRole('button', { name: 'Refine' }));

    await waitFor(() => expect(global.fetch).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(screen.queryByText('Content block #12 - Content')).not.toBeNull());

    expect(props.onResultChange).toHaveBeenCalledWith({
      rating: 'Strong',
      ratingLabel: '',
      reasoningSummary: 'Closer to the brief.',
      suggestions: [{
        targetKey: 'element:12:html',
        targetType: 'element_html',
        targetId: 12,
        fieldName: 'HTML',
        fieldLabel: 'HTML',
        sourceContent: 'Current paragraph',
        suggestedContent: '<p>Updated paragraph</p>',
        targetTitle: 'Content',
        contentFormat: 'html',
        diffHtml: '<del>Current paragraph</del><ins><p>Updated paragraph</p></ins>',
      }],
    }, 'draft-hash-1');
    expect(screen.queryByText('Content block #12 - Content')).not.toBeNull();
    expect(container.querySelectorAll('.ai-refine-modal__suggestion-diff')).toHaveLength(1);
    expect(container.querySelector('.ai-refine-modal__suggestion-diff del')?.textContent).toContain('Current paragraph');
    expect(container.querySelector('.ai-refine-modal__suggestion-diff ins')?.textContent).toContain('Updated paragraph');
    expect(screen.queryByText('Refine rating')).toBeNull();
    expect(screen.queryByText('Reasoning summary')).toBeNull();
    expect(container.querySelector('textarea.ai-refine-modal__reasoning')).toBeNull();
    expect(container.querySelector('.ai-refine-modal__reasoning')?.tagName).toBe('P');
    expect(screen.queryByText('Closer to the brief.')).not.toBeNull();
    expect(screen.queryByText('Current draft content')).toBeNull();
    expect(screen.queryByText('Suggested draft content')).toBeNull();
    expect(screen.getByRole('button', { name: 'Apply changes' }).getAttribute('color')).toBe('info');
    expect(container.querySelectorAll('.ai-refine-modal__actions button')).toHaveLength(1);
    expect(container.querySelector('.ai-refine-modal__footer-actions button')?.textContent).toBe('Apply changes');

    fireEvent.click(screen.getByRole('button', { name: 'Apply changes' }));

    await waitFor(() => expect(global.fetch).toHaveBeenCalledTimes(3));

    expect(global.fetch).toHaveBeenNthCalledWith(2, '/schema-check-url', expect.objectContaining({
      method: 'POST',
    }));
    expect(global.fetch).toHaveBeenNthCalledWith(3, '/schema-apply-url', expect.objectContaining({
      method: 'POST',
    }));
    expect(JSON.parse(global.fetch.mock.calls[2][1].body)).toEqual({
      suggestions: [{
        apply: true,
        targetKey: 'element:12:html',
        targetType: 'element_html',
        targetId: 12,
        fieldName: 'HTML',
        fieldLabel: 'HTML',
        sourceContent: 'Current paragraph',
        suggestedContent: '<p>Updated paragraph</p>',
        targetTitle: 'Content',
        contentFormat: 'html',
      }],
    });
    expect(props.actions.toasts.success).not.toHaveBeenCalledWith('Refine suggestions applied to draft content');
    expect(JSON.parse(window.sessionStorage.getItem(PENDING_REFINE_TOAST_KEY))).toEqual({
      type: 'success',
      message: 'Refine suggestions applied to draft content',
    });
    expect(reload).toHaveBeenCalledTimes(1);
  } finally {
    Object.defineProperty(window, 'location', {
      configurable: true,
      value: originalLocation,
    });
  }
});

test('renders a single diff row for each suggestion', async () => {
  global.fetch = jest.fn().mockResolvedValue({
    ok: true,
    json: async () => ({
      meta: {
        refine: {
          labels: {
            apply: 'Apply changes',
          },
          state: {
            contentHash: 'draft-hash-2',
            supportsApply: true,
          },
        },
      },
    }),
  });

  const { container } = render(<AiRefineModal {...createProps({
    initialContentHash: 'draft-hash-2',
    initialResult: {
      rating: 'Good',
      reasoningSummary: 'Mostly aligned.',
      suggestions: [
        {
          targetKey: 'page:title',
          targetType: 'page_title',
          targetId: 99,
          fieldName: 'Title',
          fieldLabel: 'Title',
          sourceContent: 'Original title',
          suggestedContent: 'Updated title',
          contentFormat: 'text',
          diffHtml: '<del>Original title</del> <ins>Updated title</ins>',
        },
        {
          targetKey: 'element:9:field:mybigfield',
          targetType: 'element_text',
          targetId: 9,
          fieldName: 'MyBigField',
          fieldLabel: 'My big field',
          targetTitle: 'My content block',
          sourceContent: 'Original paragraph',
          suggestedContent: 'Updated paragraph',
          contentFormat: 'text',
          diffHtml: '<del>Original paragraph</del> <ins>Updated paragraph</ins>',
        },
        {
          targetKey: 'page:content',
          targetType: 'page_content',
          targetId: 99,
          fieldName: 'Content',
          fieldLabel: 'Content',
          sourceContent: 'Original body',
          suggestedContent: 'Original body',
          contentFormat: 'text',
          diffHtml: 'Original body',
        },
      ],
    },
  })}
  />);

  await waitFor(() => expect(global.fetch).toHaveBeenCalledTimes(1));
  await waitFor(() => expect(screen.queryByText('Page title')).not.toBeNull());

  expect(container.querySelectorAll('.ai-refine-modal__suggestion-diff')).toHaveLength(2);
  expect(container.querySelectorAll('.ai-refine-modal__suggestion-diff del')).toHaveLength(2);
  expect(container.querySelectorAll('.ai-refine-modal__suggestion-diff ins')).toHaveLength(2);
  expect(screen.queryByText('Refine rating')).toBeNull();
  expect(screen.queryByText('Reasoning summary')).toBeNull();
  expect(container.querySelector('textarea.ai-refine-modal__reasoning')).toBeNull();
  expect(container.querySelector('.ai-refine-modal__reasoning')?.textContent).toBe('Mostly aligned.');
  expect(screen.queryByText(/^Field:/)).toBeNull();
  expect(screen.queryByText(/Element ID:/)).toBeNull();
  expect(screen.queryByText('Current draft content')).toBeNull();
  expect(screen.queryByText('Suggested draft content')).toBeNull();
  expect(screen.queryByText('Page title')).not.toBeNull();
  expect(screen.queryByText('Content block #9 - My content block - My big field')).not.toBeNull();
  expect(screen.queryByText('Page content')).toBeNull();
});

test('renders display labels for rating enums and keeps styled rewrite heading markup', async () => {
  global.fetch = jest.fn().mockResolvedValue({
    ok: true,
    json: async () => ({
      meta: {
        refine: {
          ratingLabels: {
            NeedsWork: 'Needs work',
          },
          state: {
            contentHash: 'draft-hash-3',
            supportsApply: true,
          },
        },
      },
    }),
  });

  render(<AiRefineModal {...createProps({
    initialContentHash: 'draft-hash-3',
    initialResult: {
      rating: 'NeedsWork',
      reasoningSummary: 'This needs a clearer call to action.',
      suggestions: [{
        targetKey: 'page:content',
        targetType: 'page_content',
        targetId: 99,
        fieldName: 'Content',
        fieldLabel: 'Content',
        sourceContent: 'Please check the spelling.',
        suggestedContent: 'Please verify the URL or return to the homepage.',
        contentFormat: 'html',
        diffHtml: '<del><p>Please check the spelling.</p></del><ins><p>Please verify the URL or return to the homepage.</p></ins>',
      }],
    },
  })}
  />);

  await waitFor(() => expect(global.fetch).toHaveBeenCalledTimes(1));
  await waitFor(() => expect(screen.getByText('Needs work')).not.toBeNull());

  expect(screen.getByText('Needs work')).not.toBeNull();
  expect(screen.getByText('Suggested rewrites').className).toContain('ai-refine-modal__section-heading');
  expect(screen.getByLabelText('Draft diff: Page content').querySelector('del p')).not.toBeNull();
  expect(screen.getByLabelText('Draft diff: Page content').querySelector('ins p')).not.toBeNull();
});

test('shows an aligned success banner when an Excellent result has no suggestions', async () => {
  global.fetch = jest.fn().mockResolvedValue({
    ok: true,
    json: async () => ({
      meta: {
        refine: {
          state: {
            contentHash: 'draft-hash-4',
            supportsApply: true,
          },
        },
      },
    }),
  });

  render(<AiRefineModal {...createProps({
    initialContentHash: 'draft-hash-4',
    initialResult: {
      rating: 'Excellent',
      reasoningSummary: 'Everything already matches.',
      suggestions: [],
    },
  })}
  />);

  await waitFor(() => expect(global.fetch).toHaveBeenCalledTimes(1));
  const banner = await screen.findByText('Your content already matches the refine rules. No changes needed.');

  expect(banner.className).toContain('ai-refine-modal__banner');
  expect(banner.className).toContain('ai-refine-modal__banner--success');
  expect(screen.queryByText('Suggested rewrites')).toBeNull();
  expect(screen.queryByText('No rewrite suggestions were returned for this page.')).toBeNull();
  expect(screen.queryByRole('checkbox', { name: 'Apply Page title' })).toBeNull();
  expect(screen.queryByRole('button', { name: 'Apply changes' })).toBeNull();
});

test('shows the generic no-suggestions message for non-Excellent empty results', async () => {
  global.fetch = jest.fn().mockResolvedValue({
    ok: true,
    json: async () => ({
      meta: {
        refine: {
          state: {
            contentHash: 'draft-hash-5',
            supportsApply: true,
          },
        },
      },
    }),
  });

  render(<AiRefineModal {...createProps({
    initialContentHash: 'draft-hash-5',
    initialResult: {
      rating: 'Good',
      reasoningSummary: 'Aligned overall, but no rewrites were returned.',
      suggestions: [],
    },
  })}
  />);

  await waitFor(() => expect(global.fetch).toHaveBeenCalledTimes(1));
  await waitFor(() => expect(screen.queryByText('Suggested rewrites')).not.toBeNull());

  expect(screen.queryByText('Suggested rewrites')).not.toBeNull();
  expect(screen.queryByText('No rewrite suggestions were returned for this page.')).not.toBeNull();
  expect(screen.queryByText('Your content already matches the refine rules. No changes needed.')).toBeNull();
});

test('shows schema load failures in the modal banner and toast state', async () => {
  global.fetch = jest.fn().mockResolvedValue({
    ok: false,
    json: async () => ({
      error: 'Schema unavailable',
    }),
  });

  const props = createProps();

  render(<AiRefineModal {...props} />);

  await waitFor(() => expect(global.fetch).toHaveBeenCalledTimes(1));
  await waitFor(() => expect(screen.getByText('Schema unavailable')).not.toBeNull());
  expect(props.actions.toasts.error).toHaveBeenCalledWith('Schema unavailable');
  expect(screen.getByText('Schema unavailable')).not.toBeNull();
  expect(screen.queryByRole('button', { name: 'Refine' })).toBeNull();
});

test('clears stale cached results when the saved draft hash has changed', async () => {
  global.fetch = jest.fn().mockResolvedValue({
    ok: true,
    json: async () => ({
      meta: {
        refine: {
          state: {
            contentHash: 'draft-hash-new',
            supportsApply: true,
          },
        },
      },
    }),
  });

  const props = createProps({
    initialContentHash: 'draft-hash-old',
    initialResult: {
      rating: 'Good',
      reasoningSummary: 'Mostly aligned.',
      suggestions: [{
        targetKey: 'page:title',
        targetType: 'page_title',
        targetId: 99,
        fieldName: 'Title',
        fieldLabel: 'Title',
        sourceContent: 'Original title',
        suggestedContent: 'Updated title',
        contentFormat: 'text',
        diffHtml: '<del>Original title</del> <ins>Updated title</ins>',
      }],
    },
  });

  render(<AiRefineModal {...props} />);

  await waitFor(() => expect(global.fetch).toHaveBeenCalledTimes(1));
  await waitFor(() => expect(props.onStaleResult).toHaveBeenCalledTimes(1));

  expect(props.onStaleResult).toHaveBeenCalledTimes(1);
  expect(screen.queryByText('Mostly aligned.')).toBeNull();
  expect(screen.queryByText('Page title')).toBeNull();
  expect(screen.queryByText('Click the button below to check the content on this page against your writing style and tone rules.')).not.toBeNull();
  expect(screen.getByRole('button', { name: 'Refine' })).not.toBeNull();
});

test('shows a warning toast for partial apply responses that do not reload the cms', async () => {
  const reload = jest.fn();
  const originalLocation = window.location;

  Object.defineProperty(window, 'location', {
    configurable: true,
    value: {
      ...originalLocation,
      reload,
    },
  });

  global.fetch = jest.fn()
    .mockResolvedValueOnce({
      ok: true,
      json: async () => ({
        meta: {
          refine: {
            actions: {
              checkUrl: '/schema-check-url',
              applyUrl: '/schema-apply-url',
            },
            labels: {
              apply: 'Apply changes',
            },
            state: {
              supportsApply: true,
            },
          },
        },
      }),
    })
    .mockResolvedValueOnce({
      ok: true,
      json: async () => ({
        rating: 'Good',
        reasoningSummary: 'Needs one rewrite.',
        suggestions: [{
          targetKey: 'page:title',
          targetType: 'page_title',
          targetId: 99,
          fieldName: 'Title',
          fieldLabel: 'Page name',
          sourceContent: 'Original title',
          suggestedContent: 'Updated title',
          contentFormat: 'text',
          diffHtml: '<del>Original title</del><ins>Updated title</ins>',
        }],
      }),
    })
    .mockResolvedValueOnce({
      ok: true,
      json: async () => ({
        appliedCount: 1,
        skippedCount: 1,
        reloadRequired: false,
      }),
    });

  const props = createProps();

  try {
    render(<AiRefineModal {...props} />);

    await waitFor(() => expect(global.fetch).toHaveBeenCalledTimes(1));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Refine' }).disabled).toBe(false));

    fireEvent.click(screen.getByRole('button', { name: 'Refine' }));

    await waitFor(() => expect(global.fetch).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Apply changes' }).disabled).toBe(false));

    fireEvent.click(screen.getByRole('button', { name: 'Apply changes' }));

    await waitFor(() => expect(global.fetch).toHaveBeenCalledTimes(3));

    expect(props.actions.toasts.warning).toHaveBeenCalledWith('Some suggestions could not be applied');
    expect(window.sessionStorage.getItem(PENDING_REFINE_TOAST_KEY)).toBeNull();
    expect(reload).not.toHaveBeenCalled();
  } finally {
    Object.defineProperty(window, 'location', {
      configurable: true,
      value: originalLocation,
    });
  }
});

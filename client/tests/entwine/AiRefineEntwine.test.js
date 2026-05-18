/* eslint-env jest */
/* eslint-disable import/first, global-require */
jest.mock('react-dom/client', () => ({
  createRoot: jest.fn(() => ({
    render: jest.fn(),
    unmount: jest.fn(),
  })),
}));

jest.mock('lib/Injector', () => ({
  loadComponent: jest.fn(),
}), { virtual: true });

const selectorDefinitions = {};

/**
 * Creates the small jQuery facade used to capture the entwine definitions in tests.
 */
const createFakeJQuery = () => {
  const fakeJQuery = jest.fn((selector) => {
    if (typeof selector === 'string') {
      return {
        entwine: (definition) => {
          selectorDefinitions[selector] = definition;
          return definition;
        },
      };
    }

    return selector;
  });

  fakeJQuery.entwine = jest.fn((namespace, callback) => callback(fakeJQuery));
  fakeJQuery.noticeAdd = jest.fn();

  return fakeJQuery;
};

/**
 * Loads the entwine module with a configurable pending toast fixture.
 */
const loadEntwineDefinitions = (pendingToast = null) => {
  jest.resetModules();
  Object.keys(selectorDefinitions).forEach((selector) => delete selectorDefinitions[selector]);

  const fakeJQuery = createFakeJQuery();
  window.jQuery = fakeJQuery;
  window.$ = fakeJQuery;

  jest.doMock('../../src/toasts/refinePendingToast', () => ({
    consumePendingRefineToast: jest.fn(() => pendingToast),
  }));

  jest.isolateModules(() => {
    require('../../src/entwine/AiRefineEntwine');
  });

  return {
    fakeJQuery,
    actionDefinition: selectorDefinitions['.ai-refine__action'],
  };
};

afterEach(() => {
  delete window.jQuery;
  delete window.$;
});

test('replays any pending toast when the refine action matches', () => {
  const { fakeJQuery, actionDefinition } = loadEntwineDefinitions({
    type: 'success',
    message: 'Refine suggestions applied to draft content',
  });
  const context = {
    _super: jest.fn(),
  };

  actionDefinition.onmatch.call(context);

  expect(fakeJQuery.noticeAdd).toHaveBeenCalledWith(expect.objectContaining({
    text: 'Refine suggestions applied to draft content',
    type: 'success',
    stayTime: 5000,
  }));
  expect(context._super).toHaveBeenCalledTimes(1);
});

test('warns instead of opening the modal when the action has no saved record context', () => {
  const { fakeJQuery, actionDefinition } = loadEntwineDefinitions();
  const context = {
    attr: jest.fn((name) => {
      if (name === 'data-fqcn') {
        return '';
      }

      return '0';
    }),
    renderRefineModal: jest.fn(),
  };
  const event = {
    preventDefault: jest.fn(),
  };

  const result = actionDefinition.onclick.call(context, event);

  expect(result).toBe(false);
  expect(event.preventDefault).toHaveBeenCalledTimes(1);
  expect(fakeJQuery.noticeAdd).toHaveBeenCalledWith(expect.objectContaining({
    text: 'Save the page before opening AI refine.',
    type: 'warning',
  }));
  expect(context.renderRefineModal).not.toHaveBeenCalled();
});

test('opens the modal when the action has valid record context', () => {
  const { fakeJQuery, actionDefinition } = loadEntwineDefinitions();
  const context = {
    attr: jest.fn((name) => {
      if (name === 'data-fqcn') {
        return 'SilverStripe\\\\CMS\\\\Model\\\\SiteTree';
      }

      return '42';
    }),
    renderRefineModal: jest.fn(),
  };
  const event = {
    preventDefault: jest.fn(),
  };

  const result = actionDefinition.onclick.call(context, event);

  expect(result).toBe(false);
  expect(event.preventDefault).toHaveBeenCalledTimes(1);
  expect(context.renderRefineModal).toHaveBeenCalledWith(true);
  expect(fakeJQuery.noticeAdd).not.toHaveBeenCalled();
});

test('passes the cached result hash and cache callbacks into the modal renderer', () => {
  const { actionDefinition } = loadEntwineDefinitions();
  const cachedResult = {
    rating: 'Good',
    reasoningSummary: 'Mostly aligned.',
    suggestions: [],
  };
  const render = jest.fn();
  const blur = jest.fn();
  const context = {
    attr: jest.fn((name) => {
      if (name === 'data-fqcn') {
        return 'SilverStripe\\\\CMS\\\\Model\\\\SiteTree';
      }

      return '42';
    }),
    blur,
    clearCachedRefineResult: jest.fn(),
    getCachedRefineResultSnapshot: jest.fn(() => ({
      result: cachedResult,
      contentHash: 'draft-hash-2',
    })),
    getComponent: jest.fn(() => 'AiRefineModal'),
    getReactContainer: jest.fn(() => ({ remove: jest.fn() })),
    getReactRoot: jest.fn(() => ({
      render,
      unmount: jest.fn(),
    })),
    isRefineFormDirty: jest.fn(() => false),
    setCachedRefineResult: jest.fn(),
    setComponent: jest.fn(),
    setReactContainer: jest.fn(),
    setReactRoot: jest.fn(),
  };

  actionDefinition.renderRefineModal.call(context);

  const renderedElement = render.mock.calls[0][0];

  expect(context.getCachedRefineResultSnapshot).toHaveBeenCalledTimes(1);
  expect(renderedElement.props.initialResult).toEqual(cachedResult);
  expect(renderedElement.props.initialContentHash).toBe('draft-hash-2');
  renderedElement.props.onResultChange({ rating: 'Excellent', reasoningSummary: 'Aligned.', suggestions: [] }, 'draft-hash-3');
  renderedElement.props.onStaleResult();
  renderedElement.props.onClosed();
  expect(context.setCachedRefineResult).toHaveBeenCalledWith(
    { rating: 'Excellent', reasoningSummary: 'Aligned.', suggestions: [] },
    'draft-hash-3'
  );
  expect(context.clearCachedRefineResult).toHaveBeenCalledTimes(1);
  expect(blur).toHaveBeenCalledTimes(1);
});

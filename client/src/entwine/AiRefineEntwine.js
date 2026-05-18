/* global window */
import React from 'react';
import { createRoot } from 'react-dom/client';
import { loadComponent } from 'lib/Injector';
import {
  createRefineSessionCache,
  hasRecordContext,
} from './refineSessionCache';
import { consumePendingRefineToast } from '../toasts/refinePendingToast';

const jQuery = window.jQuery || window.$;
const REFINE_RECORD_CLASS_FIELD = 'AiRefineRecordClass';

/**
 * Finds the CMS content wrapper that owns a toolbar button or edit form node.
 */
const getCmsContent = ($element) => $element.closest('.cms-content');

/**
 * Resolves the main edit form that stores the record context for Refine.
 */
const getEditForm = ($element) => getCmsContent($element).find('.cms-edit-form').first();

/**
 * Reads the saved record ID from the current CMS edit form.
 */
const getRefineRecordId = ($element) => parseInt(
  getEditForm($element).find('input[name=ID]').val(),
  10,
);

/**
 * Reads the record class hidden field used to build Refine API URLs.
 */
const getRefineRecordClass = ($element) => {
  const value = getEditForm($element).find(`input[name=${REFINE_RECORD_CLASS_FIELD}]`).val();
  return typeof value === 'string' ? value.trim() : '';
};

/**
 * Returns the saved record context when the current form has everything Refine needs.
 */
const getRefineRecordContext = ($element) => {
  const fqcn = getRefineRecordClass($element);
  const recordId = getRefineRecordId($element);

  if (!hasRecordContext(fqcn, recordId)) {
    return null;
  }

  return {
    fqcn,
    recordId,
  };
};

/**
 * Resolves the injector context so loaded React components share the right CMS subtree.
 */
const getRefineInjectorContext = ($element) => {
  const cmsContent = getCmsContent($element).attr('id');
  return cmsContent ? { context: cmsContent } : {};
};

/**
 * Reads the saved record context from an already-rendered Refine action button.
 */
const getActionRecordContext = ($element) => {
  const fqcn = $element.attr('data-fqcn');
  const recordId = parseInt($element.attr('data-record-id'), 10);

  if (!hasRecordContext(fqcn, recordId)) {
    return null;
  }

  return {
    fqcn,
    recordId,
  };
};

/**
 * Reuses one session cache per CMS content panel to preserve modal state between opens.
 */
const getSharedRefineSessionCache = ($element) => {
  const cmsContent = getCmsContent($element);
  if (!cmsContent.length) {
    return createRefineSessionCache();
  }

  let cache = cmsContent.data('aiRefineSessionCache');
  if (!cache) {
    cache = createRefineSessionCache();
    cmsContent.data('aiRefineSessionCache', cache);
  }

  return cache;
};

/**
 * Unmounts and removes the rendered React tree for one entwine host node.
 */
const clearRenderedReactTree = (context) => {
  const root = context.getReactRoot();
  if (root) {
    root.unmount();
    context.setReactRoot(null);
  }

  const container = context.getReactContainer();
  if (container) {
    container.remove();
    context.setReactContainer(null);
  }
};

/**
 * Re-renders Refine buttons when edit-form state changes and optionally clears stale results.
 */
const syncRefineButtons = ($form, { clearCache = false } = {}) => {
  const cmsContent = $form.closest('.cms-content');
  if (!cmsContent.length) {
    return;
  }

  cmsContent.find('.ai-refine__action').each((index, element) => {
    const $button = jQuery(element);
    if (clearCache && typeof $button.clearCachedRefineResult === 'function') {
      $button.clearCachedRefineResult();
    }
    if (typeof $button.renderRefineModal === 'function') {
      $button.renderRefineModal();
    }
  });
};

/**
 * Replays any stored toast after a full CMS reload following an apply action.
 */
const showPendingRefineToast = () => {
  const toast = consumePendingRefineToast();
  if (!toast) {
    return;
  }

  jQuery.noticeAdd({
    text: toast.message,
    type: toast.type,
    stayTime: 5000,
    inEffect: { left: '0', opacity: 'show' },
  });
};

jQuery.entwine('ss.ai-refine', ($) => {
  $('.js-injector-boot .preview-mode-selector').entwine({
    ReactRoot: null,
    ReactContainer: null,
    Component: null,

    /**
     * Tears down any injected toolbar button React tree for this preview toolbar.
     */
    clearToolbarButton() {
      clearRenderedReactTree(this);
    },

    /**
     * Creates or returns the toolbar placeholder that hosts the Refine button.
     */
    getOrCreateToolbarButtonContainer() {
      let container = this.getReactContainer();
      if (container) {
        return container;
      }

      container = $('<span class="ai-refine__placeholder"></span>');
      const sharePlaceholder = this.find('> .share-draft-content__placeholder').first();
      if (sharePlaceholder.length) {
        sharePlaceholder.before(container);
      } else {
        const firstChild = this.children().first();
        if (firstChild.length) {
          firstChild.before(container);
        } else {
          this.prepend(container);
        }
      }

      this.setReactContainer(container);

      return container;
    },

    /**
     * Mounts or refreshes the toolbar action button when preview controls appear.
     */
    onmatch() {
      const recordContext = getRefineRecordContext(this);
      if (!recordContext) {
        this.clearToolbarButton();
        this._super();
        return;
      }

      let Component = this.getComponent();
      if (!Component) {
        Component = loadComponent('AiRefineActionButton', getRefineInjectorContext(this));
        this.setComponent(Component);
      }

      const container = this.getOrCreateToolbarButtonContainer();
      let root = this.getReactRoot();
      if (!root) {
        root = createRoot(container[0]);
        this.setReactRoot(root);
      }

      root.render(
        <Component
          fqcn={recordContext.fqcn}
          recordId={recordContext.recordId}
        />
      );

      this._super();
    },

    /**
     * Unmounts the toolbar button when the preview toolbar is removed.
     */
    onunmatch() {
      this.clearToolbarButton();
      this._super();
    },
  });
});

jQuery.entwine('ss', ($) => {
  $('.ai-refine__action').entwine({
    ReactRoot: null,
    ReactContainer: null,
    Component: null,

    /**
     * Returns the shared session cache used for modal result persistence in this CMS view.
     */
    getOrCreateRefineSessionCache() {
      return getSharedRefineSessionCache(this);
    },

    /**
     * Reads the cached Refine result for this record, if one exists.
     */
    getCachedRefineResult() {
      return this.getOrCreateRefineSessionCache().getResult();
    },

    /**
     * Reads the cached Refine result plus the draft hash it was generated from.
     */
    getCachedRefineResultSnapshot() {
      return this.getOrCreateRefineSessionCache().getSnapshot();
    },

    /**
     * Stores the latest modal result so reopening the modal keeps the last check.
     */
    setCachedRefineResult(result, contentHash = '') {
      this.getOrCreateRefineSessionCache().setResult(result, contentHash);
    },

    /**
     * Clears any cached Refine result when the underlying draft changes.
     */
    clearCachedRefineResult() {
      this.getOrCreateRefineSessionCache().clear();
    },

    /**
     * Replays any pending toast the first time the toolbar action matches.
     */
    onmatch() {
      showPendingRefineToast();
      this._super();
    },

    /**
     * Checks whether the CMS form has unsaved changes that should block apply actions.
     */
    isRefineFormDirty() {
      const editForm = getEditForm(this);
      return editForm.length > 0 && editForm.hasClass('changed');
    },

    /**
     * Mounts the Refine modal and wires cached results plus close handling into it.
     */
    renderRefineModal(createIfMissing = false) {
      const recordContext = getActionRecordContext(this);
      if (!recordContext) {
        return;
      }

      let container = this.getReactContainer();
      if (!container) {
        if (!createIfMissing) {
          return;
        }

        container = $('<div class="ai-refine-modal__container"></div>');
        $('body').append(container);
        this.setReactContainer(container);
      }

      let root = this.getReactRoot();
      if (!root) {
        if (!createIfMissing) {
          return;
        }

        root = createRoot(container[0]);
        this.setReactRoot(root);
      }

      let Component = this.getComponent();
      if (!Component) {
        Component = loadComponent('AiRefineModal');
        this.setComponent(Component);
      }
      const cachedResult = this.getCachedRefineResultSnapshot();

      const self = this;
      const handleClosed = () => {
        clearRenderedReactTree(self);
        self.blur();
      };

      root.render(
        <Component
          fqcn={recordContext.fqcn}
          recordId={recordContext.recordId}
          initialResult={cachedResult.result}
          initialContentHash={cachedResult.contentHash}
          isFormDirty={this.isRefineFormDirty()}
          onResultChange={(result, contentHash) => this.setCachedRefineResult(result, contentHash)}
          onStaleResult={() => this.clearCachedRefineResult()}
          onClosed={handleClosed}
        />
      );
    },

    /**
     * Opens the modal when the button has a valid saved record context.
     */
    onclick(e) {
      e.preventDefault();
      if (!getActionRecordContext(this)) {
        jQuery.noticeAdd({
          text: 'Save the page before opening AI refine.',
          type: 'warning',
        });
        return false;
      }

      this.renderRefineModal(true);

      return false;
    },

    /**
     * Cleans up any mounted modal instance when the action button leaves the DOM.
     */
    onunmatch() {
      clearRenderedReactTree(this);
    },
  });

  $('.cms-edit-form.changed').entwine({
    /**
     * Clears cached results as soon as the draft diverges from the saved source of truth.
     */
    onmatch() {
      syncRefineButtons(this, { clearCache: true });
      this._super();
    },
  });

  $('.cms-edit-form:not(.changed)').entwine({
    /**
     * Refreshes button state when the edit form returns to a clean saved draft.
     */
    onmatch() {
      syncRefineButtons(this);
      this._super();
    },
  });
});

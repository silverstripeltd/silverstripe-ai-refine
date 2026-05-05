/* global window */
import React from 'react';
import { createRoot } from 'react-dom/client';
import { loadComponent } from 'lib/Injector';
import {
  createBrandVoiceSessionCache,
  hasRecordContext,
} from './brandVoiceSessionCache';
import { consumePendingBrandVoiceToast } from '../toasts/brandVoicePendingToast';

const jQuery = window.jQuery || window.$;
const BRAND_VOICE_RECORD_CLASS_FIELD = 'AiBrandVoiceRecordClass';

/**
 * Finds the CMS content wrapper that owns a toolbar button or edit form node.
 */
const getCmsContent = ($element) => $element.closest('.cms-content');

/**
 * Resolves the main edit form that stores the record context for Brand Voice.
 */
const getEditForm = ($element) => getCmsContent($element).find('.cms-edit-form').first();

/**
 * Reads the saved record ID from the current CMS edit form.
 */
const getBrandVoiceRecordId = ($element) => parseInt(
  getEditForm($element).find('input[name=ID]').val(),
  10,
);

/**
 * Reads the record class hidden field used to build Brand Voice API URLs.
 */
const getBrandVoiceRecordClass = ($element) => {
  const value = getEditForm($element).find(`input[name=${BRAND_VOICE_RECORD_CLASS_FIELD}]`).val();
  return typeof value === 'string' ? value.trim() : '';
};

/**
 * Returns the saved record context when the current form has everything Brand Voice needs.
 */
const getBrandVoiceRecordContext = ($element) => {
  const fqcn = getBrandVoiceRecordClass($element);
  const recordId = getBrandVoiceRecordId($element);

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
const getBrandVoiceInjectorContext = ($element) => {
  const cmsContent = getCmsContent($element).attr('id');
  return cmsContent ? { context: cmsContent } : {};
};

/**
 * Reads the saved record context from an already-rendered Brand Voice action button.
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
const getSharedBrandVoiceSessionCache = ($element) => {
  const cmsContent = getCmsContent($element);
  if (!cmsContent.length) {
    return createBrandVoiceSessionCache();
  }

  let cache = cmsContent.data('aiBrandVoiceSessionCache');
  if (!cache) {
    cache = createBrandVoiceSessionCache();
    cmsContent.data('aiBrandVoiceSessionCache', cache);
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
 * Re-renders Brand Voice buttons when edit-form state changes and optionally clears stale results.
 */
const syncBrandVoiceButtons = ($form, { clearCache = false } = {}) => {
  const cmsContent = $form.closest('.cms-content');
  if (!cmsContent.length) {
    return;
  }

  cmsContent.find('.ai-brand-voice__action').each((index, element) => {
    const $button = jQuery(element);
    if (clearCache && typeof $button.clearCachedBrandVoiceResult === 'function') {
      $button.clearCachedBrandVoiceResult();
    }
    if (typeof $button.renderBrandVoiceModal === 'function') {
      $button.renderBrandVoiceModal();
    }
  });
};

/**
 * Replays any stored toast after a full CMS reload following an apply action.
 */
const showPendingBrandVoiceToast = () => {
  const toast = consumePendingBrandVoiceToast();
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

jQuery.entwine('ss.ai-brand-voice', ($) => {
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
     * Creates or returns the toolbar placeholder that hosts the Brand Voice button.
     */
    getOrCreateToolbarButtonContainer() {
      let container = this.getReactContainer();
      if (container) {
        return container;
      }

      container = $('<span class="ai-brand-voice__placeholder"></span>');
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
      const recordContext = getBrandVoiceRecordContext(this);
      if (!recordContext) {
        this.clearToolbarButton();
        this._super();
        return;
      }

      let Component = this.getComponent();
      if (!Component) {
        Component = loadComponent('AiBrandVoiceActionButton', getBrandVoiceInjectorContext(this));
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
  $('.ai-brand-voice__action').entwine({
    ReactRoot: null,
    ReactContainer: null,
    Component: null,

    /**
     * Returns the shared session cache used for modal result persistence in this CMS view.
     */
    getOrCreateBrandVoiceSessionCache() {
      return getSharedBrandVoiceSessionCache(this);
    },

    /**
     * Reads the cached Brand Voice result for this record, if one exists.
     */
    getCachedBrandVoiceResult() {
      return this.getOrCreateBrandVoiceSessionCache().getResult();
    },

    /**
     * Reads the cached Brand Voice result plus the draft hash it was generated from.
     */
    getCachedBrandVoiceResultSnapshot() {
      return this.getOrCreateBrandVoiceSessionCache().getSnapshot();
    },

    /**
     * Stores the latest modal result so reopening the modal keeps the last check.
     */
    setCachedBrandVoiceResult(result, contentHash = '') {
      this.getOrCreateBrandVoiceSessionCache().setResult(result, contentHash);
    },

    /**
     * Clears any cached Brand Voice result when the underlying draft changes.
     */
    clearCachedBrandVoiceResult() {
      this.getOrCreateBrandVoiceSessionCache().clear();
    },

    /**
     * Replays any pending toast the first time the toolbar action matches.
     */
    onmatch() {
      showPendingBrandVoiceToast();
      this._super();
    },

    /**
     * Checks whether the CMS form has unsaved changes that should block apply actions.
     */
    isBrandVoiceFormDirty() {
      const editForm = getEditForm(this);
      return editForm.length > 0 && editForm.hasClass('changed');
    },

    /**
     * Mounts the Brand Voice modal and wires cached results plus close handling into it.
     */
    renderBrandVoiceModal(createIfMissing = false) {
      const recordContext = getActionRecordContext(this);
      if (!recordContext) {
        return;
      }

      let container = this.getReactContainer();
      if (!container) {
        if (!createIfMissing) {
          return;
        }

        container = $('<div class="ai-brand-voice-modal__container"></div>');
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
        Component = loadComponent('AiBrandVoiceModal');
        this.setComponent(Component);
      }
      const cachedResult = this.getCachedBrandVoiceResultSnapshot();

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
          isFormDirty={this.isBrandVoiceFormDirty()}
          onResultChange={(result, contentHash) => this.setCachedBrandVoiceResult(result, contentHash)}
          onStaleResult={() => this.clearCachedBrandVoiceResult()}
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
          text: 'Save the page before opening AI brand voice.',
          type: 'warning',
        });
        return false;
      }

      this.renderBrandVoiceModal(true);

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
      syncBrandVoiceButtons(this, { clearCache: true });
      this._super();
    },
  });

  $('.cms-edit-form:not(.changed)').entwine({
    /**
     * Refreshes button state when the edit form returns to a clean saved draft.
     */
    onmatch() {
      syncBrandVoiceButtons(this);
      this._super();
    },
  });
});

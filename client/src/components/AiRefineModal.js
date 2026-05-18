/* eslint-disable react/no-danger */
import React, { useCallback, useEffect, useMemo, useState } from 'react';
import PropTypes from 'prop-types';
import { bindActionCreators } from 'redux';
import { connect } from 'react-redux';
import {
  Button,
  Modal,
  ModalBody,
  ModalHeader,
  Spinner,
} from 'reactstrap';
import * as toastsActions from 'state/toasts/ToastsActions';
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
  getSuggestionHeading,
  getModalConfig,
  getResponseErrorMessage,
  getSchemaHeaders,
  mergeSchemaConfig,
  suggestionHasRecommendedChange,
} from './refineModalHelpers';
import {
  clearPendingRefineToast,
  storePendingRefineToast,
} from '../toasts/refinePendingToast';

/**
 * Wraps fetch so every modal request gets consistent JSON parsing.
 */
const fetchJson = async (url, options = {}) => {
  const response = await window.fetch(url, {
    credentials: 'same-origin',
    ...options,
  });

  return {
    response,
    payload: await response.json(),
  };
};

/**
 * Sends the supplied toast through the matching CMS toast channel.
 */
const showToast = (toasts, toast) => {
  if (toast.type === 'warning') {
    toasts.warning(toast.message);
    return;
  }

  toasts.success(toast.message);
};

/**
 * Persists an apply toast across reload so the CMS can replay it after refresh.
 */
const reloadWithPendingToast = (toasts, toast) => {
  const storedToast = storePendingRefineToast(toast);
  if (!storedToast) {
    showToast(toasts, toast);
  }

  try {
    window.location.reload();
  } catch (error) {
    if (storedToast) {
      clearPendingRefineToast();
    }
    throw error;
  }
};

/**
 * Renders the Refine modal, handles API calls, and applies chosen suggestions.
 */
export const AiRefineModal = ({
  fqcn,
  recordId,
  initialResult = null,
  initialContentHash = '',
  isFormDirty = false,
  onClosed = null,
  onResultChange = null,
  onStaleResult = null,
  actions,
}) => {
  const [isOpen, setIsOpen] = useState(true);
  const [isLoadingSchema, setIsLoadingSchema] = useState(true);
  const [schemaError, setSchemaError] = useState('');
  const [schemaConfig, setSchemaConfig] = useState(defaultSchemaConfig);
  const [isChecking, setIsChecking] = useState(false);
  const [isApplying, setIsApplying] = useState(false);
  const [result, setResult] = useState(initialResult || null);
  const [selectedTargetKeys, setSelectedTargetKeys] = useState(() => getInitialSelectedTargetKeys(initialResult));
  const [hasValidatedCachedResult, setHasValidatedCachedResult] = useState(!initialResult);

  const modalConfig = useMemo(() => getModalConfig(), []);
  const schemaUrl = useMemo(() => buildSchemaUrl(fqcn, recordId, modalConfig), [fqcn, modalConfig, recordId]);
  const defaultCheckUrl = useMemo(() => buildCheckUrl(fqcn, recordId, modalConfig), [fqcn, modalConfig, recordId]);
  const defaultApplyUrl = useMemo(() => buildApplyUrl(fqcn, recordId, modalConfig), [fqcn, modalConfig, recordId]);

  /**
   * Resets the modal result back to its pre-check empty state.
   */
  const clearResult = useCallback(() => {
    setResult(null);
    setSelectedTargetKeys([]);
  }, []);

  useEffect(() => {
    setResult(initialResult || null);
    setSelectedTargetKeys(getInitialSelectedTargetKeys(initialResult));
    setHasValidatedCachedResult(!initialResult);
  }, [initialContentHash, initialResult]);

  useEffect(() => {
    let isMounted = true;

    /**
     * Loads the schema metadata that drives labels, URLs, and state flags in the modal.
     */
    const loadSchema = async () => {
      try {
        const { response, payload } = await fetchJson(schemaUrl, {
          headers: getSchemaHeaders(),
        });
        if (!response.ok) {
          throw new Error(getResponseErrorMessage(payload, defaultSchemaConfig.messages.checkFailure));
        }
        if (!isMounted) {
          return;
        }
        setSchemaConfig(mergeSchemaConfig(payload));
        setSchemaError('');
      } catch (error) {
        if (!isMounted) {
          return;
        }
        const message = error?.message || defaultSchemaConfig.messages.checkFailure;
        setSchemaError(message);
        actions.toasts.error(message);
      } finally {
        if (isMounted) {
          setIsLoadingSchema(false);
        }
      }
    };

    loadSchema();

    return () => {
      isMounted = false;
    };
  }, [actions, schemaUrl]);

  useEffect(() => {
    if (isLoadingSchema || schemaError) {
      return;
    }
    if (!initialResult) {
      setHasValidatedCachedResult(true);
      return;
    }

    const currentContentHash = schemaConfig.state?.contentHash || defaultSchemaConfig.state.contentHash;
    const hasMatchingCachedContentHash = initialContentHash !== ''
      && initialContentHash === currentContentHash;

    if (!hasMatchingCachedContentHash) {
      clearResult();
      if (typeof onStaleResult === 'function') {
        onStaleResult();
      }
    }

    setHasValidatedCachedResult(true);
  }, [
    clearResult,
    initialContentHash,
    initialResult,
    isLoadingSchema,
    onStaleResult,
    schemaConfig.state?.contentHash,
    schemaError,
  ]);

  /**
   * Closes the modal and notifies the parent action button when teardown is complete.
   */
  const handleClosed = useCallback(() => {
    setIsOpen(false);
    if (typeof onClosed === 'function') {
      onClosed();
    }
  }, [onClosed]);

  /**
   * Runs a draft Refine check and replaces the modal result state with the response.
   */
  const handleCheck = useCallback(async () => {
    setIsChecking(true);
    try {
      const { response, payload } = await fetchJson(schemaConfig.actions?.checkUrl || defaultCheckUrl, {
        method: 'POST',
        headers: getCheckHeaders(),
      });
      if (!response.ok) {
        const message = getResponseErrorMessage(payload, schemaConfig.messages.checkFailure);
        if (message === schemaConfig.messages.noContent || response.status === 400) {
          actions.toasts.warning(message);
        } else {
          actions.toasts.error(message);
        }
        return;
      }

      const nextResult = buildCheckResult(payload);
      setResult(nextResult);
      setSelectedTargetKeys(getInitialSelectedTargetKeys(nextResult));
      setHasValidatedCachedResult(true);
      if (typeof onResultChange === 'function') {
        onResultChange(nextResult, schemaConfig.state?.contentHash || defaultSchemaConfig.state.contentHash);
      }
      actions.toasts.success(schemaConfig.messages.checkSuccess);
    } catch (error) {
      actions.toasts.error(error?.message || schemaConfig.messages.checkFailure);
    } finally {
      setIsChecking(false);
    }
  }, [actions, defaultCheckUrl, onResultChange, schemaConfig]);

  const suggestions = useMemo(() => getResultSuggestions(result), [result]);
  const actionableSuggestions = useMemo(
    () => suggestions.filter((suggestion) => suggestionHasRecommendedChange(suggestion)),
    [suggestions]
  );
  const hasSelectableSuggestions = actionableSuggestions.length > 0;
  const selectedSuggestionKeys = useMemo(() => new Set(selectedTargetKeys), [selectedTargetKeys]);
  const selectedSuggestions = useMemo(() => (
    actionableSuggestions.filter(({ targetKey }) => selectedSuggestionKeys.has(targetKey))
  ), [actionableSuggestions, selectedSuggestionKeys]);
  const hasSelectedSuggestions = selectedSuggestions.length > 0;
  const showAllAlignedMessage = result?.rating === 'Excellent' && !hasSelectableSuggestions;
  const showNoSuggestionsMessage = !showAllAlignedMessage && !hasSelectableSuggestions;
  const displayRating = useMemo(
    () => result?.ratingLabel || getRatingLabel(result?.rating, schemaConfig),
    [result?.rating, result?.ratingLabel, schemaConfig]
  );

  /**
   * Toggles one suggestion in the apply selection list.
   */
  const handleToggleSuggestion = useCallback((targetKey) => {
    setSelectedTargetKeys((currentKeys) => {
      if (currentKeys.includes(targetKey)) {
        return currentKeys.filter((currentKey) => currentKey !== targetKey);
      }

      return [...currentKeys, targetKey];
    });
  }, []);

  /**
   * Applies the selected suggestions back to draft content and handles reload toasts.
   */
  const handleApply = useCallback(async () => {
    if (!hasSelectedSuggestions) {
      return;
    }

    try {
      setIsApplying(true);

      const { response, payload } = await fetchJson(schemaConfig.actions?.applyUrl || defaultApplyUrl, {
        method: 'POST',
        headers: getApplyHeaders(),
        body: JSON.stringify(buildApplyRequestBody(selectedSuggestions)),
      });
      if (!response.ok) {
        actions.toasts.error(getResponseErrorMessage(payload, schemaConfig.messages.applyFailure));
        return;
      }

      if ((payload.appliedCount || 0) > 0) {
        const applyToast = (payload.skippedCount || 0) > 0
          ? { type: 'warning', message: schemaConfig.messages.applyPartial }
          : { type: 'success', message: schemaConfig.messages.applySuccess };

        if (payload.reloadRequired) {
          reloadWithPendingToast(actions.toasts, applyToast);
        } else {
          showToast(actions.toasts, applyToast);
        }

        return;
      }

      if ((payload.skippedCount || 0) > 0) {
        actions.toasts.warning(schemaConfig.messages.applyPartial);
        return;
      }

      actions.toasts.warning(schemaConfig.messages.noSuggestions);
    } catch (error) {
      actions.toasts.error(error?.message || schemaConfig.messages.applyFailure);
    } finally {
      setIsApplying(false);
    }
  }, [actions, defaultApplyUrl, hasSelectedSuggestions, schemaConfig, selectedSuggestions]);

  const refineConfigured = schemaConfig.state?.refineConfigured ?? true;
  const supportsApply = schemaConfig.state?.supportsApply ?? defaultSchemaConfig.state.supportsApply;
  const showResult = !isChecking && hasValidatedCachedResult && !!result;
  const showApplyAction = supportsApply && !isLoadingSchema && !schemaError && showResult && hasSelectableSuggestions;
  const actionsDisabled = isChecking
    || isApplying
    || isLoadingSchema
    || !!schemaError
    || !refineConfigured
    || isFormDirty;
  const closeButton = (
    <button
      type="button"
      className="btn btn-close btn--icon-xl btn--no-text modal__close-button ai-refine-modal__close"
      aria-label="Close"
      title="Close"
      onClick={handleClosed}
    >
      <span aria-hidden="true" className="font-icon-cancel btn__icon" />
    </button>
  );

  return (
    <Modal
      isOpen={isOpen}
      toggle={handleClosed}
      size={modalConfig.size}
      className={modalConfig.className}
      modalClassName={modalConfig.modalClassName}
    >
      <ModalHeader close={closeButton}>{schemaConfig.title}</ModalHeader>
      <ModalBody>
        {schemaError ? (
          <div className="ai-refine-modal__banner ai-refine-modal__banner--error">
            {schemaError}
          </div>
        ) : null}

        {!schemaError ? (
          <>
            {!refineConfigured ? (
              <div className="ai-refine-modal__banner ai-refine-modal__banner--warning">
                {schemaConfig.messages.missingRefine}
              </div>
            ) : null}

            {isFormDirty ? (
              <div className="ai-refine-modal__banner ai-refine-modal__banner--warning">
                {schemaConfig.messages.draftNotice}
              </div>
            ) : null}

            <div className="ai-refine-modal__actions">
              <Button
                color="info"
                type="button"
                onClick={handleCheck}
                disabled={actionsDisabled}
              >
                {getCheckButtonLabel(result, schemaConfig)}
              </Button>
            </div>

            {isLoadingSchema || isChecking || isApplying ? (
              <div className="ai-refine-modal__loading" role="status">
                <Spinner size="sm" />
                <span>{isApplying ? 'Applying suggestions...' : 'Loading...'}</span>
              </div>
            ) : null}

            {!isLoadingSchema && !showResult ? (
              <p className="ai-refine-modal__empty-state">{schemaConfig.messages.emptyState}</p>
            ) : null}

            {showResult ? (
              <div className="ai-refine-modal__result">
                <p className="ai-refine-modal__rating-value">{displayRating}</p>
                <p className="ai-refine-modal__reasoning">{result.reasoningSummary}</p>

                {showAllAlignedMessage ? (
                  <div className="ai-refine-modal__banner ai-refine-modal__banner--success">
                    {schemaConfig.messages.allAligned}
                  </div>
                ) : (
                  <section className="ai-refine-modal__section">
                    <h4 className="ai-refine-modal__section-heading">{schemaConfig.labels.rewrite}</h4>
                    <p className="ai-refine-modal__review-notice">{schemaConfig.messages.reviewNotice}</p>

                    {showNoSuggestionsMessage ? (
                      <p className="ai-refine-modal__empty-state">{schemaConfig.messages.noSuggestions}</p>
                    ) : (
                      <div className="ai-refine-modal__suggestions">
                        {actionableSuggestions.map((suggestion, index) => {
                          const suggestionHeading = getSuggestionHeading(suggestion, index);
                          const isSelected = selectedSuggestionKeys.has(suggestion.targetKey);
                          const checkboxId = `ai-refine-suggestion-${(suggestion.targetKey || `${index}`)
                            .replace(/[^a-zA-Z0-9_-]+/g, '-')}`;

                          return (
                            <article
                              key={suggestion.targetKey || `${suggestionHeading}-${index}`}
                              className="ai-refine-modal__suggestion"
                            >
                              <div className="ai-refine-modal__suggestion-header">
                                <div className="ai-refine-modal__suggestion-heading">
                                  <h5>{suggestionHeading}</h5>
                                </div>

                                {supportsApply ? (
                                  <div className="ai-refine-modal__suggestion-toggle">
                                    <input
                                      id={checkboxId}
                                      type="checkbox"
                                      checked={isSelected}
                                      disabled={actionsDisabled}
                                      onChange={() => handleToggleSuggestion(suggestion.targetKey)}
                                      aria-label={`Apply ${suggestionHeading}`}
                                    />
                                    <label htmlFor={checkboxId}>{schemaConfig.labels.applySuggestion}</label>
                                  </div>
                                ) : null}
                              </div>

                              {/* Server-side HtmlDiff output is rendered here to match Silverstripe's history diff UI. */}
                              <div
                                aria-label={`Draft diff: ${suggestionHeading}`}
                                className="ai-refine-modal__suggestion-diff"
                                dangerouslySetInnerHTML={{ __html: suggestion.diffHtml || '' }}
                              />
                            </article>
                          );
                        })}
                      </div>
                    )}
                  </section>
                )}
              </div>
            ) : null}

            {showApplyAction ? (
              <div className="ai-refine-modal__footer-actions">
                <Button
                  color="info"
                  type="button"
                  onClick={handleApply}
                  disabled={actionsDisabled || !hasSelectedSuggestions}
                >
                  {schemaConfig.labels.apply}
                </Button>
              </div>
            ) : null}
          </>
        ) : null}
      </ModalBody>
    </Modal>
  );
};

AiRefineModal.propTypes = {
  fqcn: PropTypes.string.isRequired,
  recordId: PropTypes.number.isRequired,
  initialResult: PropTypes.shape({
    rating: PropTypes.string,
    ratingLabel: PropTypes.string,
    reasoningSummary: PropTypes.string,
    suggestions: PropTypes.arrayOf(PropTypes.shape({
      fieldLabel: PropTypes.string,
      fieldName: PropTypes.string,
      sourceContent: PropTypes.string,
      suggestedContent: PropTypes.string,
      contentFormat: PropTypes.string,
      diffHtml: PropTypes.string,
      targetId: PropTypes.number,
      targetKey: PropTypes.string,
      targetTitle: PropTypes.string,
      targetType: PropTypes.string,
    })),
  }),
  initialContentHash: PropTypes.string,
  isFormDirty: PropTypes.bool,
  onClosed: PropTypes.func,
  onResultChange: PropTypes.func,
  onStaleResult: PropTypes.func,
  actions: PropTypes.shape({
    toasts: PropTypes.shape({
      error: PropTypes.func.isRequired,
      success: PropTypes.func.isRequired,
      warning: PropTypes.func.isRequired,
    }).isRequired,
  }).isRequired,
};

/**
 * Wires CMS toast actions into the modal component props.
 */
const mapDispatchToProps = (dispatch) => ({
  actions: {
    toasts: bindActionCreators(toastsActions, dispatch),
  },
});

export default connect(null, mapDispatchToProps)(AiRefineModal);

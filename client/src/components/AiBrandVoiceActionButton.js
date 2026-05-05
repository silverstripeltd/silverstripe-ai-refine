import React from 'react';
import PropTypes from 'prop-types';
import Button from 'components/Button/Button';

/**
 * Renders the CMS toolbar button that opens the Brand Voice modal for a record.
 */
export const AiBrandVoiceActionButton = ({
  fqcn,
  recordId,
  title = 'Brand Voice',
  tooltip = 'Check Brand Voice',
}) => (
  <Button
    type="button"
    color="secondary"
    className="ai-brand-voice__action ai-brand-voice-toolbar__button"
    icon="comment"
    title={tooltip}
    data-fqcn={fqcn}
    data-record-id={recordId}
  >
    {title}
  </Button>
);

AiBrandVoiceActionButton.propTypes = {
  fqcn: PropTypes.string.isRequired,
  recordId: PropTypes.number.isRequired,
  title: PropTypes.string,
  tooltip: PropTypes.string,
};

export default AiBrandVoiceActionButton;

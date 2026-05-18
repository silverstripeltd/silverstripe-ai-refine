import React from 'react';
import PropTypes from 'prop-types';
import Button from 'components/Button/Button';

/**
 * Renders the CMS toolbar button that opens the Refine modal for a record.
 */
export const AiRefineActionButton = ({
  fqcn,
  recordId,
  title = 'Refine',
  tooltip = 'Refine',
}) => (
  <Button
    type="button"
    color="secondary"
    className="ai-refine__action ai-refine-toolbar__button"
    icon="comment"
    title={tooltip}
    data-fqcn={fqcn}
    data-record-id={recordId}
  >
    {title}
  </Button>
);

AiRefineActionButton.propTypes = {
  fqcn: PropTypes.string.isRequired,
  recordId: PropTypes.number.isRequired,
  title: PropTypes.string,
  tooltip: PropTypes.string,
};

export default AiRefineActionButton;

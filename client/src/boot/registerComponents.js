/* eslint-disable */
import Injector from 'lib/Injector';
import AiRefineActionButton from 'components/AiRefineActionButton';
import AiRefineModal from 'components/AiRefineModal';

/**
 * Registers the Refine React components with the Silverstripe injector.
 */
const registerComponents = () => {
  Injector.component.register('AiRefineActionButton', AiRefineActionButton);
  Injector.component.register('AiRefineModal', AiRefineModal);
};

export default registerComponents;

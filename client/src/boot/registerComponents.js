/* eslint-disable */
import Injector from 'lib/Injector';
import AiBrandVoiceActionButton from 'components/AiBrandVoiceActionButton';
import AiBrandVoiceModal from 'components/AiBrandVoiceModal';

/**
 * Registers the Brand Voice React components with the Silverstripe injector.
 */
const registerComponents = () => {
  Injector.component.register('AiBrandVoiceActionButton', AiBrandVoiceActionButton);
  Injector.component.register('AiBrandVoiceModal', AiBrandVoiceModal);
};

export default registerComponents;

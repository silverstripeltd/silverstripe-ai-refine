/* global document */
/* eslint-disable */
import registerComponents from './registerComponents';

/**
 * Boots the Brand Voice injector registrations once the CMS shell is ready.
 */
const bootBrandVoiceUi = () => {
  registerComponents();
};

document.addEventListener('DOMContentLoaded', bootBrandVoiceUi);

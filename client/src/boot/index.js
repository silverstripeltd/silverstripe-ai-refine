/* global document */
/* eslint-disable */
import registerComponents from './registerComponents';

/**
 * Boots the Refine injector registrations once the CMS shell is ready.
 */
const bootRefineUi = () => {
  registerComponents();
};

document.addEventListener('DOMContentLoaded', bootRefineUi);

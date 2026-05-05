/* eslint-env jest */
/* eslint-disable import/first */
jest.mock('lib/Injector', () => ({
  __esModule: true,
  default: {
    component: {
      register: jest.fn(),
    },
  },
}), { virtual: true });

jest.mock('components/AiBrandVoiceActionButton', () => 'AiBrandVoiceActionButton', { virtual: true });
jest.mock('components/AiBrandVoiceModal', () => 'AiBrandVoiceModal', { virtual: true });

import Injector from 'lib/Injector';
import registerComponents from '../../src/boot/registerComponents';

test('registerComponents registers the toolbar button and modal components', () => {
  registerComponents();

  expect(Injector.component.register).toHaveBeenNthCalledWith(1, 'AiBrandVoiceActionButton', 'AiBrandVoiceActionButton');
  expect(Injector.component.register).toHaveBeenNthCalledWith(2, 'AiBrandVoiceModal', 'AiBrandVoiceModal');
});

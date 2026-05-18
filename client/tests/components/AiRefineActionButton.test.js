/* eslint-env jest */
/* eslint-disable import/first */
jest.mock('components/Button/Button', () => {
  const React = jest.requireActual('react');

  return ({ children, className = '', color, icon, ...props }) => React.createElement(
    'button',
    {
      ...props,
      className: `btn ${color ? `btn-${color}` : ''} ${className}`.trim(),
    },
    icon ? React.createElement('span', { className: `btn__icon font-icon-${icon}`, 'aria-hidden': 'true' }) : null,
    children,
  );
}, { virtual: true });

import React from 'react';
import { render, screen } from '@testing-library/react';
import { AiRefineActionButton } from '../../src/components/AiRefineActionButton';

test('renders a share-style secondary toolbar button with refine labelling', () => {
  const { container } = render(
    <AiRefineActionButton
      fqcn={'App\\Page'}
      recordId={42}
    />
  );

  const button = screen.getByRole('button', { name: 'Refine' });

  expect(button.className).toContain('ai-refine__action');
  expect(button.className).toContain('ai-refine-toolbar__button');
  expect(button.className).toContain('btn-secondary');
  expect(button.getAttribute('data-fqcn')).toBe('App\\Page');
  expect(button.getAttribute('data-record-id')).toBe('42');
  expect(button.getAttribute('title')).toBe('Refine');
  expect(container.querySelector('.font-icon-comment')).not.toBeNull();
});

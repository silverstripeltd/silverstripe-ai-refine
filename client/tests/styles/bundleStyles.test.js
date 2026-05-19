/* eslint-env jest */

const fs = require('fs');
const path = require('path');

const readBundleStyles = () => fs.readFileSync(path.resolve(__dirname, '../../src/styles/bundle.scss'), 'utf8');

test('limits the refine modal width to 1280px', () => {
  expect(readBundleStyles()).toMatch(/\.ai-refine-modal\s*\{[\s\S]*?\.modal-dialog\s*\{[\s\S]*?max-width:\s*1280px;/);
});

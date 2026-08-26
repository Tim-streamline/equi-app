import assert from 'node:assert/strict';
import test from 'node:test';

import { buildIntakeSchema, isConfiguredNoneOption } from '../lib/intake/config.ts';

test('builds the app intake schema from synced backend rows', () => {
  const schema = buildIntakeSchema(
    [{ id: 'section-1', key: 'paard', order: 0, title: 'Paard', intro: 'Intro', minutes: 2, icon: 'horse', subtitle: 'Basis' }],
    [{
      id: 'field-1',
      section_id: 'section-1',
      key: 'geslacht',
      order: 0,
      label: 'Geslacht',
      type: 'radio',
      required: 1,
      optional: 0,
      active: 1,
      options: '["merrie","ruin","hengst"]',
      show_if: null,
    }],
  );

  assert.equal(schema[0].id, 'paard');
  assert.equal(schema[0].fields[0].id, 'geslacht');
  assert.equal(schema[0].fields[0].required, true);
  assert.deepEqual(schema[0].fields[0].options, ['merrie', 'ruin', 'hengst']);
});

test('matches backend-configured none options case-insensitively', () => {
  const configured = ['geen van toepassing'];

  assert.equal(isConfiguredNoneOption('Geen van toepassing', configured), true);
  assert.equal(isConfiguredNoneOption('geen', configured), false);
});

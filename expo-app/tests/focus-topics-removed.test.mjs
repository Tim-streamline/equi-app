import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import test from 'node:test';

const projectRoot = new URL('../', import.meta.url);

function source(path) {
  return readFileSync(new URL(path, projectRoot), 'utf8');
}

test('removes focus topics from mobile routes, storage and horse screens', () => {
  assert.equal(existsSync(new URL('app/onboarding/focus.tsx', projectRoot)), false);

  for (const path of [
    'app/onboarding/add-horse.tsx',
    'app/(tabs)/account/horse-profile.tsx',
    'app/(tabs)/account/my-horses.tsx',
    'db/hooks.ts',
    'db/powersync-schema.ts',
  ]) {
    assert.doesNotMatch(source(path), /focus_topics|horse_focus|useFocusTopics|useFocusForHorse|HorseFocus/);
  }
});

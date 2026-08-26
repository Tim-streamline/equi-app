import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const files = [
  'app/(tabs)/(pager)/home.tsx',
  'app/(tabs)/(pager)/protocol.tsx',
  'db/hooks.ts',
  'db/powersync-schema.ts',
];

test('the mobile app no longer exposes the removed protocol task contract', async () => {
  const sources = await Promise.all(files.map((file) => readFile(new URL(`../${file}`, import.meta.url), 'utf8')));
  const combined = sources.join('\n');

  for (const removed of [
    'protocol_tasks',
    'protocol_task_completions',
    'useTodayTasks',
    'useProtocolTasks',
    'useAllTaskCompletions',
    'toggleTaskCompletion',
    'TaskSection',
    'Los geven',
  ]) {
    assert.doesNotMatch(combined, new RegExp(removed));
  }
});

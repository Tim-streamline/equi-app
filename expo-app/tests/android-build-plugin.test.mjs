import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';

const require = createRequire(import.meta.url);
const { addFreshUpdatesManifest } = require('../plugins/withCustomAndroidBuild.js');

test('release builds always regenerate the expo-updates asset manifest', () => {
  const once = addFreshUpdatesManifest('android {}\n');
  assert.match(once, /it\.name\.endsWith\('UpdatesResources'\)[\s\S]*outputs\.upToDateWhen \{ false \}/);
  assert.equal(addFreshUpdatesManifest(once), once, 'adding it twice changes nothing');
  // Older generated android/ dirs get it from release.sh, which never runs prebuild.
  assert.match(readFileSync(new URL('../release.sh', import.meta.url), 'utf8'), /addFreshUpdatesManifest[\s\S]*gradlew assembleRelease/);
});

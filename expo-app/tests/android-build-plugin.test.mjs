import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';

const require = createRequire(import.meta.url);
const withCustomAndroidBuild = require('../plugins/withCustomAndroidBuild.js');
const release = readFileSync(new URL('../release.sh', import.meta.url), 'utf8');

// Run the plugin's app/build.gradle mod the way prebuild does.
async function appBuildGradle(contents) {
  const config = withCustomAndroidBuild({ name: 'app', slug: 'app' });
  const result = await config.mods.android.appBuildGradle({
    ...config,
    modRequest: { platform: 'android', modName: 'appBuildGradle', projectRoot: process.cwd(), platformProjectRoot: 'android', introspect: false },
    modResults: { contents, language: 'groovy' },
  });
  return result.modResults.contents;
}

test('release builds always regenerate the expo-updates asset manifest', async () => {
  const once = await appBuildGradle('android {}\n');
  assert.match(once, /it\.name\.endsWith\('UpdatesResources'\)[\s\S]*outputs\.upToDateWhen \{ false \}/);
  assert.equal(await appBuildGradle(once), once, 'running prebuild again changes nothing');
  // release.sh re-applies the plugins to an existing android/ before building.
  assert.match(release, /expo prebuild --platform android --no-install[\s\S]*gradlew assembleRelease/);
});

test('the version bump raises app.json, which prebuild copies into gradle, or fails loudly', () => {
  const script = release.slice(release.indexOf('  NEXT_CODE=$(node -e'), release.indexOf('  # Keep a direct gradle build'));
  const dir = mkdtempSync(join(tmpdir(), 'bump-'));
  const bump = (json) => {
    writeFileSync(join(dir, 'app.json'), json);
    return execFileSync('bash', ['-c', `set -euo pipefail\nAPP_JSON=app.json NEXT=0.0.9\n${script}\necho "$NEXT_CODE"`], { cwd: dir, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim();
  };
  // Compact formatting still works, and nothing else in the file changes.
  assert.equal(bump('{"expo":{"name":"x","version":"0.0.8","android":{"versionCode":12}}}'), '13');
  assert.equal(readFileSync(join(dir, 'app.json'), 'utf8'), '{"expo":{"name":"x","version":"0.0.9","android":{"versionCode":13}}}');
  assert.throws(() => bump('{"expo":{"version":"0.0.8","android":{}}}'), 'no versionCode to bump');
});

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import ts from 'typescript';
import { errorTrackingOptions, sanitizeErrorEvent } from '../lib/error-tracking-options.ts';

test('outgoing events retain errors and release tags without account or request data', () => {
  const event = {
    event_id: 'test-id', release: 'release-1', environment: 'staging',
    user: { id: '42', email: 'person@example.test', ip_address: '127.0.0.1' },
    request: { method: 'POST', url: 'https://example.test/web-session/password/reset/reset-secret?email=person@example.test#token', headers: { Authorization: 'Bearer secret' }, data: { password: 'secret' }, cookies: 'secret', env: { secret: 'hidden' } },
    exception: { values: [{ type: 'Error', value: 'person@example.test password=secret Bearer secret', stacktrace: { frames: [{ filename: 'https://example.test/app.js?token=secret', function: 'login', vars: { password: 'secret' } }] } }] },
    extra: { horse: { name: 'Private' } }, contexts: { state: { email: 'person@example.test' } }, breadcrumbs: [{ message: 'secret' }], tags: { component: 'web' },
  };
  const clean = sanitizeErrorEvent(event);
  assert.deepEqual(clean.request, { method: 'POST', url: 'https://example.test/web-session/password/reset/[redacted]' });
  assert.equal(clean.release, 'release-1');
  assert.equal(clean.exception.values[0].stacktrace.frames[0].function, 'login');
  assert.equal(clean.exception.values[0].stacktrace.frames[0].filename, 'https://example.test/app.js');
  assert.deepEqual(clean.tags, { component: 'web' });
  for (const value of ['person@example.test', 'secret', 'Private', '127.0.0.1']) assert.ok(!JSON.stringify(clean).includes(value), value);
  assert.equal(event.user.id, '42', 'sanitizing must not mutate the original error');
});

test('SDK options disable tracking when requested and keep analytics and tracing off', () => {
  assert.equal(errorTrackingOptions('', 'production').enabled, false);
  assert.equal(errorTrackingOptions('https://key@example.test/1', 'development', undefined, false).enabled, false);
  const options = errorTrackingOptions('https://key@example.test/1', 'staging', 'build-1');
  assert.equal(options.enabled, true);
  assert.equal(options.sampleRate, 1);
  assert.equal(options.tracesSampleRate, 0);
  assert.equal(options.sendDefaultPii, false);
  assert.equal(options.enableLogs, false);
});

test('native initializer enables native crashes and distinguishes OTA updates', async () => {
  const code = ts.transpileModule(await readFile(new URL('../lib/error-tracking.ts', import.meta.url), 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText;
  const calls = [], tags = [];
  const sentry = { init: options => calls.push(options), setTag: (...tag) => tags.push(tag), wrap: () => {}, captureException: () => {} };
  const modules = { '@sentry/react-native': sentry, 'expo-updates': { updateId: 'ota-id', runtimeVersion: 'runtime-1', channel: 'preview' }, './error-tracking-options': { errorTrackingOptions, BETTER_STACK_ANDROID_DSN: 'https://native-key@example.test/2' } };
  new Function('require', 'exports', '__DEV__', 'process', code)(name => modules[name], {}, false, { env: { EXPO_PUBLIC_BETTER_STACK_ENVIRONMENT: 'staging' } });
  assert.equal(calls[0].enableNativeCrashHandling, true);
  assert.equal(calls[0].environment, 'staging');
  assert.equal(calls[0].replaysOnErrorSampleRate, 0);
  assert.ok(tags.some(([key, value]) => key === 'expo-update-id' && value === 'ota-id'));
});

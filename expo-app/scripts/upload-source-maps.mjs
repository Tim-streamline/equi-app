import { spawnSync } from 'node:child_process';

if (!process.env.SENTRY_AUTH_TOKEN) {
  console.warn('Better Stack: OTA source-map upload skipped; set the private SENTRY_AUTH_TOKEN build secret.');
} else {
  const result = spawnSync(process.execPath, ['node_modules/@sentry/react-native/scripts/expo-upload-sourcemaps.js', 'dist'], {
    stdio: 'inherit',
    env: {
      ...process.env,
      SENTRY_URL: 'https://us-west-2a-sourcemaps.betterstackdata.com/',
      SENTRY_ORG: '607744',
      SENTRY_PROJECT: '2781655',
    },
  });
  process.exitCode = result.status ?? 1;
}

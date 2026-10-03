import { cp, mkdir, readdir, rm } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { SentryCli } from '@sentry/cli';

const root = fileURLToPath(new URL('../', import.meta.url));
const assets = path.join(root, 'dist/_expo/static/js/web');
const archive = path.join(root, '.source-maps');
const cli = new SentryCli(null, {
  url: 'https://us-west-2a-sourcemaps.betterstackdata.com/',
  org: '607744',
  project: '2781654',
  authToken: process.env.SENTRY_AUTH_TOKEN,
});
await cli.execute(['sourcemaps', 'inject', assets], true);
await mkdir(archive, { recursive: true });
// Keep the exact bundle and map together for a later private upload if needed.
for (const name of await readdir(assets)) {
  if (/\.(js|map)$/.test(name)) await cp(path.join(assets, name), path.join(archive, name));
}
try {
  if (process.env.SENTRY_AUTH_TOKEN) {
    await cli.execute(['sourcemaps', 'upload', '--url-prefix', '~/_expo/static/js/web', assets], true);
  } else {
    console.warn('Better Stack: source-map upload skipped; set SENTRY_AUTH_TOKEN. Maps saved in web/.source-maps.');
  }
} finally {
  // Include vendor worker maps, and clean up even when the upload fails.
  await removeMaps(path.join(root, 'dist'));
}

async function removeMaps(directory) {
  for (const entry of await readdir(directory, { withFileTypes: true })) {
    const file = path.join(directory, entry.name);
    if (entry.isDirectory()) await removeMaps(file);
    else if (entry.name.endsWith('.map')) await rm(file);
  }
}

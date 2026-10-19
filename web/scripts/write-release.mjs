import { randomUUID } from 'node:crypto';
import { readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export async function writeRelease(directory, buildId = randomUUID()) {
  if (!/^[a-zA-Z0-9-]{1,80}$/.test(buildId)) throw new Error('Invalid web build ID');
  const file = path.join(directory, 'index.html');
  const html = (await readFile(file, 'utf8')).replace(/<meta name="equi-build" content="[^"]*"\s*\/?>\s*/g, '');
  if (!html.includes('</head>')) throw new Error('Web export has no HTML head');
  await writeFile(file, html.replace('</head>', `<meta name="equi-build" content="${buildId}" /></head>`));
  await writeFile(path.join(directory, 'web-version.json'), JSON.stringify({ buildId }) + '\n');
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  await writeRelease(fileURLToPath(new URL('../dist/', import.meta.url)));
}

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, mkdir, writeFile, readFile, stat, rm, readdir } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { prepareRoutes } from '../scripts/prepare-routes.mjs';

test('regeneration preserves live routes and only removes obsolete generated wrappers', async t => {
  const fixture = await mkdtemp(path.join(os.tmpdir(), 'equi-routes-'));
  t.after(() => rm(fixture, { recursive: true, force: true }));
  const root = path.join(fixture, 'web');
  const source = path.join(fixture, 'expo-app/app');
  const target = path.join(root, 'app');
  await mkdir(path.join(source, 'intake'), { recursive: true });
  for (const route of ['index.tsx', '_layout.tsx', 'intake/old.tsx']) {
    await writeFile(path.join(source, route), 'export default function Route() {}');
  }
  await prepareRoutes({ source, target, root });
  const index = path.join(target, 'index.tsx');
  const before = await stat(index, { bigint: true });
  const directoryBefore = await stat(target, { bigint: true });
  assert.match(await readFile(index, 'utf8'), /"\.\.\/\.\.\/expo-app\/app\/index"/);
  assert.match(await readFile(path.join(target, '_layout.tsx'), 'utf8'), /"\.\.\/components\/RootLayout"/);

  await prepareRoutes({ source, target, root });
  const after = await stat(index, { bigint: true });
  assert.equal(after.ino, before.ino, 'a live route must never be deleted and recreated');
  assert.equal(after.mtimeNs, before.mtimeNs, 'unchanged routes must not trigger Metro updates');
  assert.equal((await stat(target, { bigint: true })).mtimeNs, directoryBefore.mtimeNs);

  await writeFile(path.join(target, 'manual.tsx'), '// Not generated');
  await rm(path.join(source, 'intake/old.tsx'));
  await writeFile(path.join(source, 'intake/new.tsx'), 'export default function Route() {}');
  await prepareRoutes({ source, target, root });
  assert.deepEqual(await readdir(path.join(target, 'intake')), ['new.tsx']);
  assert.equal(await readFile(path.join(target, 'manual.tsx'), 'utf8'), '// Not generated');
  assert.equal((await stat(index, { bigint: true })).mtimeNs, before.mtimeNs);

  await writeFile(index, '// Stale wrapper contents');
  await prepareRoutes({ source, target, root });
  assert.match(await readFile(index, 'utf8'), /export \{ default \}/);
  assert.deepEqual((await readdir(target)).filter(name => name.endsWith('.tmp')), []);
});

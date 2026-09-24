import { cp, mkdir } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
const target = new URL('../public/powersync/', import.meta.url);
await mkdir(target, { recursive: true });
await cp(fileURLToPath(new URL('../node_modules/@powersync/web/dist/', import.meta.url)), fileURLToPath(target), { recursive: true });

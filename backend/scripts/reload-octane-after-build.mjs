import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { resolve } from 'node:path';

export function resolveReloadCommand(environment = process.env) {
    if (environment.LARAVEL_SAIL === '1') {
        return {
            command: 'php',
            args: ['artisan', 'octane:reload'],
        };
    }

    return {
        command: './vendor/bin/sail',
        args: ['artisan', 'octane:reload'],
    };
}

export function reloadOctane(environment = process.env) {
    const { command, args } = resolveReloadCommand(environment);
    const result = spawnSync(command, args, { stdio: 'inherit' });

    if (result.error) {
        throw result.error;
    }

    return result.status ?? 1;
}

const isEntryPoint = process.argv[1]
    && fileURLToPath(import.meta.url) === resolve(process.argv[1]);

if (isEntryPoint) {
    process.exitCode = reloadOctane();
}

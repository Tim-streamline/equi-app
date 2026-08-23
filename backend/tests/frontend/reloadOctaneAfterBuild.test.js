import assert from 'node:assert/strict';
import test from 'node:test';

import { resolveReloadCommand } from '../../scripts/reload-octane-after-build.mjs';

test('host builds reload Octane through Sail', () => {
    assert.deepEqual(resolveReloadCommand({}), {
        command: './vendor/bin/sail',
        args: ['artisan', 'octane:reload'],
    });
});

test('builds inside Sail reload Octane directly', () => {
    assert.deepEqual(resolveReloadCommand({ LARAVEL_SAIL: '1' }), {
        command: 'php',
        args: ['artisan', 'octane:reload'],
    });
});

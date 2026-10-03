import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { svelte } from '@sveltejs/vite-plugin-svelte';
import tailwindcss from '@tailwindcss/vite';
import path from 'node:path';
import { sentryVitePlugin } from '@sentry/vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        svelte(),
        tailwindcss(),
        sentryVitePlugin({
            url: 'https://us-west-2a-sourcemaps.betterstackdata.com/',
            org: '607744',
            project: '2781654',
            authToken: process.env.SENTRY_AUTH_TOKEN,
            telemetry: false,
            release: { name: process.env.VITE_BETTER_STACK_RELEASE, create: false, finalize: false },
            sourcemaps: { filesToDeleteAfterUpload: ['public/build/**/*.map'] },
        }),
    ],
    resolve: {
        alias: {
            $lib: path.resolve(__dirname, 'resources/js/lib'),
        },
    },
    build: {
        sourcemap: 'hidden',
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});

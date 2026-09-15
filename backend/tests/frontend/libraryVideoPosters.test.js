import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';
import test from 'node:test';

const source = await readFile(new URL('../../resources/js/lib/components/LibraryItemPreviewModal.svelte', import.meta.url), 'utf8');
const script = source.match(/<script>([\s\S]*?)<\/script>/)[1].replace(/^\s*import .*;$/gm, '');
const posterExpression = source.match(/<VideoJsPlayer[^>]*poster=\{([^}]+)\}/)[1];

function preview(props) {
    const context = vm.createContext({
        URL, $props: () => props, $state: (value) => value, $derived: (value) => value,
        $effect: () => {}, onDestroy: () => {},
    });
    vm.runInContext(script, context);
    return JSON.parse(vm.runInContext(`JSON.stringify(blocks.filter(block => block.type === 'video').map(block => ({ url: block.url, poster: ${posterExpression} })))`, context));
}

test('each embedded video uses its own frame, independently of the manual item cover and body order', () => {
    const first = 'https://media.test/first.mp4';
    const second = 'https://media.test/second.mp4';
    const props = {
        heroImageUrl: 'https://media.test/manual-cover.jpg',
        body: `<video src="${second}"></video>\n<video src="${first}"></video>`,
        videoPosters: { [first]: 'https://media.test/first.jpg', [second]: 'https://media.test/second.jpg' },
    };
    assert.deepEqual(preview(props), [
        { url: second, poster: props.videoPosters[second] },
        { url: first, poster: props.videoPosters[first] },
    ]);
    props.heroImageUrl = 'https://media.test/replacement-cover.jpg';
    assert.equal(preview(props)[0].poster, props.videoPosters[second]);
});

test('an external or failed-generation video uses its own native frame instead of the item cover', () => {
    assert.deepEqual(preview({ heroImageUrl: 'https://media.test/cover.jpg', body: '<video src="https://external.test/video.mp4"></video>' }), [
        { url: 'https://external.test/video.mp4', poster: '' },
    ]);
});

test('unsaved uploads and media insertion update per-video posters without overwriting a manual cover', async () => {
    const editor = await readFile(new URL('../../resources/js/Pages/Library/Edit.svelte', import.meta.url), 'utf8');
    const editorScript = editor.match(/<script>([\s\S]*?)<\/script>/)[1].replace(/^\s*import .*;$/gm, '');
    const context = vm.createContext({
        $props: () => ({
            item: { id: 'item', format: 'video', thumbnail_mode: 'manual', hero_image_url: 'https://media.test/cover.jpg' },
            categories: [], therapists: [], videoPosters: { 'https://media.test/existing.mp4': 'https://media.test/existing.jpg' },
        }),
        $state: (value) => value, useForm: (value) => { context.$form = value; return value; },
    });
    vm.runInContext(editorScript, context);
    vm.runInContext(`
        uploaded({ id: 'new-video', type: 'video', url: 'https://media.test/new.mp4', thumbnail_url: 'https://media.test/new.jpg' });
        insertMedia({ id: 'new-video', type: 'video', url: 'https://media.test/new.mp4', thumbnail_url: 'https://media.test/new.jpg' });
    `, context);
    const result = JSON.parse(vm.runInContext('JSON.stringify({ posters: previewVideoPosters, body: $form.body, cover: $form.hero_image_url, ids: $form.media_ids })', context));
    assert.deepEqual(result.posters, { 'https://media.test/existing.mp4': 'https://media.test/existing.jpg', 'https://media.test/new.mp4': 'https://media.test/new.jpg' });
    assert.match(result.body, /<video src="https:\/\/media.test\/new.mp4"/);
    assert.equal(result.cover, 'https://media.test/cover.jpg');
    assert.deepEqual(result.ids, ['new-video']);
    assert.match(editor, /videoPosters=\{previewVideoPosters\}/, 'The editor must pass its live video posters to the modal');
});

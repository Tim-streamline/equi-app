import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

function source(path) {
    return readFileSync(new URL(path, import.meta.url), 'utf8');
}

test('admin navigation distinguishes templates from advice settings', () => {
    const navigation = source('../../resources/js/lib/nav.js');

    assert.match(navigation, /label: 'Protocol Templates', href: '\/admin\/protocol-settings'/);
    assert.match(navigation, /label: 'Protocol Settings', href: '\/admin\/protocol-settings\/advice'/);
});

test('protocol configuration uses template terminology', () => {
    const settings = source('../../resources/js/Pages/ProtocolSettings/Index.svelte');

    assert.match(settings, /<AdminLayout title="Protocol Templates">/);
    assert.match(settings, /id="protocol-template-selector"/);
    assert.match(settings, /Field label="Protocol template"/);
    assert.doesNotMatch(settings, /Protocol Settings/);
    assert.doesNotMatch(settings, /protocol type/i);
});

test('protocol template selection uses a top dropdown without a sidebar', () => {
    const settings = source('../../resources/js/Pages/ProtocolSettings/Index.svelte');

    assert.match(settings, /bind:value=\{selectedTemplateId\}/);
    assert.match(settings, /onclick=\{createTemplate\}/);
    assert.doesNotMatch(settings, /<aside/);
    assert.doesNotMatch(settings, /Choose a template to configure/);
});

test('protocol list and editor use protocol template labels', () => {
    const list = source('../../resources/js/Pages/Protocols/Index.svelte');
    const editor = source('../../resources/js/Pages/Protocols/Edit.svelte');

    assert.match(list, /<TableHead>Protocol template<\/TableHead>/);
    assert.match(editor, /Field label="Protocol template"/);
    assert.match(editor, /Configureer ze in Protocol Templates\./);
    assert.doesNotMatch(list, /protocol type/i);
    assert.match(editor, /href="\/admin\/protocol-settings\/advice"[^>]*>Naar Protocol Settings<\/Button>/);
});

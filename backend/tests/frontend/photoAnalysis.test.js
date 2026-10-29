import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compile } from 'svelte/compiler';
import { render } from 'svelte/server';

function compiled(name) {
    const source = readFileSync(new URL(`../../resources/js/Pages/FeedScanner/${name}.svelte`, import.meta.url), 'utf8');
    let code = compile(source, { filename: `${name}.svelte`, generate: 'server' }).js.code;
    code = code.replace(/from (['"])(svelte[^'"]*)\1/g, (_, quote, specifier) => `from ${quote}${import.meta.resolve(specifier)}${quote}`);
    code = code.replace(/from (['"])\.\/SubmissionOcr.svelte\1/g, () => `from '${compiled('SubmissionOcr')}'`);
    return `data:text/javascript;base64,${Buffer.from(code).toString('base64')}`;
}
const { default: Component } = await import(compiled('PhotoAnalysis'));
const view = props => render(Component, { props }).body;

test('both readers and structured nutritional values are visible without expanding raw JSON', () => {
    const html = view({ ocr: { text: 'OCR sugar 42%', confidence: 80 }, luna: { status: 'completed', model: 'gpt-6-luna', prompt_version: 3, prompt: 'Read faithfully', data: {
        raw_text: 'Luna sugar 4.2%', nutrients: [{ code: 'sugar', value: '4.2', unit: '%', qualifier: 'maximum', basis: 'unknown', confidence: 'low', source: 'Suiker max 4,2%' }], unreadable_fields: ['Selenium'],
    } }, nutrientOptions: { sugar: 'Suiker' } });
    assert.ok(html.includes('OCR sugar 42%'));
    assert.ok(html.includes('Luna sugar 4.2%'));
    assert.ok(html.includes('4.2 %'));
    assert.ok(html.includes('Basis onbekend'));
    assert.ok(html.includes('Onzeker'));
    assert.ok(html.includes('promptversie 3'));
    assert.ok(html.indexOf('Luna sugar 4.2%') < html.indexOf('<details'));
});

test('Luna failures remain distinct from unprocessed photos and do not hide successful OCR', () => {
    assert.ok(view({ luna: null }).includes('nog geen Luna-resultaat'));
    const html = view({ ocr: { text: 'Preserved OCR', confidence: 95 }, luna: { status: 'failed', error_code: 'reader_not_configured' } });
    assert.ok(html.includes('Preserved OCR'));
    assert.ok(html.includes('API-sleutel ontbreekt'));
});

test('label text, ingredients, evidence and editable prompts are escaped', () => {
    const attack = '<img src=x onerror=alert(1)>';
    const html = view({ luna: { status: 'completed', prompt: attack, data: { raw_text: attack, ingredients: [{ label: attack, evidence: attack, confidence: 'high' }] } } });
    assert.ok(!html.includes('<img'));
    assert.ok(html.includes('&lt;img'));
});

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compile } from 'svelte/compiler';
import { render } from 'svelte/server';

const source = readFileSync(new URL('../../resources/js/Pages/FeedScanner/SubmissionOcr.svelte', import.meta.url), 'utf8');
const { js } = compile(source, { filename: 'SubmissionOcr.svelte', generate: 'server' });
const code = js.code.replace(/from (['"])(svelte[^'"]*)\1/g, (_, quote, specifier) => `from ${quote}${import.meta.resolve(specifier)}${quote}`);
const { default: Component } = await import(`data:text/javascript;base64,${Buffer.from(code).toString('base64')}`);
const view = props => render(Component, { props }).body;

test('the photo OCR is displayed independently of the recognition snapshot', () => {
    const html = view({ title: 'Samenstelling', ocr: { text: 'Current photo text', confidence: 94.578, words: 3 }, recognition: { decision: 'uncertain', ocr: { text: 'Obsolete text' } } });
    assert.ok(html.includes('Current photo text'));
    assert.ok(!html.includes('Obsolete text'));
    assert.ok(html.includes('94,6 / 100'));
    assert.ok(!html.includes('<details'));
});

test('OCR text is escaped and unavailable, empty and unprocessed photos remain distinct', () => {
    assert.ok(view({ ocr: { text: '<img src=x onerror=alert(1)>', confidence: 0 } }).includes('&lt;img'));
    assert.ok(view({ ocr: { text: 'Label', confidence: 0 } }).includes('0 / 100'));
    assert.ok(view({ ocr: { engine: 'unavailable', text: '' } }).includes('was niet beschikbaar'));
    assert.ok(view({ ocr: { engine: 'test', text: '' } }).includes('geen leesbare tekst'));
    assert.ok(view({ ocr: null }).includes('nog geen OCR-resultaat'));
});

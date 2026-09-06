import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const pageUrl = new URL('../../resources/js/Pages/LibraryCategories/Index.svelte', import.meta.url);

test('Library categories exposes a clear create action and resets stale edit values', async () => {
    const source = await readFile(pageUrl, 'utf8');

    assert.match(source, /Nieuwe categorie/);
    assert.match(source, /aria-label="Nieuwe bibliotheekcategorie toevoegen"/);
    assert.match(source, /function create\(\)[\s\S]*\$form\.defaults\(emptyCategory\(\)\)[\s\S]*\$form\.reset\(\)/);
    assert.doesNotMatch(source, /(?<!\$)form\.(defaults|reset|clearErrors)\(/);
    assert.match(source, /Math\.max\(-1, \.\.\.categories\.map/);
});

test('the create dialog contains every field and posts to the store route', async () => {
    const source = await readFile(pageUrl, 'utf8');

    assert.match(source, /\$form\.post\('\/admin\/library-categories'/);
    for (const field of ['label', 'slug', 'order', 'is_default']) {
        assert.match(source, new RegExp(`\\$form\\.${field}`));
    }
    assert.match(source, /Categorie toevoegen/);
});

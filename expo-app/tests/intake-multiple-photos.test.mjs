import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import ts from 'typescript';
import { RefreshSafety } from '../lib/refresh-safety-core.ts';

const flatten = node => Array.isArray(node) ? node.flatMap(flatten) : node && typeof node === 'object' ? [node, ...flatten(node.props?.children)] : [];
const text = node => Array.isArray(node) ? node.map(text).join(' ') : node && typeof node === 'object' ? text(node.props?.children) : String(node ?? '');
const asset = (name, extra = {}) => ({ uri: `cache/${name}`, fileName: name, mimeType: 'image/jpeg', fileSize: 20, ...extra });
const tick = () => new Promise(resolve => setImmediate(resolve));

function fixture({ assets = [asset('a.jpg'), asset('b.jpg')], max, slots, initial = [], save, pick } = {}) {
  const hooks = []; let hook = 0, value = initial;
  const effects = [], refreshSafety = new RefreshSafety();
  const saved = [], published = [], options = [], assigned = [], session = { revision: 0 };
  const intake = { state: { submittedAt: null }, submitting: false, horseId: 'horse', ensureBooking: async () => 'booking' };
  const jsx = (type, props) => ({ type, props });
  const modules = {
    react: {
      useRef: initial => { const i = hook++; return hooks[i] ??= { current: initial }; },
      useState: initial => { const i = hook++; if (!(i in hooks)) hooks[i] = initial; return [hooks[i], next => { hooks[i] = typeof next === 'function' ? next(hooks[i]) : next; }]; },
      useEffect: (effect, deps) => {
        const i = hook++, previous = hooks[i];
        if (!previous || !deps || deps.some((value, index) => value !== previous.deps?.[index])) effects.push(() => {
          previous?.cleanup?.(); hooks[i] = { deps, cleanup: effect() };
        });
      },
    },
    'react/jsx-runtime': { jsx, jsxs: jsx },
    'react-native': { ...Object.fromEntries(['View', 'Text', 'Pressable', 'Image', 'ActivityIndicator'].map(x => [x, x])), Platform: { OS: 'web' } },
    'lucide-react-native': { Camera: 'Camera', FileText: 'FileText', Trash2: 'Trash2' },
    'expo-image-picker': { launchImageLibraryAsync: async opts => { options.push(opts); return pick ? pick() : { canceled: false, assets }; } },
    'expo-document-picker': {},
    '@/db/auth': {}, '@/db/connector': {}, '@/db/provider': { useDb: () => ({ currentUserId: 'owner' }) },
    '@/lib/account-session': { accountSession: session },
    '@/lib/intake/attachments/storage': { saveAttachment: async record => { if (save) await save(record); saved.push(record); } },
    '@/lib/intake/attachments/events': {}, '@/lib/intake/store': { useIntake: () => intake },
    '@/lib/refresh-safety': { registerRefreshProtection: refreshSafety.register, refreshProtectionChanged: refreshSafety.changed },
  };
  const exports = {};
  const code = ts.transpileModule(readFileSync(new URL('../components/intake/AttachmentField.tsx', import.meta.url), 'utf8'), {
    compilerOptions: { target: ts.ScriptTarget.ES2022, jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS },
  }).outputText;
  new Function('require', 'exports', code)(name => { assert.ok(name in modules, name); return modules[name]; }, exports);
  const props = { photo: true, max, slots, section: 'fysiek', field: 'photos', onSlotAdded: (ref, id) => assigned.push([ref, id]) };
  const draw = () => {
    hook = 0;
    const tree = flatten(exports.AttachmentField({ ...props, value, onChange: next => { value = next; published.push([...next]); } }));
    for (const effect of effects.splice(0)) effect();
    return tree;
  };
  const button = label => draw().find(n => n.type === 'Pressable' && (label ? text(n) === label : n.props.accessibilityRole === 'button'));
  return { draw, button, options, saved, published, assigned, props, session, intake, refreshSafety, value: () => value };
}

test('mandatory refresh waits for picker selection, view assignment and durable photo saving', async () => {
  let finishPicker, finishSave;
  const app = fixture({ slots: [{ id: 'left', label: 'Links', field: 'left' }],
    pick: () => new Promise(resolve => { finishPicker = resolve; }),
    save: () => new Promise(resolve => { finishSave = resolve; }),
  });
  app.button().props.onPress();
  await assert.rejects(app.refreshSafety.prepare(), /kiezen of opslaan/);
  finishPicker({ canceled: false, assets: [asset('hoof.jpg')] }); await tick(); app.draw();
  await assert.rejects(app.refreshSafety.prepare(), /Wijs je gekozen/);
  app.draw().find(n => n.props?.accessibilityRole === 'radio').props.onPress();
  app.button('Foto’s opslaan').props.onPress(); await tick();
  await assert.rejects(app.refreshSafety.prepare(), /kiezen of opslaan/);
  finishSave(); await tick(); app.draw();
  await app.refreshSafety.prepare();
  assert.equal(app.saved.length, 1);
  assert.equal(app.assigned.length, 1);
});

test('one library selection durably saves all photos before publishing each reference', async () => {
  const app = fixture();
  app.button().props.onPress(); await tick();
  assert.equal(app.options[0].allowsMultipleSelection, true);
  assert.equal(app.options[0].selectionLimit, 0);
  assert.equal(app.saved.length, 2);
  assert.equal(app.value().length, 2);
  assert.deepEqual(app.published.map(v => v.length), [1, 2]);
  assert.deepEqual(app.value().map(ref => ref.split(':')[1]), app.saved.map(r => r.id));
  assert.ok(app.saved.every(r => r.userId === 'owner' && r.uploaded === false));
});

test('remaining capacity includes existing photos and enforces web overflow', async () => {
  const app = fixture({ initial: ['attachment:old:old.jpg'], max: 2 });
  app.button().props.onPress(); await tick();
  assert.equal(app.options[0].allowsMultipleSelection, false);
  assert.equal(app.options[0].selectionLimit, 1);
  assert.equal(app.saved.length, 1);
  assert.equal(app.value().length, 2);
  assert.match(text(app.draw()), /maximum aantal bestanden/);
  assert.equal(app.button().props.disabled, true);
});

test('invalid files and storage failures keep successful photos and explain each failure', async () => {
  const app = fixture({ assets: [asset('big.jpg', { fileSize: 16 * 1024 * 1024 }), asset('ok.jpg'), asset('bad.gif', { mimeType: 'image/gif' }), asset('disk.jpg'), asset('last.jpg')],
    save: record => { if (record.name === 'disk.jpg') throw new Error('Geen opslagruimte'); } });
  app.button().props.onPress(); await tick();
  assert.deepEqual(app.saved.map(r => r.name), ['ok.jpg', 'last.jpg']);
  assert.equal(app.value().length, 2);
  assert.match(text(app.draw()), /big.jpg:.*15 MB/);
  assert.match(text(app.draw()), /bad.gif:.*JPG/);
  assert.match(text(app.draw()), /disk.jpg: Geen opslagruimte/);
});

test('cancel, double tap and account changes cannot add unexpected photos', async () => {
  const canceled = fixture({ pick: () => ({ canceled: true, assets: null }) });
  canceled.button().props.onPress(); await tick(); assert.equal(canceled.saved.length, 0);
  let finish;
  const app = fixture({ pick: () => new Promise(resolve => { finish = resolve; }) });
  const button = app.button(); button.props.onPress(); button.props.onPress();
  assert.equal(app.options.length, 1);
  app.session.revision++;
  finish({ canceled: false, assets: [asset('a.jpg')] }); await tick();
  assert.equal(app.saved.length, 0);
  assert.match(text(app.draw()), /sessie is gewijzigd/);
});

test('session change during durable save stops publishing and remaining batch writes', async () => {
  const app = fixture({ save: () => { app.session.revision++; } });
  app.button().props.onPress(); await tick();
  assert.equal(app.saved.length, 1);
  assert.equal(app.value().length, 0);
});

test('named views require explicit unique assignments and save to the chosen answer fields', async () => {
  const slots = [{ id: 'left', label: 'Links', field: 'photo-left' }, { id: 'right', label: 'Rechts', field: 'photo-right' }];
  const app = fixture({ slots });
  app.button().props.onPress(); await tick();
  assert.equal(app.saved.length, 0);
  assert.equal(app.button('Foto’s opslaan').props.disabled, true);
  let radios = app.draw().filter(n => n.props?.accessibilityRole === 'radio');
  radios[1].props.onPress();
  radios = app.draw().filter(n => n.props?.accessibilityRole === 'radio');
  assert.equal(radios[3].props.disabled, true);
  radios[2].props.onPress();
  assert.equal(app.button('Foto’s opslaan').props.disabled, false);
  app.button('Foto’s opslaan').props.onPress(); await tick();
  assert.deepEqual(app.saved.map(r => r.field), ['photo-right', 'photo-left']);
  assert.deepEqual(app.assigned.map(([, id]) => id), ['right', 'left']);
});

test('a view occupied while selecting cannot be overwritten', async () => {
  const app = fixture({ assets: [asset('a.jpg')], slots: [{ id: 'left', label: 'Links', field: 'left' }] });
  app.button().props.onPress(); await tick();
  app.draw().find(n => n.props?.accessibilityRole === 'radio').props.onPress();
  app.props.slots = [];
  const save = app.button('Foto’s opslaan'); assert.equal(save.props.disabled, true);
  save.props.onPress(); await tick();
  assert.equal(app.saved.length, 0);
});

test('submission during selection prevents saving and submitted intakes have no add controls', async () => {
  let finish;
  const app = fixture({ pick: () => new Promise(resolve => { finish = resolve; }) });
  app.button().props.onPress();
  app.intake.submitting = true; app.draw();
  finish({ canceled: false, assets: [asset('a.jpg')] }); await tick();
  assert.equal(app.saved.length, 0);
  assert.equal(app.button(), undefined);
  app.intake.submitting = false; app.intake.state.submittedAt = '2026-10-16T12:00:00Z';
  assert.equal(app.button(), undefined);
});

test('existing-only photos allow deletion in drafts and lock during or after submission', () => {
  const refs = ['attachment:old:old.jpg', 'attachment:keep:keep.jpg'];
  const app = fixture({ initial: refs, max: 0 });
  const removals = () => app.draw().filter(n => n.props?.accessibilityLabel === 'Bijlage verwijderen');
  assert.equal(app.button().props.disabled, true);
  assert.equal(removals().length, 2);
  removals()[0].props.onPress();
  assert.deepEqual(app.value(), [refs[1]]);
  assert.deepEqual(app.published, [[refs[1]]]);
  app.intake.submitting = true;
  assert.equal(removals().length, 0);
  app.intake.submitting = false;
  app.intake.state.submittedAt = '2026-10-19T12:00:00Z';
  assert.equal(removals().length, 0);
  assert.equal(app.button(), undefined);
  assert.deepEqual(app.value(), [refs[1]]);
});

test('view batches reject browser overflow and cancellation saves nothing', async () => {
  const slots = [{ id: 'left', label: 'Links', field: 'left' }];
  const excess = fixture({ slots });
  excess.button().props.onPress(); await tick();
  assert.match(text(excess.draw()), /Kies maximaal 1 foto/);
  assert.equal(excess.saved.length, 0);
  const app = fixture({ assets: [asset('a.jpg')], slots });
  app.button().props.onPress(); await tick();
  app.button('Annuleren').props.onPress();
  assert.equal(app.saved.length, 0);
  assert.equal(app.button('Foto’s opslaan'), undefined);
});

test('a view filled during file storage does not overwrite the newer answer', async () => {
  const app = fixture({ assets: [asset('a.jpg')], slots: [{ id: 'left', label: 'Links', field: 'left' }],
    save: () => { app.props.slots = []; app.draw(); } });
  app.button().props.onPress(); await tick();
  app.draw().find(n => n.props?.accessibilityRole === 'radio').props.onPress();
  app.button('Foto’s opslaan').props.onPress(); await tick();
  assert.equal(app.assigned.length, 0);
  assert.match(text(app.draw()), /aanzicht is inmiddels ingevuld/);
});

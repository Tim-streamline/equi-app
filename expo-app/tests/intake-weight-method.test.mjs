import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from 'typescript';

const jsx = (type, props) => typeof type === 'function' ? type(props) : ({ type, props });
const flatten = node => Array.isArray(node) ? node.flatMap(flatten) : node && typeof node === 'object' ? [node, ...flatten(node.props?.children)] : [];
const text = node => Array.isArray(node) ? node.map(text).join('') : node && typeof node === 'object' ? text(node.props?.children) : typeof node === 'boolean' ? '' : node ?? '';
const exports = {};
const source = readFileSync(new URL('../components/intake/IntakeField.tsx', import.meta.url), 'utf8');
const compiled = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, jsx: ts.JsxEmit.ReactJSX } }).outputText;
const state = { answers: { paard: {} }, submittedAt: null };
const setField = (section, field, value) => { (state.answers[section] ??= {})[field] = value; };
const modules = {
  'react/jsx-runtime': { jsx, jsxs: jsx, Fragment: 'Fragment' },
  'react-native': { View: 'View', Text: 'Text', Pressable: 'Pressable' },
  'lucide-react-native': {},
  './IntakeScrollView': { IntakeTextInput: 'Input' },
  './PhotoInstructions': { PhotoInstructions: 'PhotoInstructions' },
  './AttachmentField': { AttachmentField: 'AttachmentField' },
  './ConfiguredPhotoField': { ConfiguredPhotoField: 'ConfiguredPhotoField', PhotoExample: 'PhotoExample' },
  './FieldLabel': { FieldLabel: 'FieldLabel' },
  './AgeField': { AgeField: 'AgeField' },
  '@/lib/intake/logic': { isFieldRequired: () => true, isNoneOption: () => false, photoNotApplicable: () => false, photoReferences: () => [], photoSlotReferences: () => [] },
  '@/lib/intake/store': { useIntake: () => ({ state, setField }) },
};
new Function('require', 'exports', compiled)(name => { assert.ok(name in modules, name); return modules[name]; }, exports);
const schemaExports = {};
const schemaCode = ts.transpileModule(readFileSync(new URL('../lib/intake/schema.ts', import.meta.url), 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText;
new Function('exports', schemaCode)(schemaExports);
const section = schemaExports.INTAKE_SCHEMA.find(s => s.id === 'paard');
const methodField = section.fields.find(f => f.id === 'gewicht-methode');
const dateField = section.fields.find(f => f.id === 'gewicht-methode-wanneer');
const render = (field, sectionId = 'paard', n = 8) => flatten(exports.IntakeField({ field, sectionId, n, noneOptions: [] }));

test('the measurement date is a normal required question using the existing saved key', () => {
  assert.equal(section.fields.indexOf(dateField), section.fields.indexOf(methodField) + 1);
  assert.equal(dateField.type, 'text');
  assert.equal(dateField.answerSection, 'paard');
  assert.deepEqual(dateField.showIf, { 'paard.gewicht-methode': methodField.options.slice(1) });
  setField('paard', 'gewicht-methode', 'rapport dierenarts');
  setField('paard', 'gewicht-methode-wanneer', 'mei 2026');
  assert.equal(render(methodField).some(node => node.type === 'Input'), false);
  const nodes = render(dateField);
  assert.equal(nodes.find(node => node.type === 'FieldLabel').props.label, 'Wanneer is het gewicht bepaald?');
  assert.equal(nodes.find(node => node.type === 'FieldLabel').props.n, 8);
  assert.equal(nodes.find(node => node.type === 'FieldLabel').props.required, true);
  const input = nodes.find(node => node.type === 'Input');
  assert.equal(input.props.value, 'mei 2026');
  assert.equal(input.props.placeholder, 'Bijv. mei 2026');
  input.props.onChangeText('juni 2026');
  assert.equal(state.answers.paard['gewicht-methode-wanneer'], 'juni 2026');
});

test('changing the method preserves the saved date, including when the question moves', () => {
  setField('paard', 'gewicht-methode-wanneer', 'mei 2026');
  for (const method of methodField.options) {
    render(methodField).find(node => node.type === 'Pressable' && text(node).includes(method)).props.onPress();
    assert.equal(state.answers.paard['gewicht-methode-wanneer'], 'mei 2026');
  }
  const moved = render(dateField, 'gezondheid').find(node => node.type === 'Input');
  assert.equal(moved.props.value, 'mei 2026');
  moved.props.onChangeText('juni 2026');
  assert.equal(state.answers.paard['gewicht-methode-wanneer'], 'juni 2026');
  assert.equal(state.answers.gezondheid, undefined);
});

test('submitted intakes display saved dates as their own read-only answer', () => {
  setField('paard', 'gewicht-methode', 'rapport dierenarts');
  setField('paard', 'gewicht-methode-wanneer', 'mei 2026');
  state.submittedAt = '2026-10-20';
  assert.match(render(dateField, 'gezondheid').map(text).join(' '), /mei 2026/);
  assert.doesNotMatch(render(methodField).map(text).join(' '), /mei 2026/);
  assert.equal(render(dateField).some(node => node.type === 'Input'), false);
  state.submittedAt = null;
});

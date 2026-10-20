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
const field = { id: 'gewicht-methode', label: 'Hoe is dit gewicht bepaald?', type: 'radio', options: ['geschat op zicht', 'gewogen op weegbrug', 'gemeten met gewichts-lint', 'rapport dierenarts'] };
const render = () => flatten(exports.IntakeField({ field, sectionId: 'paard', n: 7, noneOptions: [] }));

test('weight date input opens for measured methods, persists its value, and stays within question 7', () => {
  const option = label => render().find(node => node.type === 'Pressable' && text(node).includes(label));
  assert.equal(render().some(node => node.props?.accessibilityLabel === 'Wanneer is het gewicht bepaald?'), false);
  for (const method of field.options.slice(1)) {
    option(method).props.onPress();
    assert.equal(state.answers.paard['gewicht-methode-wanneer'], undefined);
    const input = render().find(node => node.props?.accessibilityLabel === 'Wanneer is het gewicht bepaald?');
    assert.ok(input, method);
    input.props.onChangeText('mei 2026');
    assert.equal(state.answers.paard['gewicht-methode-wanneer'], 'mei 2026');
    assert.equal(render().find(node => node.props?.accessibilityLabel === 'Wanneer is het gewicht bepaald?').props.value, 'mei 2026');
  }
  option('geschat op zicht').props.onPress();
  assert.equal(render().some(node => node.props?.accessibilityLabel === 'Wanneer is het gewicht bepaald?'), false);
  assert.equal(state.answers.paard['gewicht-methode-wanneer'], undefined);
  assert.equal(render().filter(node => node.type === 'FieldLabel').length, 1);
});

test('saved weight date is shown with the selected method in read-only intake', () => {
  setField('paard', 'gewicht-methode', 'rapport dierenarts');
  setField('paard', 'gewicht-methode-wanneer', 'mei 2026');
  state.submittedAt = '2026-10-20';
  assert.match(render().map(text).join(' '), /rapport dierenarts\nWanneer: mei 2026/);
  state.submittedAt = null;
});

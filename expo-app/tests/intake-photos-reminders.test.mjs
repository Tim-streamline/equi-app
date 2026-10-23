import * as intakeDates from '../lib/intake/dates.ts';
import * as repeaterRows from '../lib/intake/repeater.ts';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import ts from 'typescript';
const modules = {};
function load(path, dependencies) {
 const exports = {};
 const code = ts.transpileModule(readFileSync(new URL(path, import.meta.url), 'utf8'), {compilerOptions: {module: ts.ModuleKind.CommonJS, jsx: ts.JsxEmit.ReactJSX}}).outputText;
 new Function('require', 'exports', code)(key => { if (key === '@/lib/intake/repeater') return repeaterRows; if (key === './dates' || key === '@/lib/intake/dates') return intakeDates; if (key === './MonthYearField') return { MonthYearField: 'MonthYearField' }; assert.ok(key in dependencies, key); return dependencies[key]; }, exports);
 return exports;
}
for (const name of ['dates', 'schema', 'config', 'logic']) modules[`./${name}`] = load(`../lib/intake/${name}.ts`, modules);
const { INTAKE_SCHEMA } = modules['./schema'];
const { answersForSection, missingRequired, requiredProgress, isSectionComplete, showField } = modules['./logic'];
const photos = INTAKE_SCHEMA[0];
const jsx = (type, props) => ({type, props});
const flatten = node => Array.isArray(node) ? node.flatMap(flatten) : node && typeof node === 'object' ? [node, ...flatten(node.props?.children)] : [];

test('Intake fotos is the first regular section and moved questions retain their saved answer identifiers', () => {
 assert.equal(photos.title, 'Foto’s voor je intake'); assert.equal(photos.id, 'intake-fotos'); assert.equal(photos.nr, 0);
 assert.equal(photos.fields.filter(f => f.type === 'photo').length, 12);
 assert.equal(INTAKE_SCHEMA.slice(1).flatMap(s => s.fields).some(f => f.type === 'photo'), false);
 assert.equal(photos.fields.find(f => f.id === 'foto-hoeven').answerSection, 'fysiek');
 const answers = { fysiek: { 'foto-hoeven': ['attachment:old:hoef.jpg'] } };
 assert.deepEqual(answersForSection(photos, answers)['foto-hoeven'], answers.fysiek['foto-hoeven']);
 assert.equal(missingRequired(photos, {}, answers).some(f => f.id === 'foto-hoeven'), true);
});
test('photo visibility and completion obey ordinary CMS settings without hidden year or slot requirements', () => {
 const field = { id: 'custom-photo', type: 'photo', optional: false, showIf: { choice: 'Ja' } };
 const section = { id: 'custom', fields: [field] };
 assert.equal(showField(field, {choice: 'Nee'}), false);
 assert.equal(missingRequired(section, {choice: 'Ja'}).length, 1);
 assert.equal(missingRequired(section, {choice: 'Ja', 'custom-photo': ['attachment:custom:file.jpg']}).length, 0);
 field.optional = true;
 assert.equal(isSectionComplete(section, {choice: 'Ja'}), true);
});
test('the regular section renderer displays newly configured photo questions in any section', () => {
 for (const id of ['intake-fotos', 'custom']) {
  const field = {id: 'new-photo', type: 'photo', label: 'CMS photo'};
  const section = {id, nr: 0, fields: [field]};
  const dependencies = {
   react: {useEffect() {}, useMemo: fn => fn(), useRef: value => ({current: value}), useCallback: fn => fn(), useState: value => [value, () => {}]},
   'react/jsx-runtime': {jsx, jsxs: jsx}, 'react-native': {View: 'View', Text: 'Text', ScrollView: 'ScrollView'},
   'expo-router': {router: {}, useLocalSearchParams: () => ({id})},
   'react-native-safe-area-context': {SafeAreaView: 'SafeAreaView', useSafeAreaInsets: () => ({bottom: 0})},
   'lucide-react-native': {ChevronLeft: 'ChevronLeft', X: 'X'},
   '@/components/intake/LaminitisSafetyNotice': { LaminitisSafetyNotice: 'LaminitisSafetyNotice' },
    '@/components/ui/Button': {Button: 'Button'}, '@/components/ui/IconButton': {IconButton: 'IconButton'},
   '@/components/intake/IntakeScrollView': {IntakeScrollView: 'Scroll'}, '@/components/intake/IntakeField': {IntakeField: 'Field'},
   '@/lib/intake/schema-provider': {useIntakeSchema: () => ({schema: [section], noneOptions: []})},
   '@/lib/intake/logic': modules['./logic'], '@/lib/intake/submission': {formatIntakeDate: () => '16 oktober 2026'},
    '@/lib/intake/store': {useIntake: () => ({state: {answers: {}}, trackActivity() {}})},
  };
  const screen = load('../app/intake/section/[id].tsx', dependencies).default;
  assert.equal(flatten(screen()).find(n => n.type === 'Field').props.field.id, 'new-photo');
 }
});
test('moved photo input reuses the old upload namespace and writes through normal autosave', () => {
 const saved = [], field = photos.fields.find(f => f.id === 'foto-hoeven');
 const dependencies = {
  'react/jsx-runtime': {jsx, jsxs: jsx}, 'react-native': {View: 'View', Text: 'Text', Pressable: 'Pressable'},
  'lucide-react-native': {Check: 'Check', Plus: 'Plus', Trash2: 'Trash2'},
  './IntakeScrollView': {IntakeTextInput: 'TextInput'}, '@/lib/intake/logic': modules['./logic'],
  '@/lib/intake/submission': {formatIntakeDate: () => '16 oktober 2026'},
    '@/lib/intake/store': {useIntake: () => ({state: {answers: {fysiek: {'foto-hoeven': ['attachment:old:hoof.jpg']}}}, setField: (...args) => saved.push(args)})},
  './PhotoInstructions': {PhotoInstructions: () => null},
  './ConfiguredPhotoField': {ConfiguredPhotoField: 'Upload', PhotoExample: () => null},
  './AttachmentField': {AttachmentField: 'Upload'}, './FieldLabel': {FieldLabel: 'Label'}, './AgeField': {AgeField: 'Age'},
 };
 const {IntakeField} = load('../components/intake/IntakeField.tsx', dependencies);
 const input = flatten(IntakeField({field, sectionId: photos.id, n: 1, noneOptions: []})).find(n => n.type === 'Upload');
 assert.equal(input.props.sectionId, 'fysiek'); assert.equal(input.props.field.id, 'foto-hoeven');
 assert.deepEqual(input.props.value, ['attachment:old:hoof.jpg']);
 input.props.onChange(['attachment:new:hoof.jpg']);
 assert.deepEqual(saved, [['fysiek', 'foto-hoeven', ['attachment:new:hoof.jpg']]]);
});
test('reminder thresholds count required CMS questions including newly added photos', () => {
 const fields = Array.from({length: 9}, (_, i) => ({id: `q${i}`, type: 'text'}));
 fields.push({id: 'cms-photo', type: 'photo'});
 const schema = [{id: 'custom', fields}], answers = {custom: {}};
 for (let i = 0; i < 8; i++) answers.custom[`q${i}`] = 'yes';
 assert.equal(requiredProgress(answers, schema).stage, 'continue');
 answers.custom.q8 = 'yes'; assert.equal(requiredProgress(answers, schema).stage, 'almost');
 answers.custom['cms-photo'] = ['attachment:custom:photo.jpg'];
 assert.equal(requiredProgress(answers, schema).stage, 'ready');
});

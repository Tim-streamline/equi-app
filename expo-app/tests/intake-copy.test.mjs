import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import ts from 'typescript';

function load(file, modules) {
  const code = ts.transpileModule(readFileSync(new URL(file, import.meta.url), 'utf8'), {
    compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
  }).outputText;
  const exports = {};
  new Function('require', 'exports', code)(name => { assert.ok(name in modules, name); return modules[name]; }, exports);
  return exports;
}
const intake = {};
for (const name of ['schema', 'config', 'logic']) intake[`./${name}`] = load(`../lib/intake/${name}.ts`, intake);
const { INTAKE_SCHEMA: schema, INTAKE_NONE_OPTIONS: noneOptions } = intake['./schema'];

test('every intake section uses the save-and-return label with the existing navigation', () => {
  let id;
  const paths = [];
  const jsx = (type, props) => ({ type, props });
  const modules = {
    'react/jsx-runtime': { jsx, jsxs: jsx }, react: { useMemo: fn => fn() },
    'react-native': { View: 'View', Text: 'Text', ScrollView: 'ScrollView', KeyboardAvoidingView: 'KeyboardAvoidingView', Platform: { OS: 'android' } },
    'expo-router': { router: { replace: path => paths.push(path) }, useLocalSearchParams: () => ({ id }) },
    'react-native-safe-area-context': { SafeAreaView: 'SafeAreaView', useSafeAreaInsets: () => ({ bottom: 0 }) },
    'lucide-react-native': { ChevronLeft: 'ChevronLeft', X: 'X' },
    '@/components/ui/Button': { Button: 'Button' }, '@/components/ui/IconButton': { IconButton: 'IconButton' },
    '@/components/intake/IntakeScrollView': { IntakeScrollView: 'IntakeScrollView' },
    '@/components/intake/IntakeField': { IntakeField: 'IntakeField' },
    '@/lib/intake/schema-provider': { useIntakeSchema: () => ({ schema, noneOptions }) },
    '@/lib/intake/logic': intake['./logic'], '@/lib/intake/store': { useIntake: () => ({ state: { answers: {} } }) },
  };
  const { default: Section } = load('../app/intake/section/[id].tsx', modules);
  const flatten = node => Array.isArray(node) ? node.flatMap(flatten) : node && typeof node === 'object' ? [node, ...flatten(node.props?.children), ...flatten(node.props?.footer)] : [];
  assert.equal(schema.length, 12);
  for (const section of schema) {
    id = section.id;
    const nodes = flatten(Section());
    assert.equal(nodes.filter(node => node.type === 'IntakeScrollView').length, 1, `${id} shares keyboard handling`);
    const questions = nodes.filter(node => node.type === 'IntakeField' && node.props.field.type !== 'sectionhead');
    assert.deepEqual(questions.map(node => node.props.n), questions.map((_, i) => i + 1), `${id} has consecutive numbering`);
    const buttons = nodes.filter(node => node.type === 'Button');
    assert.equal(buttons.length, 1, id);
    assert.equal(buttons[0].props.title, 'Opslaan en terug naar overzicht');
    buttons[0].props.onPress();
    assert.equal(paths.at(-1), '/intake/overview');
  }
});

test('backend questionnaire and app fallback do not promise a customer intake copy', () => {
  const definition = JSON.parse(readFileSync(new URL('../../backend/database/data/intake-questionnaire.json', import.meta.url), 'utf8'));
  const expected = undefined;
  for (const sections of [schema, definition.sections]) {
    assert.equal(sections.find(section => section.id === 'contact').fields.find(field => field.id === 'email').hint, expected);
  }
});

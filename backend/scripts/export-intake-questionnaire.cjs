const fs = require('node:fs');
const path = require('node:path');
const ts = require('../../expo-app/node_modules/typescript');

const root = path.resolve(__dirname, '../..');
const sourcePath = path.join(root, 'expo-app/lib/intake/schema.ts');
const outputPath = path.join(__dirname, '../database/data/intake-questionnaire.json');
const legacyDirectory = path.join(root, 'docs/legacy/intake');
const legacyPath = path.join(legacyDirectory, 'intake-schema-2026-08-23.ts');
const source = fs.readFileSync(sourcePath, 'utf8');
const javascript = ts.transpileModule(source, {
  compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
}).outputText;
const loaded = { exports: {} };
new Function('exports', 'module', 'require', javascript)(loaded.exports, loaded, require);

const payload = {
  slug: 'protocol-intake',
  name: 'Protocol-intake',
  disclaimer_short: loaded.exports.INTAKE_DISCLAIMER_SHORT,
  disclaimer_long: loaded.exports.INTAKE_DISCLAIMER_LONG,
  none_options: loaded.exports.INTAKE_NONE_OPTIONS,
  sections: loaded.exports.INTAKE_SCHEMA,
};

fs.mkdirSync(path.dirname(outputPath), { recursive: true });
fs.writeFileSync(outputPath, `${JSON.stringify(payload, null, 2)}\n`);
fs.mkdirSync(legacyDirectory, { recursive: true });
if (!fs.existsSync(legacyPath)) fs.copyFileSync(sourcePath, legacyPath);

console.log(`Exported ${payload.sections.length} sections and ${payload.sections.reduce((count, section) => count + section.fields.length, 0)} fields.`);

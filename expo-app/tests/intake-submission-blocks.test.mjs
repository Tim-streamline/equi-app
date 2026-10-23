import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from 'typescript';
const modules = {};
function load(file, extra = {}) {
 const exports={};
 const js=ts.transpileModule(readFileSync(new URL(file,import.meta.url),'utf8'),{compilerOptions:{module:ts.ModuleKind.CommonJS,jsx:ts.JsxEmit.ReactJSX}}).outputText;
 new Function('require','exports',js)(name=>{const value=extra[name]??modules[name];assert.ok(value,name);return value},exports);return exports;
}
for(const name of ['dates','schema','config','logic','submission-blocks']) modules['./'+name]=load('../lib/intake/'+name+'.ts');
const {submissionBlocks}=modules['./submission-blocks'];
const {INTAKE_SCHEMA}=modules['./schema'];
const schema=[{id:'health',title:'Health',fields:[{id:'condition',label:'Current condition',type:'radio',options:['acute','none'],submissionBlocks:[{answer:'acute',message:'Contact your vet.'}]},{id:'symptoms',type:'multi',label:'Symptoms',answerSection:'old',options:['pain','other'],showIf:{condition:'acute'},submissionBlocks:[{answer:'pain',message:'Wait before submitting.'}]}]}];
test('single and multiple choices produce configured cards and changing or hiding them removes blocks',()=>{
 assert.deepEqual(submissionBlocks(schema,{health:{condition:'acute'},old:{symptoms:['other','pain']}},[]).map(b=>b.message),['Contact your vet.','Wait before submitting.']);
 assert.deepEqual(submissionBlocks(schema,{health:{condition:'none'},old:{symptoms:['pain']}},[]),[]);
 assert.deepEqual(submissionBlocks(schema,{health:{condition:'none',_laminitis_hold:'old',_laminitis_recovery_required:'Ja'}},[]),[]);
});
test('the migrated medical rules retain conditional matching and remove recovery-only questions',()=>{
 const medisch=INTAKE_SCHEMA.find(s=>s.id==='medisch');
 assert.ok(!medisch.fields.some(f=>['hoefbevangenheid-acute-fase-voorbij','hoefbevangenheid-stabiel','hoefbevangenheid-herstel-uitleg'].includes(f.id)));
 assert.equal(submissionBlocks(INTAKE_SCHEMA,{medisch:{hoefbevangenheid:'Ja, mijn paard is momenteel hoefbevangen'}},[]).length,1);
 assert.equal(submissionBlocks(INTAKE_SCHEMA,{medisch:{hoefbevangenheid:'Ja, mijn paard is in het verleden hoefbevangen geweest','hoefbevangenheid-hersteld':'Nee'}},[]).length,1);
 assert.equal(submissionBlocks(INTAKE_SCHEMA,{medisch:{hoefbevangenheid:'Nee, voor zover bekend niet','hoefbevangenheid-hersteld':'Nee'}},[]).length,0);
});
test('synced field settings carry custom blocking text into the evaluator',()=>{
 const configured=modules['./config'].buildIntakeSchema([{id:'s',key:'custom',title:'Custom'}],[{section_id:'s',key:'answer',type:'radio',label:'Question',submission_blocks:JSON.stringify([{answer:'blocked',message:'Custom wording'}])}]);
 assert.equal(submissionBlocks(configured,{custom:{answer:'blocked'}},[])[0].message,'Custom wording');
});
test('the card renders every matching message and has no recovery controls',()=>{
 const jsx=(type,props)=>({type,props});
 const blocks=submissionBlocks(schema,{health:{condition:'acute'},old:{symptoms:['pain']}},[]);
 const component=load('../components/intake/SubmissionBlockNotice.tsx',{'react/jsx-runtime':{jsx,jsxs:jsx},'react-native':{View:'View',Text:'Text'},'@/lib/intake/store':{useIntake:()=>({submissionBlocks:blocks})}}).SubmissionBlockNotice;
 const result=JSON.stringify(component());
 assert.ok(result.includes('Contact your vet.'));assert.ok(result.includes('Wait before submitting.'));assert.ok(!result.includes('Pressable'));
 blocks.length=0;assert.equal(component(),null);
});

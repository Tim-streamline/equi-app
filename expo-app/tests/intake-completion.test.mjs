import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from 'typescript';
import { waitForIntakeUploads, formatIntakeDate } from '../lib/intake/submission.ts';
const load = (file, modules={}) => {
 const exports={}; const js=ts.transpileModule(readFileSync(new URL(file,import.meta.url),'utf8'),{compilerOptions:{module:ts.ModuleKind.CommonJS,jsx:ts.JsxEmit.ReactJSX}}).outputText;
 new Function('require','exports',js)(key=>{assert.ok(key in modules,key);return modules[key];},exports);return exports;
};
const schema=load('../lib/intake/schema.ts');
const config=load('../lib/intake/config.ts');
const logic=load('../lib/intake/logic.ts',{'./schema':schema,'./config':config});
const jsx=(type,props)=>({type,props});
const flatten=node=>Array.isArray(node)?node.flatMap(flatten):node&&typeof node==='object'?[node,...flatten(node.props?.children)]:[];
const text=node=>Array.isArray(node)?node.map(text).join(''):node&&typeof node==='object'?text(node.props?.children):node??'';
function overview(answers,submittedAt=null) {
 const s={id:'test',nr:0,title:'Test',fields:[{id:'required',type:'text'},{id:'optional',type:'text',optional:true}]};
 let submissions=0;
 const modules={
  react:{useEffect(){},useState:x=>[x,()=>{}]}, 'react/jsx-runtime':{jsx,jsxs:jsx},
  'react-native':{Text:'Text',View:'View',Pressable:'Pressable',ScrollView:'ScrollView'},
  'react-native-safe-area-context':{SafeAreaView:'SafeAreaView'}, 'lucide-react-native':{},
  'expo-router':{router:{replace(){}},useNavigation:()=>({canGoBack:()=>false})},
  '@/components/ui/Button':{Button:'Button'}, '@/components/ui/IconButton':{IconButton:'IconButton'},
  '@/lib/intake/submission':{formatIntakeDate},'@/lib/intake/logic':logic,
  '@/lib/intake/store':{formatSavedAgo:()=>null,useIntake:()=>({state:{answers,submittedAt},loaded:true,submitting:false,submit:async()=>{submissions++;}})},
  '@/lib/intake/schema-provider':{useIntakeSchema:()=>({schema:[s],noneOptions:[],disclaimerShort:''})},
 };
 return {nodes:flatten(load('../app/intake/overview.tsx',modules).default()),submissions:()=>submissions};
}
test('submit is visible but disabled for missing required answers; optional answers do not block',async()=>{
 assert.equal(overview({}).nodes.find(n=>n.type==='Button').props.disabled,true);
 const complete=overview({test:{required:'Done'}}); const button=complete.nodes.find(n=>n.type==='Button');
 assert.equal(button.props.title,'Intake versturen');assert.equal(button.props.disabled,false);
 await button.props.onPress();assert.equal(complete.submissions(),1);
});
test('submitted overview shows the date and keeps sections available without a submit button',()=>{
 const result=overview({test:{required:'Done'}},'2026-10-16T12:00:00Z');
 assert.equal(result.nodes.some(n=>n.type==='Button'),false);
 assert.match(result.nodes.map(text).join(' '),/Intake ingestuurd op 16 oktober 2026/);
 assert.ok(result.nodes.some(n=>n.props?.sec?.id==='test'));
});
test('submission waits for uploads, times out safely offline, and stops on session changes',async()=>{
 let checks=0; let reads=0;
 await waitForIntakeUploads({getUploadQueueStats:async()=>({count:reads++?0:1})},()=>checks++,1000);
 assert.ok(checks>=2);
 await assert.rejects(waitForIntakeUploads({getUploadQueueStats:async()=>({count:1})},()=>{},0),/gesynchroniseerd/);
 await assert.rejects(waitForIntakeUploads({getUploadQueueStats:async()=>({count:0})},()=>{throw new Error('session changed');}),/session changed/);
});
test('photo presentation and copy are data-driven and retain the required titles',()=>{
 const photos=schema.INTAKE_SCHEMA[0];
 assert.equal(photos.title,'Foto’s voor je intake');
 assert.equal(schema.INTAKE_SCHEMA.find(s=>s.id==='fysiek').title,'Lichamelijke observaties');
 assert.equal(photos.presentation.intro_style,'card');
 const field=photos.fields.find(f=>f.id==='foto-hooi');
 const render=f=>load('../components/intake/PhotoInstructions.tsx',{'react/jsx-runtime':{jsx,jsxs:jsx},'react-native':{Text:'Text',View:'View'}}).PhotoInstructions({field:f});
 assert.match(text(render(field)),/Zo maak je de foto/);
 assert.match(text(render({...field,photoConfig:{instructions:'Admin text',instruction_label:'Admin heading'}})),/Admin headingAdmin text/);
 assert.equal(render({...field,photoConfig:{}}),null);
 const result=config.buildIntakeSchema([{id:'s',key:'s',title:'Custom',presentation:JSON.stringify({intro_style:'card',offline_notice:'Custom notice'})}],[]);
 assert.equal(result[0].presentation.offline_notice,'Custom notice');
});

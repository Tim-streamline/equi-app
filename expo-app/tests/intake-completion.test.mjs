import * as intakeDates from '../lib/intake/dates.ts';
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from 'typescript';
import { waitForIntakeUploads, formatIntakeDate } from '../lib/intake/submission.ts';
const load = (file, modules={}) => {
 const exports={}; const js=ts.transpileModule(readFileSync(new URL(file,import.meta.url),'utf8'),{compilerOptions:{module:ts.ModuleKind.CommonJS,jsx:ts.JsxEmit.ReactJSX}}).outputText;
 new Function('require','exports',js)(key=>{if (key === './dates' || key === '@/lib/intake/dates') return intakeDates; if (key === './MonthYearField') return { MonthYearField: 'MonthYearField' }; assert.ok(key in modules,key);return modules[key];},exports);return exports;
};
const schema=load('../lib/intake/schema.ts');
const config=load('../lib/intake/config.ts');
const logic=load('../lib/intake/logic.ts',{'./schema':schema,'./config':config});
const jsx=(type,props)=>({type,props});
const flatten=node=>Array.isArray(node)?node.flatMap(flatten):node&&typeof node==='object'?[node,...flatten(node.props?.children)]:[];
const text=node=>Array.isArray(node)?node.map(text).join(''):node&&typeof node==='object'?text(node.props?.children):node??'';
function overview(answers,submittedAt=null,s={id:'test',nr:0,title:'Test',fields:[{id:'required',type:'text'},{id:'optional',type:'text',optional:true}]},screenName='overview') {
 let submissions=0; const routes=[]; const states=[]; let cursor=0;
 const modules={
  react:{useEffect(){},useState:initial=>{const index=cursor++;if(!(index in states))states[index]=initial;return [states[index],next=>{states[index]=typeof next==='function'?next(states[index]):next;}];}}, 'react/jsx-runtime':{jsx,jsxs:jsx},
  'react-native':{Text:'Text',View:'View',Pressable:'Pressable',ScrollView:'ScrollView'},
  'react-native-safe-area-context':{SafeAreaView:'SafeAreaView',useSafeAreaInsets:()=>({bottom:0})}, 'lucide-react-native':{},
  'expo-router':{router:{replace(){},push:route=>routes.push(route)},useNavigation:()=>({canGoBack:()=>false})},
  '@/components/intake/LaminitisSafetyNotice': { LaminitisSafetyNotice: 'LaminitisSafetyNotice' },
    '@/components/ui/Button':{Button:'Button'}, '@/components/ui/IconButton':{IconButton:'IconButton'},
  '@/components/intake/MissingQuestionsModal':{MissingQuestionsModal:'MissingQuestionsModal'},
  '@/lib/intake/submission':{formatIntakeDate},'@/lib/intake/logic':logic,
  '@/lib/intake/store':{formatSavedAgo:()=>null,useIntake:()=>({state:{answers,submittedAt},loaded:true,submitting:false,submit:async()=>{submissions++;}})},
  '@/lib/intake/schema-provider':{useIntakeSchema:()=>({schema:[s],noneOptions:[],disclaimerShort:''})},
 };
 const screen=load(`../app/intake/${screenName}.tsx`,modules).default;
 const render=()=>{cursor=0;return flatten(screen());};
 return {nodes:render(),render,submissions:()=>submissions,routes};
}
const weightField=schema.INTAKE_SCHEMA.find(s=>s.id==='paard').fields.find(f=>f.id==='gewicht-methode');
const dateField=schema.INTAKE_SCHEMA.find(s=>s.id==='paard').fields.find(f=>f.id==='gewicht-methode-wanneer');
const weightSection={id:'paard',nr:1,title:'Paard',fields:[weightField,dateField]};
test('synced questionnaire definitions and the app fallback use the same date question',()=>{
 const snapshot=JSON.parse(readFileSync(new URL('../../backend/database/data/intake-questionnaire-local.json',import.meta.url),'utf8'));
 const backendSection=snapshot.sections.find(s=>s.key==='paard');
 const synced=config.buildIntakeSchema([{...backendSection,id:'synced-paard'}],backendSection.fields.map(f=>({...f,section_id:'synced-paard'})))[0];
 const syncedDate=synced.fields.find(f=>f.id===dateField.id);
 for(const key of ['id','type','label','answerSection','placeholder','showIf','required']) assert.deepEqual(syncedDate[key],dateField[key],key);
 const answers={paard:{'gewicht-methode':'rapport dierenarts','gewicht-methode-wanneer':'mei 2026'}};
 assert.equal(logic.missingRequired(synced,{},answers).some(f=>f.id===dateField.id),false);
 delete answers.paard['gewicht-methode-wanneer'];
 assert.equal(logic.missingRequired(synced,{},answers).some(f=>f.id===dateField.id),true);
});
test('ordinary text validation rejects malformed values and still supports multiple text boxes',()=>{
 for(const value of [null,'','  ',42,{},[],['text']]) assert.equal(logic.isFieldAnswered({id:'other',type:'text'},value),false);
 assert.equal(logic.isFieldAnswered({id:'other',type:'text'},'mei 2026'),true);
 assert.equal(logic.isFieldAnswered({id:'other',type:'text',lines:2},['','text']),true);
 assert.equal(logic.isFieldAnswered({id:'other',type:'text',lines:2},['','  ']),false);
});
for(const method of ['gewogen op weegbrug','gemeten met gewichts-lint','rapport dierenarts']) {
 test(`${method}: missing dates block completion and progress`,()=>{
  for(const date of [undefined,null,'',' \n ',42,{},[]]) {
   const values={'gewicht-methode':method,'gewicht-methode-wanneer':date};
   const answers={paard:values};
   assert.equal(logic.isSectionComplete(weightSection,values,answers),false);
   assert.deepEqual(logic.answeredCount(weightSection,values,answers),{answered:1,total:2});
   assert.equal(logic.requiredProgress(answers,[weightSection]).stage,'continue');
   assert.equal(logic.missingRequired(weightSection,values,answers)[0].label,'Wanneer is het gewicht bepaald?');
  }
  const values={'gewicht-methode':method,'gewicht-methode-wanneer':'mei 2026'};
  assert.equal(logic.isSectionComplete(weightSection,values,{paard:values}),true);
  assert.equal(logic.requiredProgress({paard:values},[weightSection]).stage,'ready');
 });
 for(const screen of ['overview','submit']) {
  test(`${screen}: missing ${method} date is listed and links directly to the date question`,async()=>{
   const result=overview({paard:{'gewicht-methode':method}},null,weightSection,screen);
   await result.nodes.find(n=>n.props?.title==='Intake versturen').props.onPress();
   const modal=result.render().find(n=>n.type==='MissingQuestionsModal');
   assert.equal(result.submissions(),0);
   assert.equal(modal.props.visible,true);
   const {section,fields}=modal.props.sections[0];
   assert.equal(fields[0].label,'Wanneer is het gewicht bepaald?');
   modal.props.onSelect(section,fields[0]);
   assert.deepEqual(result.routes,[{pathname:'/intake/section/[id]',params:{id:'paard',field:'gewicht-methode-wanneer'}}]);
   const complete=overview({paard:{'gewicht-methode':method,'gewicht-methode-wanneer':'mei 2026'}},null,weightSection,screen);
   await complete.nodes.find(n=>n.props?.title==='Intake versturen').props.onPress();
   assert.equal(complete.submissions(),1);
  });
 }
}
test('estimated weights need no date, and moved weight questions retain the stored date namespace',()=>{
 const estimated={'gewicht-methode':'geschat op zicht','gewicht-methode-wanneer':'mei 2026'};
 assert.equal(logic.isSectionComplete(weightSection,estimated,{paard:estimated}),true);
 assert.deepEqual(logic.visibleFields(weightSection,estimated,{paard:estimated}).map(f=>f.id),['gewicht-methode']);
 assert.equal(logic.requiredProgress({paard:estimated},[weightSection]).total,1);
 assert.equal(logic.missingRequired(weightSection,{})[0].label,weightField.label);
 const moved={...weightSection,id:'moved',fields:[{...weightField,answerSection:'paard'},dateField]};
 assert.equal(logic.isSectionComplete(moved,{}, {paard:{'gewicht-methode':'rapport dierenarts'}}),false);
 assert.equal(logic.isSectionComplete(moved,{}, {paard:{'gewicht-methode':'rapport dierenarts','gewicht-methode-wanneer':'mei 2026'}}),true);
 const optional={...weightSection,fields:[{...weightField,optional:true},dateField]};
 assert.equal(logic.isSectionComplete(optional,{}),true);
 assert.equal(logic.isSectionComplete(optional,{}, {paard:{'gewicht-methode':'rapport dierenarts'}}),false);
});
test('submit attempt opens missing questions grouped by section and links to the question',async()=>{
 const result=overview({});
 assert.equal(result.nodes.some(n=>n.props?.title==='Ga naar eerste ontbrekende vraag'),false);
 await result.nodes.find(n=>n.props?.title==='Intake versturen').props.onPress();
 const modal=result.render().find(n=>n.type==='MissingQuestionsModal');
 assert.equal(modal.props.visible,true);
 assert.deepEqual(modal.props.sections.map(item=>[item.section.id,item.fields.map(field=>field.id)]),[['test',['required']]]);
 assert.equal(result.submissions(),0);
 modal.props.onSelect(modal.props.sections[0].section,modal.props.sections[0].fields[0]);
 assert.deepEqual(result.routes,[{pathname:'/intake/section/[id]',params:{id:'test',field:'required'}}]);
});
test('missing-question popup follows intake section order',()=>{
 const {MissingQuestionsModal}=load('../components/intake/MissingQuestionsModal.tsx',{
  'react/jsx-runtime':{jsx,jsxs:jsx},
  'react-native':{Modal:'Modal',Pressable:'Pressable',ScrollView:'ScrollView',Text:'Text',View:'View'},
  'lucide-react-native':{ChevronRight:'ChevronRight',X:'X'},
  '@/components/ui/Button':{Button:'Button'},
 });
 const sections=[{section:{id:'later',nr:4,title:'Later'},fields:[{id:'later-question',label:'Later vraag'}]},
  {section:{id:'earlier',nr:1,title:'Eerder'},fields:[{id:'earlier-question',label:'Eerdere vraag'}]}];
 const labels=flatten(MissingQuestionsModal({visible:true,sections,onClose(){},onSelect(){}}))
  .filter(node=>node.type==='Text').map(text);
 assert.ok(labels.indexOf('Sectie 2 · Eerder · 1')<labels.indexOf('Sectie 5 · Later · 1'));
 assert.ok(labels.indexOf('Eerdere vraag')<labels.indexOf('Later vraag'));
 assert.equal(sections[0].section.id,'later');
});
test('submit stays available and optional answers do not block it',async()=>{
 assert.equal(overview({}).nodes.find(n=>n.type==='Button'&&n.props.title==='Intake versturen').props.disabled,false);
 const complete=overview({test:{required:'Done'}}); const button=complete.nodes.find(n=>n.type==='Button'&&n.props.title==='Intake versturen');
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
 const render=f=>load('../components/intake/PhotoInstructions.tsx',{react:{useState:value=>[value,()=>{}]},'react/jsx-runtime':{jsx,jsxs:jsx},'react-native':{Text:'Text',View:'View',Pressable:'Pressable'},'lucide-react-native':{}}).PhotoInstructions({field:f});
 assert.match(text(render(field)),/Zo maak je de foto/);
 assert.match(text(render({...field,photoConfig:{instructions:'Admin text',instruction_label:'Admin heading'}})),/Admin headingAdmin text/);
 assert.equal(render({...field,photoConfig:{}}),null);
 const result=config.buildIntakeSchema([{id:'s',key:'s',title:'Custom',presentation:JSON.stringify({intro_style:'card',offline_notice:'Custom notice'})}],[]);
 assert.equal(result[0].presentation.offline_notice,'Custom notice');
});

import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import ts from 'typescript';
function load(path, modules = {}) {
 const exports={};
 const js=ts.transpileModule(readFileSync(new URL(path,import.meta.url),'utf8'),{compilerOptions:{module:ts.ModuleKind.CommonJS,jsx:ts.JsxEmit.ReactJSX}}).outputText;
 new Function('require','exports',js)(name=>{assert.ok(name in modules,name);return modules[name]},exports);
 return exports;
}
const rules=load('../lib/intake/laminitis.ts');
const config=JSON.parse(readFileSync(new URL('../../backend/tests/Fixtures/intake-safety.json',import.meta.url),'utf8'));
const flat=n=>Array.isArray(n)?n.flatMap(flat):n&&typeof n==='object'?[n,...flat(n.props?.children)]:[];
const text=n=>Array.isArray(n)?n.map(text).join(''):n&&typeof n==='object'?text(n.props?.children):n??'';
function setup(submitted) {
 const states=[];let cursor=0;const sent=[];const writes=[];
 const state={answers:{},submittedAt:submitted?'2026-10-20':null};
 const context={state,safetyConfig:config,laminitisBlocked:true,laminitisSafety:{episode_id:'episode',kind:'urgent',configuration:config,requirements:config.recovery.require,
  recovery_fields:[{id:'resolved',label:'Original resolution question',options:['confirmed','no']},{id:'stable',label:'Original stability question',options:['approved','no']}]},
 setField:(section,key,value)=>{writes.push({section,key,value});state.answers[section]={...state.answers[section],[key]:value};},
 confirmLaminitisRecovery:async declarations=>{sent.push(declarations);}};
 const jsx=(type,props)=>({type,props});
 const modules={react:{useEffect(){},useRef:()=>({current:null}),useState:initial=>{const i=cursor++;if(!(i in states))states[i]=initial;return[states[i],v=>{states[i]=typeof v==='function'?v(states[i]):v}]}},
 'react/jsx-runtime':{jsx,jsxs:jsx},'react-native':{Modal:'Modal',Pressable:'Pressable',Text:'Text',View:'View'},
 '@/components/ui/Button':{Button:'Button'},'@/lib/intake/store':{useIntake:()=>context},
 '@/lib/intake/schema-provider':{useIntakeSchema:()=>({schema:[{id:'custom-safety',fields:[{id:'resolved',label:'Changed label',options:['wrong-value']},{id:'urgent-warning',label:'Configured warning',hint:'Configured hint'}]}]})},
 '@/lib/intake/laminitis':rules};
 const component=load('../components/intake/LaminitisSafetyNotice.tsx',modules).LaminitisSafetyNotice;
 const render=()=>{cursor=0;return flat(component({recovery:true}));};
 const choose=value=>render().find(n=>n.type==='Pressable'&&text(n)===value).props.onPress();
 const button=()=>render().find(n=>n.type==='Button'&&n.props.title===config.messages.confirm);
 return{render,choose,button,sent,writes,state};
}
test('submitted recovery renders pinned questions and sends configured values without editing answers',async()=>{
 const ui=setup(true);
 assert.ok(ui.render().some(n=>text(n)==='Original resolution question'));
 assert.ok(!ui.render().some(n=>text(n)==='wrong-value'));
 assert.equal(ui.button().props.disabled,true);
 ui.choose('confirmed');ui.choose('approved');
 assert.equal(ui.button().props.disabled,false);
 await ui.button().props.onPress();
 assert.deepEqual(ui.sent,[{resolved:'confirmed',stable:'approved'}]);
 assert.deepEqual(ui.writes,[]);
 assert.deepEqual(ui.state.answers,{});
});
test('draft recovery writes to the configured section and requires all confirmations',async()=>{
 const ui=setup(false);
 ui.choose('confirmed');
 assert.equal(ui.button().props.disabled,true);
 ui.choose('approved');
 assert.equal(ui.button().props.disabled,false);
 await ui.button().props.onPress();
 assert.deepEqual(ui.writes,[{section:'custom-safety',key:'resolved',value:'confirmed'},{section:'custom-safety',key:'stable',value:'approved'}]);
 assert.deepEqual(ui.sent,[undefined]);
});

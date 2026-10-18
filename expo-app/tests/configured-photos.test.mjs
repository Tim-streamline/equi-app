import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import ts from 'typescript';
function load(file, modules) {
  const exports = {};
  const js = ts.transpileModule(readFileSync(new URL(file, import.meta.url), 'utf8'), {compilerOptions: {module: ts.ModuleKind.CommonJS, jsx: ts.JsxEmit.ReactJSX}}).outputText;
  new Function('require', 'exports', js)(key => { assert.ok(key in modules, key); return modules[key]; }, exports);
  return exports;
}
const modules = {};
for (const name of ['schema', 'config', 'logic']) modules[`./${name}`] = load(`../lib/intake/${name}.ts`, modules);
const {photoAnswered, missingRequired, answeredCount, visibleFields, requiredProgress, groupedPhotoFields} = modules['./logic'];
const section = modules['./schema'].INTAKE_SCHEMA[0];
const field = id => section.fields.find(f => f.id === id);

test('all photo categories are visible before later intake answers; old catch-all only shows saved uploads', () => {
 const visible = visibleFields(section, {}, {});
 assert.ok(visible.some(f => f.id === 'hoefbevangenheid-rontgen-upload'));
 assert.ok(visible.some(f => f.id === 'giftige-planten-foto'));
 assert.ok(!visible.some(f => f.id === 'foto-hoef-instructie'));
 assert.ok(!visibleFields(section, {}, {fysiek: {'foto-hoef-instructie': ['attachment:old:file.jpg']}}).some(f => f.id === 'foto-hoef-instructie'));
 assert.equal(field('sec-hoef-upload').photoConfig.slots.length,12);
});
test('saved not-applicable choices complete questions without images and can be reversed without losing images', () => {
 const f = field('foto-weide'), s = {id: 'photos', fields: [{...f, optional: false}]};
 const answers = {__photos: {'weide-na': 'yes'}, huisvesting: {'foto-weide': []}};
 assert.deepEqual(answeredCount(s, {}, answers), {answered: 1, total: 1});
 assert.deepEqual(missingRequired(s, {}, answers), []);
 answers.__photos['weide-na'] = '';
 assert.equal(missingRequired(s, {}, answers).length, 1);
 answers.huisvesting['foto-weide'] = ['attachment:existing:image.jpg'];
 assert.equal(photoAnswered(f, answers.huisvesting['foto-weide'], answers), true);
});
test('every historical photo needs a valid saved year, although leaving the category empty is optional', () => {
 const f=field('foto-historie'), s={id:'photos',fields:[f]};
 const answers={klacht:{'foto-historie':[]},__photos:{}};
 assert.deepEqual(missingRequired(s,{},answers),[]);
 answers.klacht['foto-historie']=['attachment:a:a.jpg','attachment:b:b.jpg'];
 answers.__photos['year-a']='2023';
 assert.equal(requiredProgress(answers,[s]).stage,'continue');
 for (const year of ['', 'xx', '9999']) { answers.__photos['year-b']=year; assert.equal(missingRequired(s,{},answers).length,1); }
 answers.__photos['year-b']='2024';
 assert.deepEqual(missingRequired(s,{},answers),[]);
 assert.equal(answeredCount(s,{},JSON.parse(JSON.stringify(answers))).answered,1);
});
test('hoof completeness requires every configured view and new CMS slots change validation', () => {
 const f=structuredClone(field('foto-hoeven'));
 const refs=f.photoConfig.slots.map(slot=>`attachment:${slot.id}:hoof.jpg`);
 const answers={__photos:{}};
 assert.equal(photoAnswered(f,refs,answers),false);
 for (const slot of f.photoConfig.slots) answers.__photos[`slot-${slot.id}`]=slot.id;
 assert.equal(photoAnswered(f,refs,answers),true);
 f.photoConfig.slots.push({id:'custom',label:'Admin view'});
 assert.equal(photoAnswered(f,refs,answers),false);
 assert.equal(photoAnswered({...f,photoConfig:{}},refs,answers),true);
});
const flatten = node => Array.isArray(node) ? node.flatMap(flatten) : node && typeof node === 'object' ? [node,...flatten(node.props?.children)] : [];
function ui(f, initial) {
 const state={answers:structuredClone(initial)}, writes=[];
 const jsx=(type,props)=>typeof type==='function'?type(props):({type,props});
 const {ConfiguredPhotoField}=load('../components/intake/ConfiguredPhotoField.tsx',{
  react:{useRef:initial=>({current:initial}),useState:()=>[undefined,()=>{}],useEffect:()=>{}},'react/jsx-runtime':{jsx,jsxs:jsx},'react-native':{View:'View',Text:'Text',Pressable:'Pressable',Image:'Image'},
  './IntakeScrollView':{IntakeTextInput:'Input'}, './AttachmentField':{AttachmentField:'Upload',Photo:'Photo'},
  './PhotoInstructions':{PhotoInstructions:'PhotoInstructions'},
  '@/lib/intake/logic':modules['./logic'],'@/db/auth':{getApiBaseUrl:()=> 'https://example.test'},
  '@/lib/intake/store':{useIntake:()=>({state,setField:(s,k,v)=>{(state.answers[s]??={})[k]=v;writes.push([s,k,v]);}})},
 });
 const address=f.answerSection??'photos';
 const render=()=>flatten(ConfiguredPhotoField({field:f,sectionId:address,value:state.answers[address]?.[f.id],onChange:v=>{(state.answers[address]??={})[f.id]=v;}}));
 return {render,state,writes};
}
test('generic controls restore years and preserve images when toggling not-applicable',()=>{
 const f=field('foto-weide'),app=ui(f,{huisvesting:{'foto-weide':['attachment:a:a.jpg']}});
 app.render().find(n=>n.props?.accessibilityRole==='checkbox').props.onPress();
 assert.equal(app.state.answers.__photos['weide-na'],'yes');
 assert.equal(app.render().filter(n=>n.type==='Upload').length,0);
 app.render().find(n=>n.props?.accessibilityRole==='checkbox').props.onPress();
 assert.deepEqual(app.render().find(n=>n.type==='Upload').props.value,['attachment:a:a.jpg']);
 const history=ui(field('foto-historie'),{klacht:{'foto-historie':['attachment:a:a.jpg']},__photos:{'year-a':'2023'}});
 const upload=history.render().find(n=>n.type==='Upload');
 assert.equal(upload.props.max,10);
 const input=flatten(upload.props.renderMetadata('attachment:a:a.jpg')).find(n=>n.type==='Input');
 assert.equal(input.props.value,'2023');input.props.onChangeText('2024');
 assert.deepEqual(history.writes,[['__photos','year-a','2024']]);
});
test('separate uploads retain earlier views even when upload callbacks finish out of order',()=>{
 const f=field('foto-hoeven'), app=ui(f,{fysiek:{'foto-hoeven':[]}});
 const uploads=app.render().filter(n=>n.type==='Upload' && !n.props.slots);
 assert.equal(uploads.length,4);
 uploads[1].props.onChange(['attachment:b:b.jpg']);
 uploads[0].props.onChange(['attachment:a:a.jpg']);
 assert.deepEqual(app.state.answers.fysiek['foto-hoeven'],['attachment:b:b.jpg','attachment:a:a.jpg']);
 assert.equal(app.state.answers.__photos['slot-a'],'lv');
 assert.equal(app.state.answers.__photos['slot-b'],'rv');
});
test('older hoof uploads can be assigned a view without uploading a replacement',()=>{
 const app=ui(field('foto-hoeven'),{fysiek:{'foto-hoeven':['attachment:old:hoof.jpg']}});
 const upload=app.render().filter(n=>n.type==='Upload').at(-1);
 assert.equal(upload.props.max,0);
 flatten(upload.props.renderMetadata('attachment:old:hoof.jpg')).find(n=>n.type==='Pressable').props.onPress();
 const first=app.render().find(n=>n.type==='Upload');
 assert.deepEqual(first.props.value,['attachment:old:hoof.jpg']);
});

test('four hooves count as question eleven and require twelve views or one not-applicable choice',()=>{
 const visible=visibleFields(section,{},{});
 assert.equal(visible[10].id,'sec-hoef-upload');
 assert.equal(visible[11].id,'hoefbevangenheid-rontgen-upload');
 assert.ok(!visible.some(f=>['foto-hoef-lv','foto-hoef-rv','foto-hoef-la','foto-hoef-ra'].includes(f.id)));
 const group=groupedPhotoFields(section.fields).find(f=>f.id==='sec-hoef-upload');
 assert.equal(group.photoConfig.grouped_hooves.length,4);
 assert.equal(group.photoConfig.slots.length,12);
 const answers={fysiek:{},__photos:{}};
 assert.equal(photoAnswered(group,undefined,answers,'intake-fotos'),false);
 for(const [index,slot] of group.photoConfig.slots.entries()) {
  const ref=`attachment:hoof-${index}:photo.jpg`;
  (answers.fysiek[slot.answer_field]??=[]).push(ref);
  answers.__photos[`slot-hoof-${index}`]=slot.answer_slot;
  assert.equal(photoAnswered(group,undefined,answers,'intake-fotos'),index===11);
 }
 answers.__photos['extra-hoeven-na']='yes';
 answers.fysiek={};
 assert.equal(photoAnswered(group,undefined,answers,'intake-fotos'),true);
 assert.ok(!missingRequired(section,{},answers).some(f=>f.id==='sec-hoef-upload'));
});

test('grouped hoof controls show one not-applicable choice and save in the original hoof namespace',()=>{
 const f=groupedPhotoFields(section.fields).find(f=>f.id==='sec-hoef-upload');
 const app=ui(f,{fysiek:{}});
 const shown=app.render();
 assert.equal(shown.filter(n=>n.props?.accessibilityRole==='checkbox').length,1);
 const uploads=shown.filter(n=>n.type==='Upload');
 assert.equal(uploads.length,12);
 assert.ok(uploads.every(upload=>upload.props.max===1 && !upload.props.slots));
 uploads[0].props.onChange(['attachment:hoof-0:hoof.jpg']);
 assert.deepEqual(app.state.answers.fysiek['foto-hoef-lv'],['attachment:hoof-0:hoof.jpg']);
 assert.equal(app.state.answers.__photos['slot-hoof-0'],'zool');
 app.render().find(n=>n.props?.accessibilityRole==='checkbox').props.onPress();
 assert.equal(app.render().filter(n=>n.type==='Upload').length,0);
 const old=ui(f,{fysiek:{'foto-hoef-instructie':['attachment:old:hoof.jpg']}});
 assert.equal(old.render().filter(n=>n.type==='Photo').length,1);
});

test('body views are question seven, require all four, and preserve their original answer namespaces', () => {
 const f=field('foto-zijaanzicht-links'), answers={fysiek:{}};
 const visible=visibleFields(section,{},answers);
 assert.equal(visible[6].id,f.id);
 assert.equal(visible[7].id,'foto-huid');
 assert.equal(visible.some(f=>f.id==='foto-zijaanzicht-rechts'),false);
 const s={id:'intake-fotos',fields:[f]};
 f.photoConfig.slots.forEach((slot,i)=>{
  answers.fysiek[slot.answer_field]=[`attachment:${slot.id}:horse.jpg`];
  assert.equal(answeredCount(s,{},answers).answered,i===3?1:0);
  assert.equal(missingRequired(s,{},answers).length,i===3?0:1);
 });
 const app=ui(f,answers), uploads=app.render().filter(n=>n.type==='Upload' && !n.props.slots);
 assert.equal(uploads.length,4);
 assert.equal(uploads[1].props.field,'foto-zijaanzicht-rechts');
 assert.deepEqual(uploads[1].props.value,['attachment:rechts:horse.jpg']);
 uploads[1].props.onChange(['attachment:new:right.jpg']);
 assert.deepEqual(app.state.answers.fysiek['foto-zijaanzicht-links'],['attachment:links:horse.jpg']);
 assert.deepEqual(app.state.answers.fysiek['foto-zijaanzicht-rechts'],['attachment:new:right.jpg']);
 assert.deepEqual(answeredCount(s,{},JSON.parse(JSON.stringify(app.state.answers))),{answered:1,total:1});
});
test('captions are optional, independently saved per image, and fully configured by admin',()=>{
 for (const [id,placeholder] of Object.entries({'foto-hooi':'Weidehooi close-up','foto-stal':'Nachtverblijf','foto-weide':'Zomerweide','giftige-planten-foto':'Rondom de paddock','foto-huid':'Plekje op de hals'})) {
  assert.deepEqual(field(id).photoConfig.caption,{label:'Eventuele toelichting',placeholder});
 }
 const f=structuredClone(field('foto-hooi')); f.photoConfig.caption={label:'Admin label',placeholder:'Admin placeholder'};
 const app=ui(f,{voer:{'foto-hooi':['attachment:a:a.jpg','attachment:b:b.jpg']}});
 const upload=app.render().find(n=>n.type==='Upload');
 const inputs=['a','b'].map(id=>flatten(upload.props.renderMetadata(`attachment:${id}:${id}.jpg`)).find(n=>n.type==='Input'));
 assert.equal(inputs[0].props.accessibilityLabel,'Admin label'); assert.equal(inputs[0].props.placeholder,'Admin placeholder');
 inputs[0].props.onChangeText('Eerste baal'); inputs[1].props.onChangeText('Tweede baal');
 assert.deepEqual(app.state.answers.__photos,{'caption-a':'Eerste baal','caption-b':'Tweede baal'});
 assert.equal(flatten(app.render().find(n=>n.type==='Upload').props.renderMetadata('attachment:a:a.jpg')).find(n=>n.type==='Input').props.value,'Eerste baal');
 delete f.photoConfig.caption;
 assert.equal(app.render().find(n=>n.type==='Upload').props.renderMetadata,undefined);
});

test('batch assignments preserve configured body answer fields and hoof view metadata', () => {
 for (const id of ['foto-zijaanzicht-links', 'foto-hoeven']) {
  const f=field(id), app=ui(f,{fysiek:{}});
  let batch=app.render().find(n=>n.type==='Upload' && n.props.slots);
  assert.deepEqual(batch.props.slots.map(s=>s.id),f.photoConfig.slots.map(s=>s.id));
  for (const slot of f.photoConfig.slots) {
   batch=app.render().find(n=>n.type==='Upload' && n.props.slots);
   batch.props.onSlotAdded(`attachment:${slot.id}:horse.jpg`,slot.id);
  }
  batch=app.render().find(n=>n.type==='Upload' && n.props.slots);
  assert.deepEqual(batch.props.slots,[]);
  assert.equal(photoAnswered(f,app.state.answers.fysiek[f.id],app.state.answers,'fysiek'),true);
  for (const slot of f.photoConfig.slots) {
   if (slot.answer_field) assert.deepEqual(app.state.answers.fysiek[slot.answer_field],[`attachment:${slot.id}:horse.jpg`]);
   else assert.equal(app.state.answers.__photos[`slot-${slot.id}`],slot.id);
  }
 }
});

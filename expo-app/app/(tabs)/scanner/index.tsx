import { useCallback, useEffect, useRef, useState } from 'react';
import { BackHandler, Image, Platform, Pressable, Text, TextInput, View } from 'react-native';
import { router, useFocusEffect } from 'expo-router';
import * as ImagePicker from 'expo-image-picker';
import { ArrowRight, Camera, Check, CheckCircle2, ChevronRight, History, ImagePlus, Plus, ScanLine, Search, Upload, X } from 'lucide-react-native';
import { useCurrentUserId, useScannerProducts, useScanResults } from '@/db/hooks';
import { C, FeedIcon, RoundButton, ScannerButton, ScannerFrame, ScannerNote, ScanRow, s } from '@/components/scanner/ScannerUI';
import { feedCategory, matchesFeed, recentScans, type ScannerRow } from '@/lib/scanner-presentation';

type Screen = 'start' | 'search' | 'found' | 'photos';
type Photos = Record<string, ImagePicker.ImagePickerAsset>;
const PHOTO_FIELDS = [
  { key: 'front', title: 'Voorkant van de verpakking', detail: 'Zorg dat merk en productnaam goed leesbaar zijn.' },
  { key: 'composition', title: 'Samenstelling', detail: 'De volledige ingrediëntenlijst op de verpakking.' },
  { key: 'analysis', title: 'Analyse & toevoegingen', detail: 'Voedingswaarden en toegevoegde vitamines en mineralen.' },
];

export default function ScannerScreen() {
  const products = useScannerProducts();
  const scans = recentScans(useScanResults());
  const userId = useCurrentUserId();
  const [screen, setScreen] = useState<Screen>('start');
  const [query, setQuery] = useState('');
  const [name, setName] = useState('');
  const [selected, setSelected] = useState<ScannerRow | null>(null);
  const [photos, setPhotos] = useState<Photos>({});
  const [extraCount, setExtraCount] = useState(0);
  const [error, setError] = useState('');
  const [picking, setPicking] = useState(false);
  const [focused, setFocused] = useState(false);
  const pickingRef = useRef(false);
  const mounted = useRef(true);
  const currentUser = useRef(userId);
  currentUser.current = userId;
  useEffect(() => { mounted.current = true; return () => { mounted.current = false; }; }, []);
  useEffect(() => { setPhotos({}); setName(''); setQuery(''); setSelected(null); setExtraCount(0); setScreen('start'); }, [userId]);

  const back = useCallback(() => {
    setError('');
    if (screen === 'start') {
      if (router.canGoBack()) router.back();
      else router.replace('/(tabs)/(pager)/home');
    } else setScreen(screen === 'found' ? 'search' : screen === 'photos' && query ? 'search' : 'start');
  }, [screen, query]);
  useFocusEffect(useCallback(() => {
    const subscription = BackHandler.addEventListener('hardwareBackPress', () => {
      if (screen === 'start') return false;
      back(); return true;
    });
    return () => subscription.remove();
  }, [screen, back]));

  async function pick(key: string, camera: boolean) {
    if (pickingRef.current) return;
    pickingRef.current = true; setPicking(true); setError('');
    const owner = userId;
    try {
      if (camera && Platform.OS !== 'web') {
        const permission = await ImagePicker.requestCameraPermissionsAsync();
        if (!permission.granted) throw new Error('Geef toegang tot je camera, of kies een foto via Uploaden.');
      }
      const options: ImagePicker.ImagePickerOptions = { mediaTypes: ['images'], quality: 0.85, allowsEditing: false };
      const result = camera ? await ImagePicker.launchCameraAsync(options) : await ImagePicker.launchImageLibraryAsync(options);
      if (!mounted.current || currentUser.current !== owner || result.canceled || !result.assets?.[0]) return;
      setPhotos(previous => ({ ...previous, [key]: result.assets[0] }));
      setScreen('photos');
    } catch (e) {
      if (mounted.current && currentUser.current === owner) setError(e instanceof Error ? e.message : 'De foto kon niet worden geopend. Probeer het opnieuw.');
    } finally { pickingRef.current = false; if (mounted.current) setPicking(false); }
  }

  const filtered = products.filter(product => matchesFeed(product, query));
  const previousScan = selected ? scans.find(scan => scan.productId === selected.id) : undefined;
  const fields = [...PHOTO_FIELDS, ...Array.from({ length: extraCount }, (_, i) => ({ key: `extra-${i}`, title: `Extra foto ${i + 1}`, detail: 'Bijvoorbeeld de overige toevoegingen of een tweede deel van het etiket.' }))];
  const searchInput = <View style={{ flex: 1, flexDirection: 'row', alignItems: 'center', borderWidth: 1.5, borderColor: focused ? C.mint : 'rgba(27,42,42,0.15)', borderRadius: 14, backgroundColor: '#fff', paddingLeft: 14 }}>
    <Search size={18} color={C.teal} /><TextInput autoFocus value={query} onChangeText={setQuery} placeholder="Merk of productnaam" accessibilityLabel="Merk of productnaam" placeholderTextColor={C.muted} onFocus={() => setFocused(true)} onBlur={() => setFocused(false)} style={[s.input, { fontSize: 17 }, Platform.OS === 'web' ? { outlineStyle: 'none' } as any : undefined]} />
    {!!query && <RoundButton label="Zoekopdracht wissen" onPress={() => setQuery('')}><X size={14} color={C.muted} /></RoundButton>}
  </View>;
  const foundActions = screen === 'found' && selected ? <>
    {previousScan ? <ScannerButton title="Bekijk voeranalyse" icon={<ArrowRight size={18} color="#fff" />} onPress={() => router.push({ pathname: '/(tabs)/scanner/result', params: { id: previousScan.id } })} />
      : <ScannerButton title="Etiketfoto’s toevoegen" icon={<ImagePlus size={20} color="#fff" />} onPress={() => { setName(`${selected.brand ?? ''} ${selected.name ?? ''}`.trim()); setScreen('photos'); }} />}
    <ScannerButton title="Dit is niet mijn voer" variant="text" onPress={() => setScreen('search')} />
  </> : undefined;

  return <ScannerFrame title={screen === 'photos' ? 'Foto’s toevoegen' : ''} header={screen !== 'start'} onBack={back} navigation={screen === 'start'} showHistory={false} headerContent={screen === 'search' ? searchInput : undefined} footer={foundActions}>
    {!!error && <Text accessibilityRole="alert" style={[s.body, { color: C.danger }]}>{error}</Text>}
    {screen === 'start' && <>
      <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingTop: 8, paddingLeft: 4 }}>
        <Text style={[s.eyebrow, { fontSize: 14, color: C.teal }]}>Voerscanner</Text>
        <View style={{ borderRadius: 24, backgroundColor: '#fff', borderWidth: 1, borderColor: C.border }}><RoundButton label="Scanhistorie" onPress={() => router.push('/(tabs)/scanner/history')}><History size={20} color={C.ink} /></RoundButton></View>
      </View>
      <Text style={[s.title, { paddingHorizontal: 4, paddingTop: 2 }]}>Welk voer wil je scannen?</Text>
      <Text style={[s.body, { color: C.muted, paddingHorizontal: 4, marginBottom: 6 }]}>Ontdek wat er écht in paardenvoer zit en hoe de samenstelling scoort!</Text>
      <View style={{ backgroundColor: C.deep, borderRadius: 22, padding: 20, gap: 16, overflow: 'hidden' }}>
        <View style={{ flexDirection: 'row', gap: 12, alignItems: 'flex-start' }}>
          <View style={{ width: 44, height: 44, borderRadius: 14, backgroundColor: 'rgba(255,255,255,0.14)', alignItems: 'center', justifyContent: 'center' }}><ScanLine size={22} color="#fff" /></View>
          <View style={{ flex: 1 }}><Text style={[s.heading, { color: '#fff', fontSize: 19, lineHeight: 23 }]}>Fotografeer de voorkant</Text><Text style={[s.small, { fontSize: 14, lineHeight: 20, color: 'rgba(255,255,255,0.85)', marginTop: 3 }]}>Wij beoordelen het voer voor je.</Text></View>
        </View>
        <View style={{ flexDirection: 'row', gap: 8 }}>
          <View style={{ flex: 1.3 }}><ScannerButton title="Camera" variant="white" disabled={picking} icon={<Camera size={20} color={C.deep} />} onPress={() => void pick('front', true)} /></View>
          <View style={{ flex: 1 }}><ScannerButton title="Uploaden" variant="dark-outline" disabled={picking} icon={<Upload size={19} color="#fff" />} onPress={() => void pick('front', false)} /></View>
        </View>
        <Text style={[s.small, { color: 'rgba(255,255,255,0.8)', marginTop: -7 }]}>Een screenshot van een webshop kan ook.</Text>
      </View>
      <View style={{ flexDirection: 'row', alignItems: 'center', gap: 12, paddingHorizontal: 8, paddingVertical: 4 }}><View style={{ height: 1, backgroundColor: 'rgba(27,42,42,0.15)', flex: 1 }} /><Text style={s.small}>of</Text><View style={{ height: 1, backgroundColor: 'rgba(27,42,42,0.15)', flex: 1 }} /></View>
      <Pressable accessibilityRole="button" accessibilityLabel="Zoek op merk of productnaam" onPress={() => setScreen('search')} style={[s.row, { minHeight: 56, paddingHorizontal: 16, borderColor: 'rgba(27,42,42,0.15)' }]}><Search size={20} color={C.teal} /><Text style={[s.body, { fontSize: 17, color: C.muted }]}>Zoek op merk of productnaam</Text></Pressable>
      <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginTop: 10 }}><Text style={s.eyebrow}>Laatst gescand</Text><Pressable accessibilityRole="button" onPress={() => router.push('/(tabs)/scanner/history')} style={{ minHeight: 44, justifyContent: 'center' }}><Text style={[s.body, { color: C.teal, fontFamily: 'SourceSans3_700Bold' }]}>Alles »</Text></Pressable></View>
      <View style={{ gap: 8 }}>{scans.slice(0, 3).map(scan => <ScanRow key={scan.id} scan={scan} compact />)}{!scans.length && <View style={s.card}><Text style={s.rowTitle}>Hier verschijnen straks jouw scans!</Text><Text style={s.small}>Scan je eerste voer en ontdek hoe de samenstelling scoort.</Text></View>}</View>
    </>}
    {screen === 'search' && <>
      <Text style={[s.eyebrow, { marginTop: 6 }]}>{query.trim() ? `${filtered.length} resultaten` : 'Zoek in de voercatalogus'}</Text>
      {filtered.map(product => <Pressable key={product.id} accessibilityRole="button" onPress={() => { setSelected(product); setScreen('found'); }} style={s.row}>
        <FeedIcon /><View style={{ flex: 1, gap: 2 }}><Text style={s.small}>{product.brand}</Text><Text style={s.rowTitle}>{product.name}</Text><Text style={s.small}>{feedCategory(product.category)}</Text></View><ChevronRight size={18} color={C.muted} />
      </Pressable>)}
      {!filtered.length && <View style={{ padding: 16, gap: 6 }}><Text style={s.heading}>{query.trim() ? 'Geen voer gevonden' : 'Nog geen producten beschikbaar'}</Text><Text style={s.body}>Controleer de spelling, of voeg foto’s van het etiket toe.</Text></View>}
      {!!query.trim() && <Pressable accessibilityRole="button" onPress={() => { setName(query.trim()); setScreen('photos'); }} style={[s.row, { borderStyle: 'dashed', borderColor: C.teal, backgroundColor: C.pale, padding: 16 }]}>
        <View style={{ width: 36, height: 36, borderRadius: 18, backgroundColor: C.teal, alignItems: 'center', justifyContent: 'center' }}><Plus size={18} color="#fff" /></View><View style={{ flex: 1 }}><Text style={s.rowTitle}>Staat jouw voer er niet bij?</Text><Text style={s.small}>Foto’s toevoegen voor “{query.trim()}”</Text></View>
      </Pressable>}
    </>}
    {screen === 'found' && selected && <>
      <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8, paddingHorizontal: 4 }}><CheckCircle2 size={20} color={C.teal} /><Text style={[s.body, { color: C.teal, fontFamily: 'SourceSans3_700Bold' }]}>Gevonden in de voerdatabase</Text></View>
      <View style={[s.card, { padding: 0, gap: 0, overflow: 'hidden', boxShadow: '0 12px 32px rgba(18,122,121,0.08)' }]}>
        <View style={{ aspectRatio: 4 / 3, backgroundColor: C.pale, alignItems: 'center', justifyContent: 'center' }}><FeedIcon size={116} /></View>
        <View style={{ paddingHorizontal: 20, paddingTop: 18, paddingBottom: 20, gap: 4 }}>
          <View style={{ alignSelf: 'flex-start', borderRadius: 20, backgroundColor: '#F4EFE7', paddingHorizontal: 10, paddingVertical: 4, marginBottom: 8 }}><Text style={[s.small, { color: C.ink, fontFamily: 'SourceSans3_700Bold', fontSize: 12 }]}>{feedCategory(selected.category)}</Text></View>
          <Text style={[s.small, { fontSize: 14 }]}>{selected.brand}</Text><Text style={[s.heading, { fontSize: 26, lineHeight: 30 }]}>{selected.name}</Text>
        </View>
      </View>
      {!previousScan && <ScannerNote>Voor dit product is nog geen voeranalyse beschikbaar in jouw scanhistorie.</ScannerNote>}
    </>}
    {screen === 'photos' && <>
      <Text style={[s.title, { fontSize: 27, lineHeight: 30 }]}>Breng je voer in beeld</Text><Text style={[s.body, { color: C.muted }]}>Zorg dat de tekst scherp en volledig leesbaar is. Je kunt ook screenshots gebruiken.</Text>
      <ScannerNote>Automatische etiketherkenning wordt nog aangesloten. Je foto’s zijn alleen geselecteerd op dit scherm en worden nog niet verstuurd of geanalyseerd.</ScannerNote>
      <Text style={s.rowTitle}>Merk en productnaam</Text><View style={{ backgroundColor: '#fff', borderWidth: 1, borderRadius: 14, borderColor: focused ? C.mint : C.border }}><TextInput value={name} onChangeText={setName} placeholder="Merk en productnaam" accessibilityLabel="Merk en productnaam" onFocus={() => setFocused(true)} onBlur={() => setFocused(false)} style={[s.input, Platform.OS === 'web' ? { outlineStyle: 'none' } as any : undefined]} /></View>
      {fields.map(field => <View key={field.key} style={[s.card, { borderRadius: 20, padding: 16 }]}>
        <View style={{ flexDirection: 'row', gap: 10, alignItems: 'flex-start' }}><View style={{ flex: 1 }}><Text style={s.rowTitle}>{field.title}</Text><Text style={[s.small, { marginTop: 3 }]}>{field.detail}</Text></View>{photos[field.key] && <Check size={20} color={C.teal} />}</View>
        {photos[field.key] && <><Image accessibilityLabel={`Geselecteerde foto: ${field.title}`} source={{ uri: photos[field.key].uri }} resizeMode="contain" style={{ height: 150, width: '100%', borderRadius: 12, backgroundColor: C.canvas }} /><RoundButton label={`${field.title} verwijderen`} onPress={() => setPhotos(old => { const next = { ...old }; delete next[field.key]; return next; })}><X size={18} color={C.muted} /></RoundButton></>}
        <View style={{ flexDirection: 'row', gap: 8 }}><View style={{ flex: 1 }}><ScannerButton title={photos[field.key] ? 'Opnieuw' : 'Camera'} variant="light" disabled={picking} icon={<Camera size={18} color={C.teal} />} onPress={() => void pick(field.key, true)} /></View><View style={{ flex: 1 }}><ScannerButton title={photos[field.key] ? 'Vervangen' : 'Uploaden'} variant="outline" disabled={picking} icon={<Upload size={18} color={C.teal} />} onPress={() => void pick(field.key, false)} /></View></View>
      </View>)}
      <ScannerButton title="Extra foto toevoegen" variant="outline" icon={<Plus size={19} color={C.teal} />} onPress={() => setExtraCount(value => value + 1)} />
      <ScannerButton title="Zoek een bekend product" onPress={() => setScreen('search')} />
    </>}
  </ScannerFrame>;
}

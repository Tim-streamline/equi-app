import { useCallback, useEffect, useRef, useState } from 'react';
import { BackHandler, Platform, Pressable, Text, TextInput, View } from 'react-native';
import { router, useFocusEffect, useLocalSearchParams } from 'expo-router';
import * as ImagePicker from 'expo-image-picker';
import { ArrowRight, Camera, CheckCircle2, ChevronRight, History, ImagePlus, Plus, ScanLine, Search, Sparkle, Upload, X } from 'lucide-react-native';
import { PhotoReplacementSheet, ScannerPhotoCard } from '@/components/scanner/ScannerPhotoCard';
import { useCurrentUserId, useScannerProducts } from '@/db/hooks';
import { C, FeedIcon, RoundButton, ScannerButton, ScannerFrame, ScannerNote, ScanRow, s } from '@/components/scanner/ScannerUI';
import { feedCategory, matchesFeed, type ScannerRow } from '@/lib/scanner-presentation';
import { scannerRequest, scannerUuid, uploadFeedPhoto, type FeedSubmission } from '@/lib/feed-scanner-api';
import { useScannerHistory } from '@/hooks/useScannerHistory';

type Screen = 'start' | 'search' | 'found' | 'photos';
type Photos = Record<string, ImagePicker.ImagePickerAsset>;
const PHOTO_FIELDS = [
  { key: 'composition', title: 'Foto 1 · Samenstelling', detail: 'De volledige ingrediëntenlijst zoals op de verpakking.', required: true },
  { key: 'analysis', title: 'Foto 2 · Analyse', detail: 'Analytische bestanddelen en, waar mogelijk, vitaminen en mineralen.', required: true },
];

export default function ScannerScreen() {
  const params = useLocalSearchParams<{ mode?: string; productId?: string; designState?: string }>();
  const products = useScannerProducts();
  const { scans } = useScannerHistory();
  const userId = useCurrentUserId();
  const [screen, setScreen] = useState<Screen>('start');
  const [unknownFeed, setUnknownFeed] = useState(false);
  const [variant, setVariant] = useState(false);
  const [query, setQuery] = useState('');
  const [name, setName] = useState('');
  const [selected, setSelected] = useState<ScannerRow | null>(null);
  const [photos, setPhotos] = useState<Photos>({});
  const [extras, setExtras] = useState<string[]>([]);
  const extraSequence = useRef(0);
  const [replaceKey, setReplaceKey] = useState<string | null>(null);
  const [pickingKey, setPickingKey] = useState<string | null>(null);
  const handledVariant = useRef('');
  const handledDesignState = useRef('');
  const designPreview = process.env.EXPO_PUBLIC_SCANNER_DESIGN_PREVIEW === 'true' && Platform.OS === 'web' && typeof params.designState === 'string';
  const [error, setError] = useState('');
  const [picking, setPicking] = useState(false);
  const [focused, setFocused] = useState(false);
  const pickingRef = useRef(false);
  const submission = useRef<FeedSubmission | null>(null);
  const clientId = useRef(scannerUuid());
  const sendingRef = useRef(false);
  const [sending, setSending] = useState(false);
  const [uploadingKey, setUploadingKey] = useState<string | null>(null);
  const [submitted, setSubmitted] = useState(false);
  const mounted = useRef(true);
  const currentUser = useRef(userId);
  currentUser.current = userId;
  useEffect(() => { mounted.current = true; return () => { mounted.current = false; }; }, []);
  useEffect(() => { submission.current = null; clientId.current = scannerUuid(); setSubmitted(false); setSending(false); setPhotos({}); setName(''); setQuery(''); setSelected(null); setExtras([]); setUnknownFeed(false); setVariant(false); setReplaceKey(null); handledVariant.current = ''; handledDesignState.current = ''; setScreen('start'); }, [userId]);
  useEffect(() => {
    if (!designPreview) return;
    const key = `${userId}:${params.designState}`;
    if (handledDesignState.current === key) return;
    if (params.designState === 'found' || params.designState === 'variant') {
      if (!products[0]) return;
      setSelected(products[0]); setName(`${products[0].brand ?? ''} ${products[0].name ?? ''}`.trim());
    }
    handledDesignState.current = key;
    setUnknownFeed(params.designState === 'unknown'); setVariant(params.designState === 'variant');
    setScreen(params.designState === 'unknown' || params.designState === 'variant' ? 'photos' : params.designState === 'found' ? 'found' : params.designState === 'search' ? 'search' : 'start');
  }, [designPreview, params.designState, products, userId]);
  useEffect(() => {
    if (params.mode !== 'variant' || !params.productId) return;
    const product = products.find(item => item.id === params.productId);
    const key = `${userId}:${params.productId}`;
    if (!product || handledVariant.current === key) return;
    handledVariant.current = key;
    setSelected(product); setName(`${product.brand ?? ''} ${product.name ?? ''}`.trim());
    setPhotos({}); setExtras([]); setVariant(true); setUnknownFeed(false); setScreen('photos');
  }, [params.mode, params.productId, products, userId]);

  const back = useCallback(() => {
    setError('');
    if (screen === 'photos' && variant && router.canGoBack()) { router.back(); return; }
    if (screen === 'start') {
      if (router.canGoBack()) router.back();
      else router.replace('/(tabs)/(pager)/home');
    } else setScreen(screen === 'found' ? 'search' : screen === 'photos' && query ? 'search' : 'start');
  }, [screen, query, variant]);
  useFocusEffect(useCallback(() => {
    const subscription = BackHandler.addEventListener('hardwareBackPress', () => {
      if (screen === 'start') return false;
      back(); return true;
    });
    return () => subscription.remove();
  }, [screen, back]));

  async function pick(key: string, camera: boolean) {
    if (pickingRef.current) return;
    pickingRef.current = true; setPicking(true); setPickingKey(key); setError('');
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
      if (key === 'front' && screen === 'start') { setUnknownFeed(false); setVariant(false); }
      setScreen('photos');
    } catch (e) {
      if (mounted.current && currentUser.current === owner) setError(e instanceof Error ? e.message : 'De foto kon niet worden geopend. Probeer het opnieuw.');
    } finally { pickingRef.current = false; if (mounted.current) { setPicking(false); setPickingKey(null); } }
  }

  async function submit() {
    if (sendingRef.current || designPreview) return;
    sendingRef.current = true; setSending(true); setError(''); setSubmitted(true);
    const owner = userId;
    try {
      if (!submission.current) {
        const created = await scannerRequest<FeedSubmission>('/submissions', { client_id: clientId.current, name: name.trim() || 'Onbekend voer' });
        if (currentUser.current !== owner) return;
        submission.current = created;
      }
      if (currentUser.current !== owner) return;
      const id = submission.current.id;
      for (const [slot, asset] of Object.entries(photos)) {
        if (currentUser.current !== owner) return;
        setUploadingKey(slot);
        const uploaded = await uploadFeedPhoto(id, slot, asset);
        if (currentUser.current !== owner) return;
        submission.current = uploaded;
      }
      if (mounted.current && currentUser.current === owner) {
        submission.current = null; clientId.current = scannerUuid(); setSubmitted(false); setPhotos({}); setName(''); setExtras([]); setScreen('start');
        router.push({ pathname: '/(tabs)/scanner/processing', params: { id, start: '1' } });
      }
    } catch (e) { if (mounted.current && currentUser.current === owner) setError(e instanceof Error ? e.message : 'Versturen is niet gelukt. Probeer opnieuw.'); }
    finally { sendingRef.current = false; if (mounted.current && currentUser.current === owner) { setSending(false); setUploadingKey(null); } }
  }

  const filtered = products.filter(product => matchesFeed(product, query));
  const previousScan = selected ? scans.find(scan => scan.productId === selected.id) : undefined;
  const fields = [...PHOTO_FIELDS, ...(photos.front ? [{ key: 'front', title: 'Voorkant', detail: 'Je foto van de voorkant.', required: false }] : []), ...extras.map((key, i) => ({ key, title: `Extra foto ${i + 1}`, detail: 'Bijvoorbeeld de achterkant of zijkant.', required: false }))];
  const missingPhotos = PHOTO_FIELDS.filter(field => !photos[field.key]).length;
  const photoActions = screen === 'photos' ? <View style={{ borderTopWidth: 1, borderColor: C.border, paddingTop: 0, paddingBottom: 12 }}>
    <ScannerButton disabled={!!missingPhotos || sending || picking || designPreview} title={sending ? 'Foto’s versturen…' : missingPhotos ? `Nog ${missingPhotos} ${missingPhotos === 1 ? 'foto' : 'foto’s'} nodig` : 'Versturen en beoordelen'} onPress={() => void submit()} />
  </View> : undefined;
  const searchInput = <View style={{ flex: 1, flexDirection: 'row', alignItems: 'center', borderWidth: 1.5, borderColor: focused ? C.mint : 'rgba(27,42,42,0.15)', borderRadius: 14, backgroundColor: '#fff', paddingLeft: 14 }}>
    <Search size={18} color={C.teal} /><TextInput autoFocus value={query} onChangeText={setQuery} placeholder="Merk of productnaam" accessibilityLabel="Merk of productnaam" placeholderTextColor={C.muted} onFocus={() => setFocused(true)} onBlur={() => setFocused(false)} style={[s.input, { fontSize: 17 }, Platform.OS === 'web' ? { outlineStyle: 'none' } as any : undefined]} />
    {!!query && <RoundButton label="Zoekopdracht wissen" onPress={() => setQuery('')}><X size={14} color={C.muted} /></RoundButton>}
  </View>;
  const foundActions = screen === 'found' && selected ? <>
    {previousScan ? <ScannerButton title="Bekijk voeranalyse" icon={<ArrowRight size={18} color="#fff" />} onPress={() => router.push({ pathname: '/(tabs)/scanner/result', params: { id: previousScan.id } })} />
      : <ScannerButton title="Etiketfoto’s toevoegen" icon={<ImagePlus size={20} color="#fff" />} onPress={() => { setName(`${selected.brand ?? ''} ${selected.name ?? ''}`.trim()); setUnknownFeed(false); setVariant(false); setScreen('photos'); }} />}
    <ScannerButton title="Dit is niet mijn voer" variant="text" onPress={() => setScreen('search')} />
  </> : undefined;

  return <ScannerFrame title="" header={screen !== 'start'} onBack={back} navigation={screen === 'start'} showHistory={false} headerContent={screen === 'search' ? searchInput : undefined} footer={foundActions ?? photoActions}>
    {designPreview && <Text style={[s.small, { color: C.teal }]}>Ontwerpstand · alleen lokale vormcontrole</Text>}
    {!!error && <Text accessibilityRole="alert" style={[s.body, { color: C.danger }]}>{error}</Text>}
    {!!error && submitted && !sending && <ScannerButton title="Foto’s wijzigen en opnieuw beginnen" variant="outline" onPress={() => { submission.current = null; clientId.current = scannerUuid(); setSubmitted(false); setError(''); }} />}
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
      {!!query.trim() && <Pressable accessibilityRole="button" onPress={() => { setName(query.trim()); setUnknownFeed(true); setVariant(false); setScreen('photos'); }} style={[s.row, { borderStyle: 'dashed', borderColor: C.teal, backgroundColor: C.pale, padding: 16 }]}>
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
      <View style={{ paddingHorizontal: 4, paddingBottom: 6 }}>
        {!variant && <View style={{ width: 52, height: 52, borderRadius: 16, backgroundColor: C.pale, alignItems: 'center', justifyContent: 'center', marginBottom: 14 }}><Sparkle size={26} color={C.teal} /></View>}
        <Text style={[s.title, { fontSize: 28, lineHeight: 31 }]}>{variant ? 'Andere samenstelling?' : unknownFeed ? 'Dit voer kennen we nog niet!' : 'Foto’s van je voer'}</Text>
        <Text style={[s.body, { color: C.muted, marginTop: 8 }]}>{variant ? `Voeg foto’s toe van de samenstelling en analyse op jouw verpakking van ${selected?.name ?? 'dit voer'}.` : unknownFeed ? 'Geen probleem! Voeg twee foto’s toe van de samenstelling en analyse. Zorg dat de tekst scherp en volledig leesbaar is.' : 'Voeg twee duidelijke foto’s toe van de samenstelling en analyse op jouw verpakking.'}</Text>
      </View>
      {!variant && <View style={{ marginBottom: 4 }}>
        <Text style={[s.small, { fontFamily: 'SourceSans3_700Bold', paddingHorizontal: 4, paddingBottom: 6 }]}>Merk en productnaam</Text>
        <View style={{ backgroundColor: '#fff', borderWidth: 1, borderRadius: 14, borderColor: focused ? C.mint : 'rgba(27,42,42,0.15)' }}><TextInput editable={!submitted} value={name} onChangeText={setName} placeholder="Merk en productnaam" accessibilityLabel="Merk en productnaam" onFocus={() => setFocused(true)} onBlur={() => setFocused(false)} style={[s.input, Platform.OS === 'web' ? { outlineStyle: 'none' } as any : undefined]} /></View>
      </View>}
      {fields.map(field => <ScannerPhotoCard key={field.key} title={field.title} detail={field.detail} required={field.required}
        uri={photos[field.key]?.uri} phase={uploadingKey === field.key ? 'uploading' : pickingKey === field.key ? 'selecting' : photos[field.key] ? 'selected' : 'empty'} disabled={picking || submitted}
        onCamera={() => void pick(field.key, true)} onUpload={() => void pick(field.key, false)} onReplace={() => setReplaceKey(field.key)}
        onRemove={!submitted && (photos[field.key] || !field.required) ? () => { setPhotos(old => { const next = { ...old }; delete next[field.key]; return next; }); if (!field.required) setExtras(old => old.filter(key => key !== field.key)); } : undefined} />)}
      <Pressable accessibilityRole="button" disabled={submitted || fields.length >= 10} onPress={() => setExtras(old => [...old, `extra-${extraSequence.current++}`])} style={[s.button, { borderWidth: 1, borderStyle: 'dashed', borderColor: 'rgba(27,42,42,0.3)', borderRadius: 16, marginTop: 12 }]}><Plus size={18} color={C.ink} /><Text style={[s.buttonText, { color: C.ink, fontSize: 15 }]}>Extra foto toevoegen</Text></Pressable>
      <Text style={[s.small, { textAlign: 'center', paddingHorizontal: 12 }]}>Staan de gegevens verspreid over de verpakking? Voeg dan extra foto’s toe.</Text>
      <ScannerNote>Je foto’s worden verstuurd voor etiketherkenning. De gepubliceerde beoordelingsregels bepalen de uitkomst. Het product komt ook ter beoordeling bij ons binnen.</ScannerNote>
      <PhotoReplacementSheet visible={replaceKey !== null} onClose={() => setReplaceKey(null)}
        onCamera={() => { const key = replaceKey; setReplaceKey(null); if (key) void pick(key, true); }}
        onUpload={() => { const key = replaceKey; setReplaceKey(null); if (key) void pick(key, false); }} />
    </>}
  </ScannerFrame>;
}

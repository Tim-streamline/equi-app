import { useCallback, useEffect, useRef, useState } from 'react';
import { BackHandler, Image, Modal, Platform, Pressable, Text, TextInput, useWindowDimensions, View } from 'react-native';
import { router, useFocusEffect, useLocalSearchParams } from 'expo-router';
import * as ImagePicker from 'expo-image-picker';
import { Camera, CheckCircle2, ChevronRight, History, ImagePlus, Plus, ScanLine, Search, Upload, X } from 'lucide-react-native';
import { PhotoReplacementSheet, ScannerPhotoCard } from '@/components/scanner/ScannerPhotoCard';
import { useCurrentUserId, useScannerProducts } from '@/db/hooks';
import { C, closeScanner, FeedIcon, RoundButton, ScannerButton, ScannerFrame, ScannerNote, ScannerStepProgress, ScanRow, s } from '@/components/scanner/ScannerUI';
import { feedCategory, matchesFeed, type ScannerRow } from '@/lib/scanner-presentation';
import { scannerRequest, scannerUuid, uploadFeedPhoto, type FeedSubmission } from '@/lib/feed-scanner-api';
import { useScannerHistory } from '@/hooks/useScannerHistory';
import { missingPhotoCount, needsAdditionalPhotos, resumePhotoStep, unreadableComposition, type PhotoStep } from '@/lib/scanner-photo-flow';

type Screen = 'start' | 'search' | 'found' | 'photos';
type Photos = Record<string, ImagePicker.ImagePickerAsset>;

export default function ScannerScreen() {
  const params = useLocalSearchParams<{ mode?: string; productId?: string; designState?: string; submissionId?: string }>();
  const products = useScannerProducts();
  const { scans } = useScannerHistory();
  const userId = useCurrentUserId();
  const [screen, setScreen] = useState<Screen>('start');
  const [exampleOpen, setExampleOpen] = useState(false);
  const [exampleType, setExampleType] = useState<'ingredients' | 'analysis'>('ingredients');
  const { height: viewportHeight, fontScale } = useWindowDimensions();
  // Keep Camera/Uploaden above the fold on small phones (iPhone SE with Safari's toolbars).
  const compact = viewportHeight < 760;
  const [photoStep, setPhotoStep] = useState<PhotoStep>('composition');
  const uploadedAssets = useRef<Photos>({});
  const [unknownFeed, setUnknownFeed] = useState(false);
  const [variant, setVariant] = useState(false);
  const [query, setQuery] = useState('');
  const [name, setName] = useState('');
  const [selected, setSelected] = useState<ScannerRow | null>(null);
  const [photos, setPhotos] = useState<Photos>({});
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
  const [recognition, setRecognition] = useState<FeedSubmission | null>(null);
  const additional = needsAdditionalPhotos(recognition);
  const candidate = recognition?.status === 'candidate';
  const unreadable = unreadableComposition(recognition);
  const mounted = useRef(true);
  const currentUser = useRef(userId);
  currentUser.current = userId;
  useEffect(() => { mounted.current = true; return () => { mounted.current = false; }; }, []);
  useEffect(() => { submission.current = null; sendingRef.current = false; clientId.current = scannerUuid(); setRecognition(null); setSubmitted(false); setSending(false); setPhotos({}); uploadedAssets.current = {}; setPhotoStep('composition'); setName(''); setQuery(''); setSelected(null); setUnknownFeed(false); setVariant(false); setReplaceKey(null); handledVariant.current = ''; handledDesignState.current = ''; setScreen('start'); }, [userId]);
  useEffect(() => {
    if (!params.submissionId || !userId) return;
    let alive = true;
    setError('');
    void scannerRequest<FeedSubmission>(`/submissions/${params.submissionId}`).then(current => {
      if (!alive) return;
      if (current.status === 'completed' && current.scan_result_id) { router.replace({ pathname: '/(tabs)/scanner/result', params: { id: current.scan_result_id } }); return; }
      if (['checking', 'processing', 'review_required'].includes(current.status)) { router.replace({ pathname: '/(tabs)/scanner/processing', params: { id: current.id } }); return; }
      submission.current = current; setRecognition(current); setPhotos({}); uploadedAssets.current = {}; setPhotoStep(resumePhotoStep(current)); setName(current.submitted_name || current.suggested_name || ''); setScreen('photos'); setSubmitted(false);
    }).catch(e => { if (alive) setError(e.message); });
    return () => { alive = false; };
  }, [params.submissionId, userId]);
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
    if (params.mode !== 'variant' || !params.productId) { handledVariant.current = ''; return; }
    const product = products.find(item => item.id === params.productId);
    const key = `${userId}:${params.productId}`;
    if (!product || handledVariant.current === key) return;
    handledVariant.current = key;
    setSelected(product); setName(`${product.brand ?? ''} ${product.name ?? ''}`.trim());
    submission.current = null; clientId.current = scannerUuid(); setRecognition(null); setSubmitted(false);
    setPhotos({}); uploadedAssets.current = {}; setPhotoStep('composition'); setVariant(true); setUnknownFeed(false); setScreen('photos');
  }, [params.mode, params.productId, products, userId]);

  const back = useCallback(() => {
    setError('');
    if (sending || picking) return;
    if (screen === 'photos' && !candidate && photoStep !== 'composition') { setPhotoStep(photoStep === 'front' ? 'analysis' : 'composition'); return; }
    if (screen === 'photos' && variant && router.canGoBack()) { router.back(); return; }
    if (screen === 'start') {
      if (router.canGoBack()) router.back();
      else router.replace('/(tabs)/(pager)/home');
    } else setScreen(screen === 'found' ? 'search' : screen === 'photos' && query ? 'search' : 'start');
  }, [screen, query, variant, photoStep, candidate, sending, picking]);
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
      if (screen === 'start') { resetScan(); setPhotos({ [key]: result.assets[0] }); }
      if (key === 'composition' && unreadable) { submission.current = null; clientId.current = scannerUuid(); setRecognition(null); uploadedAssets.current = {}; }
      setScreen('photos');
      if (key === 'analysis' || key === 'front' || (key === 'composition' && screen !== 'start' && additional && !unreadable)) {
        try { await persistPhoto(key, result.assets[0]); }
        catch (e) { if (mounted.current && currentUser.current === owner) setError(e instanceof Error ? e.message : 'Uploaden is niet gelukt. Probeer opnieuw.'); }
      }
    } catch (e) {
      if (mounted.current && currentUser.current === owner) setError(e instanceof Error ? e.message : 'De foto kon niet worden geopend. Probeer het opnieuw.');
    } finally { pickingRef.current = false; if (mounted.current) { setPicking(false); setPickingKey(null); } }
  }

  async function persistPhoto(key: string, asset: ImagePicker.ImagePickerAsset) {
    if (!submission.current) throw new Error('Controleer eerst de ingrediëntenlijst.');
    const owner = userId;
    const scanId = submission.current.id;
    const previousSuggestion = submission.current.suggested_name;
    setUploadingKey(key);
    try {
      const uploaded = await uploadFeedPhoto(scanId, key, asset);
      if (!mounted.current || currentUser.current !== owner || submission.current?.id !== scanId) return;
      if (key === 'front' && !selected && !uploaded.reference_product_id) {
        // Replace only an empty field or our earlier suggestion, never a typed correction.
        setName(current => !current.trim() || current === previousSuggestion ? (uploaded.suggested_name ?? '') : current);
      }
      submission.current = uploaded; setRecognition(uploaded); uploadedAssets.current[key] = asset;
      if (uploaded.status === 'awaiting_composition') setPhotoStep('composition');
      setError('');
    } finally { if (mounted.current && currentUser.current === owner && submission.current?.id === scanId) setUploadingKey(null); }
  }

  async function continueAnalysis() {
    if (sendingRef.current || picking) return;
    sendingRef.current = true; setSending(true); setError('');
    const owner = userId;
    try {
      if (photos.analysis && uploadedAssets.current.analysis !== photos.analysis) await persistPhoto('analysis', photos.analysis);
      if (mounted.current && currentUser.current === owner && submission.current?.photo_ids?.analysis) setPhotoStep('front');
    } catch (e) { if (mounted.current && currentUser.current === owner) setError(e instanceof Error ? e.message : 'Uploaden is niet gelukt.'); }
    finally { if (currentUser.current === owner) { sendingRef.current = false; setSending(false); } }
  }

  async function submit() {
    if (sendingRef.current || picking || designPreview) return;
    sendingRef.current = true; setSending(true); setError(''); setSubmitted(true);
    const owner = userId;
    try {
      if (!submission.current) {
        const created = await scannerRequest<FeedSubmission>('/submissions', { client_id: clientId.current, name: name.trim() || undefined, reference_product_id: selected?.id });
        if (currentUser.current !== owner) return;
        submission.current = created;
      }
      if (currentUser.current !== owner) return;
      const id = submission.current.id;
      if (!additional && recognition === null && submission.current.photo_ids?.composition) {
        const current = await scannerRequest<FeedSubmission>(`/submissions/${id}`);
        if (!mounted.current || currentUser.current !== owner) return;
        if (!photos.composition && ['checking', 'candidate', 'needs_photos'].includes(current.status)) {
          submission.current = current; setRecognition(current); setPhotoStep(resumePhotoStep(current)); setSubmitted(false);
          if (current.status === 'checking') router.push({ pathname: '/(tabs)/scanner/processing', params: { id } });
          return;
        }
      }
      for (const [slot, asset] of Object.entries(photos)) {
        if (currentUser.current !== owner) return;
        if (uploadedAssets.current[slot] !== asset) await persistPhoto(slot, asset);
      }
      if (photoStep === 'composition' && additional && !unreadable) { setPhotoStep('analysis'); setSubmitted(false); return; }
      if (!additional && submission.current.workflow_version === 2) {
        const checked = await scannerRequest<FeedSubmission>(`/submissions/${id}/check`, {});
        if (!mounted.current || currentUser.current !== owner) return;
        submission.current = checked; setRecognition(checked); setPhotoStep(resumePhotoStep(checked)); setSubmitted(false);
        if (checked.status === 'checking') router.push({ pathname: '/(tabs)/scanner/processing', params: { id } });
        return;
      }
      if (mounted.current && currentUser.current === owner) {
        const productName = name.trim();
        resetScan();
        router.push({ pathname: '/(tabs)/scanner/processing', params: { id, start: '1', name: productName } });
      }
    } catch (e) { if (mounted.current && currentUser.current === owner) setError(e instanceof Error ? e.message : 'Versturen is niet gelukt. Probeer opnieuw.'); }
    finally { if (currentUser.current === owner) sendingRef.current = false; if (mounted.current && currentUser.current === owner) { setSending(false); setUploadingKey(null); } }
  }

  function resetScan() {
    setPhotoStep('composition'); uploadedAssets.current = {};
    submission.current = null; clientId.current = scannerUuid(); handledVariant.current = ''; setRecognition(null); setSubmitted(false); setPhotos({}); setName(''); setSelected(null); setVariant(false); setUnknownFeed(false); setScreen('start');
    router.setParams({ submissionId: undefined, mode: undefined, productId: undefined });
  }

  async function chooseKnown(choice: 'confirm' | 'changed' | 'different') {
    if (!recognition || sendingRef.current) return;
    const owner = userId; sendingRef.current = true; setSending(true); setError('');
    try {
      const current = await scannerRequest<FeedSubmission>(`/submissions/${recognition.id}/${choice === 'confirm' ? 'confirm' : 'more-photos'}`, choice === 'confirm' ? {} : { changed: choice === 'changed' });
      if (!mounted.current || currentUser.current !== owner) return;
      if (current.status === 'completed' && current.scan_result_id) { resetScan(); router.push({ pathname: '/(tabs)/scanner/result', params: { id: current.scan_result_id } }); }
      else { submission.current = current; setRecognition(current); setPhotoStep(resumePhotoStep(current)); setSubmitted(false); }
    } catch (e) { if (mounted.current && currentUser.current === owner) setError(e instanceof Error ? e.message : 'Probeer opnieuw.'); }
    finally { if (currentUser.current === owner) { sendingRef.current = false; setSending(false); } }
  }

  const filtered = products.filter(product => matchesFeed(product, query));
  const previousScan = selected ? scans.find(scan => scan.productId === selected.id) : undefined;
  const missingPhotos = missingPhotoCount(recognition, photos);
  const hasPhoto = (key: string) => !!photos[key] || !!recognition?.photo_ids?.[key];
  const busy = sending || picking || uploadingKey !== null;
  const photoActions = screen === 'photos' ? <View style={{ borderTopWidth: 1, borderColor: C.border, paddingTop: 0, paddingBottom: 12 }}>
    {candidate ? <><ScannerButton title={sending ? 'Even geduld…' : 'Dit is mijn voer · bekijk beoordeling'} disabled={sending || designPreview} onPress={() => void chooseKnown('confirm')} /><ScannerButton title="Ingrediëntenlijst of etiketgegevens wijken af" variant="outline" disabled={sending} onPress={() => void chooseKnown('changed')} /><ScannerButton title="Dit is ander voer" variant="text" disabled={sending} onPress={() => void chooseKnown('different')} /></>
      : photoStep === 'analysis' ? (hasPhoto('analysis') && <ScannerButton title={busy ? 'Foto uploaden…' : 'Naar de laatste stap'} disabled={busy || designPreview} onPress={() => void continueAnalysis()} />)
      : photoStep === 'front' ? <ScannerButton title={busy ? 'Foto’s versturen…' : 'Start de analyse'} disabled={busy || !!missingPhotos || !name.trim() || designPreview} onPress={() => void submit()} />
      : hasPhoto('composition') && !unreadable ? <ScannerButton disabled={busy || designPreview} title={busy ? 'Ingrediëntenlijst controleren…' : additional ? 'Verder naar de analysefoto' : 'Ingrediëntenlijst controleren'} onPress={() => void submit()} /> : null}
  </View> : undefined;
  const searchInput = <View style={{ flex: 1, flexDirection: 'row', alignItems: 'center', borderWidth: 1.5, borderColor: focused ? C.mint : 'rgba(27,42,42,0.15)', borderRadius: 14, backgroundColor: '#fff', paddingLeft: 14 }}>
    <Search size={18} color={C.teal} /><TextInput autoFocus value={query} onChangeText={setQuery} placeholder="Merk of productnaam" accessibilityLabel="Merk of productnaam" placeholderTextColor={C.muted} onFocus={() => setFocused(true)} onBlur={() => setFocused(false)} style={[s.input, { fontSize: 17 }, Platform.OS === 'web' ? { outlineStyle: 'none' } as any : undefined]} />
    {!!query && <RoundButton label="Zoekopdracht wissen" onPress={() => setQuery('')}><X size={14} color={C.muted} /></RoundButton>}
  </View>;
  const foundActions = screen === 'found' && selected ? <>
    <ScannerButton title="Controleer de ingrediëntenlijst" icon={<ImagePlus size={20} color="#fff" />} onPress={() => { submission.current = null; clientId.current = scannerUuid(); setRecognition(null); setPhotos({}); uploadedAssets.current = {}; setPhotoStep('composition'); setSubmitted(false); setName(`${selected.brand ?? ''} ${selected.name ?? ''}`.trim()); setUnknownFeed(false); setVariant(false); setScreen('photos'); }} />
    <ScannerButton title="Dit is niet mijn voer" variant="text" onPress={() => setScreen('search')} />
  </> : undefined;

  return <ScannerFrame title="" header={screen !== 'start'} onBack={back} showHistory={false} headerContent={screen === 'search' ? searchInput : undefined} footer={foundActions ?? photoActions}>
    {designPreview && <Text style={[s.small, { color: C.teal }]}>Ontwerpstand · alleen lokale vormcontrole</Text>}
    {!!error && <Text accessibilityRole="alert" style={[s.body, { color: C.danger }]}>{error}</Text>}
    {!!error && submitted && !sending && <ScannerButton title="Nieuwe inzending starten" variant="outline" onPress={() => { resetScan(); setError(''); }} />}
    {screen === 'start' && <>
      <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingTop: 8, paddingLeft: 4 }}>
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8, marginLeft: -4 }}>
          <View style={{ borderRadius: 24, backgroundColor: '#fff', borderWidth: 1, borderColor: C.border }}><RoundButton label="Scanner sluiten" onPress={closeScanner}><X size={20} color={C.ink} /></RoundButton></View>
          <Text style={[s.eyebrow, { fontSize: 14, color: C.teal }]}>Voerscanner</Text>
        </View>
        <View style={{ borderRadius: 24, backgroundColor: '#fff', borderWidth: 1, borderColor: C.border }}><RoundButton label="Scanhistorie" onPress={() => router.push('/(tabs)/scanner/history')}><History size={20} color={C.ink} /></RoundButton></View>
      </View>
      <Text style={[s.title, { paddingHorizontal: 4, paddingTop: 2 }, compact && { fontSize: 26, lineHeight: 29 }]}>Welk voer wil je scannen?</Text>
      <Text style={[s.body, { color: C.muted, paddingHorizontal: 4, marginBottom: 6 }]}>Ontdek wat er écht in paardenvoer zit en hoe de samenstelling scoort!</Text>
      <ScannerStepProgress step={1} />
      <View style={{ backgroundColor: C.deep, borderRadius: 22, padding: compact ? 16 : 20, gap: compact ? 12 : 16, overflow: 'hidden' }}>
        <View style={{ flexDirection: 'row', gap: 12, alignItems: 'flex-start' }}>
          <View style={{ width: 44, height: 44, borderRadius: 14, backgroundColor: 'rgba(255,255,255,0.14)', alignItems: 'center', justifyContent: 'center' }}><ScanLine size={22} color="#fff" /></View>
          <View style={{ flex: 1 }}><Text style={[s.heading, { color: '#fff', fontSize: 19, lineHeight: 23 }]}>Scan de ingrediëntenlijst</Text><Text style={[s.small, { fontSize: 14, lineHeight: 20, color: 'rgba(255,255,255,0.85)', marginTop: 3 }]}>Maak een foto van het gedeelte op de verpakking waar alle ingrediënten staan vermeld. Zoek op de zak naar “Samenstelling” of “Ingrediënten”. Een screenshot van een webshop mag ook.</Text></View>
        </View>
        {/* Side by side the labels no longer fit with large system text. */}
        <View style={{ flexDirection: fontScale >= 1.3 ? 'column' : 'row', gap: 8 }}>
          <View style={{ flex: fontScale >= 1.3 ? undefined : 1.3 }}><ScannerButton title="Camera" variant="white" disabled={picking} icon={<Camera size={20} color={C.deep} />} onPress={() => void pick('composition', true)} /></View>
          <View style={{ flex: fontScale >= 1.3 ? undefined : 1 }}><ScannerButton title="Uploaden" variant="dark-outline" disabled={picking} icon={<Upload size={19} color="#fff" />} onPress={() => void pick('composition', false)} /></View>
        </View>
        <Pressable accessibilityRole="button" onPress={() => { setExampleType('ingredients'); setExampleOpen(true); }} style={{ minHeight: 44, justifyContent: 'center' }}>
          <Text style={[s.small, { color: '#fff', textDecorationLine: 'underline' }]}>Klik hier voor een voorbeeld van een ingrediëntenlijst.</Text>
        </Pressable>
      </View>
      <View style={{ flexDirection: 'row', alignItems: 'center', gap: 12, paddingHorizontal: 8, paddingVertical: 4 }}><View style={{ height: 1, backgroundColor: 'rgba(27,42,42,0.15)', flex: 1 }} /><Text style={s.small}>of</Text><View style={{ height: 1, backgroundColor: 'rgba(27,42,42,0.15)', flex: 1 }} /></View>
      <Pressable accessibilityRole="button" accessibilityLabel="Zoek op merk of productnaam" onPress={() => setScreen('search')} style={[s.row, { minHeight: 56, paddingHorizontal: 16, borderColor: 'rgba(27,42,42,0.15)' }]}><Search size={20} color={C.teal} /><Text style={[s.body, { fontSize: 17, color: C.muted }]}>Zoek op merk of productnaam</Text></Pressable>
      <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginTop: 10 }}><Text style={s.eyebrow}>Laatst gescand</Text><Pressable accessibilityRole="button" onPress={() => router.push('/(tabs)/scanner/history')} style={{ minHeight: 44, minWidth: 44, alignItems: 'flex-end', justifyContent: 'center' }}><Text style={[s.body, { color: C.teal, fontFamily: 'SourceSans3_700Bold' }]}>Alles »</Text></Pressable></View>
      <View style={{ gap: 8 }}>{scans.slice(0, 3).map(scan => <ScanRow key={scan.id} scan={scan} compact />)}{!scans.length && <View style={s.card}><Text style={s.rowTitle}>Hier verschijnen straks jouw scans!</Text><Text style={s.small}>Scan je eerste voer en ontdek hoe de samenstelling scoort.</Text></View>}</View>
    </>}
    {screen === 'search' && <>
      <Text style={[s.eyebrow, { marginTop: 6 }]}>{query.trim() ? `${filtered.length} resultaten` : 'Zoek in de voercatalogus'}</Text>
      {filtered.map(product => <Pressable key={product.id} accessibilityRole="button" onPress={() => { setSelected(product); setScreen('found'); }} style={s.row}>
        <FeedIcon /><View style={{ flex: 1, gap: 2 }}><Text style={s.small}>{product.brand}</Text><Text style={s.rowTitle}>{product.name}</Text><Text style={s.small}>{feedCategory(product.category)}</Text></View><ChevronRight size={18} color={C.muted} />
      </Pressable>)}
      {!filtered.length && <View style={{ padding: 16, gap: 6 }}><Text style={s.heading}>{query.trim() ? 'Geen voer gevonden' : 'Nog geen producten beschikbaar'}</Text><Text style={s.body}>Controleer de spelling, of voeg foto’s van het etiket toe.</Text></View>}
      {!!query.trim() && <Pressable accessibilityRole="button" onPress={() => { resetScan(); setName(query.trim()); setUnknownFeed(true); setVariant(false); setScreen('photos'); }} style={[s.row, { borderStyle: 'dashed', borderColor: C.teal, backgroundColor: C.pale, padding: 16 }]}>
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
      <ScannerStepProgress step={candidate || photoStep === 'composition' ? 1 : photoStep === 'analysis' ? 2 : 3} />
      <View style={{ paddingHorizontal: 4, paddingBottom: 6 }}>
        <Text style={[s.title, { fontSize: 28, lineHeight: 31 }]}>{candidate ? 'Is dit jouw voer?' : unreadable ? 'Maak een nieuwe foto' : photoStep === 'analysis' ? 'Geen zekere productmatch' : photoStep === 'front' ? 'Nog één foto!' : 'Scan de ingrediëntenlijst'}</Text>
        <Text style={[s.body, { color: C.muted, marginTop: 8 }]}>{candidate ? 'Dit is het voer dat we hebben gedetecteerd. Klopt dat? Een overeenkomst in ingrediënten betekent niet automatisch dat het recept gelijk is.' : unreadable ? 'We konden de tekst op deze foto niet lezen. Maak een scherpe foto van de volledige ingrediëntenlijst, zonder schittering.' : photoStep === 'analysis' ? 'We herkennen dit voer op dit moment nog niet. Maak twee extra foto’s zodat we het voer kunnen analyseren en je een voorlopige beoordeling kunnen geven.' : photoStep === 'front' ? 'Maak een foto van de voorkant van de verpakking en type hieronder het merk en de productnaam.' : 'Maak een foto van het gedeelte op de verpakking waar alle ingrediënten staan vermeld. Zoek op de zak naar “Samenstelling” of “Ingrediënten”.'}</Text>
      </View>
      {candidate && <View style={s.card}><Text style={s.small}>{recognition?.recognition?.brand}</Text><Text style={s.heading}>{recognition?.recognition?.name}</Text><Text style={s.eyebrow}>Ingrediëntenlijst van dit product</Text><Text style={s.body}>{recognition?.recognition?.reference_composition}</Text><Text style={s.body}>Controleer ook merk, productnaam, verhoudingen, toevoegingen en voedingswaarden op jouw verpakking. Dezelfde productnaam bewijst niet dat het recept gelijk is. Bij twijfel of een verschil kies je voor aanvullende foto’s.</Text><ScannerButton title="Ik twijfel · aanvullende foto’s toevoegen" variant="outline" disabled={sending} onPress={() => void chooseKnown('changed')} /></View>}
      {!candidate && photoStep !== 'composition' && <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8, paddingHorizontal: 4 }}><CheckCircle2 size={18} color={C.teal} /><Text style={[s.small, { flex: 1 }]}>Ingrediëntenlijst ontvangen</Text><Pressable accessibilityRole="button" disabled={busy} onPress={() => setReplaceKey('composition')} style={{ minHeight: 44, justifyContent: 'center' }}><Text style={[s.small, { color: C.teal }]}>Vervangen</Text></Pressable></View>}
      {!candidate && photoStep === 'front' && <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8, paddingHorizontal: 4 }}><CheckCircle2 size={18} color={C.teal} /><Text style={[s.small, { flex: 1 }]}>Analysefoto ontvangen</Text><Pressable accessibilityRole="button" disabled={busy} onPress={() => { setPhotoStep('analysis'); setReplaceKey('analysis'); }} style={{ minHeight: 44, justifyContent: 'center' }}><Text style={[s.small, { color: C.teal }]}>Vervangen</Text></Pressable></View>}
      {!candidate && <ScannerPhotoCard title={photoStep === 'analysis' ? 'Fotografeer de analyse' : photoStep === 'front' ? 'Voorkant verpakking' : 'Ingrediëntenlijst'}
        detail={photoStep === 'analysis' ? 'Fotografeer de lijst waarop alle voedingswaarden, zoals suiker, zetmeel, eiwit, vet en ruwe celstof staan.' : photoStep === 'front' ? 'Zorg dat het merk en de productnaam zichtbaar zijn.' : unreadable ? 'Probeer het opnieuw met een beter leesbare foto.' : 'De volledige lijst, inclusief eventuele toevoegingen.'}
        uri={photos[photoStep]?.uri} submissionId={recognition?.id} photoId={recognition?.photo_ids?.[photoStep]} phase={uploadingKey === photoStep ? 'uploading' : pickingKey === photoStep ? 'selecting' : unreadable && photoStep === 'composition' ? 'empty' : hasPhoto(photoStep) ? 'selected' : 'empty'} disabled={busy}
        onCamera={() => void pick(photoStep, true)} onUpload={() => void pick(photoStep, false)} onReplace={() => setReplaceKey(photoStep)} />}
      {!candidate && photoStep === 'analysis' && <Pressable accessibilityRole="button" onPress={() => { setExampleType('analysis'); setExampleOpen(true); }} style={{ minHeight: 44, justifyContent: 'center', paddingHorizontal: 4 }}><Text style={[s.small, { color: C.teal, textDecorationLine: 'underline' }]}>Klik hier voor een voorbeeld van een analyse.</Text></Pressable>}
      {!candidate && photoStep === 'front' && <View style={{ gap: 6 }}><Text style={[s.small, { fontFamily: 'SourceSans3_700Bold' }]}>Merk en productnaam</Text><View style={{ backgroundColor: '#fff', borderWidth: 1, borderRadius: 14, borderColor: focused ? C.mint : C.border }}><TextInput editable={!busy} value={name} maxLength={255} onChangeText={setName} placeholder="Vitalbix Nutrimash" accessibilityLabel="Merk en productnaam" onFocus={() => setFocused(true)} onBlur={() => setFocused(false)} style={[s.input, Platform.OS === 'web' ? { outlineStyle: 'none' } as any : undefined]} /></View></View>}
      <PhotoReplacementSheet visible={replaceKey !== null} onClose={() => setReplaceKey(null)}
        onCamera={() => { const key = replaceKey; setReplaceKey(null); if (key) void pick(key, true); }}
        onUpload={() => { const key = replaceKey; setReplaceKey(null); if (key) void pick(key, false); }} />
    </>}
    <Modal visible={exampleOpen} transparent animationType="fade" onRequestClose={() => setExampleOpen(false)}>
      <View style={{ flex: 1, backgroundColor: 'rgba(13,42,42,0.65)', alignItems: 'center', justifyContent: 'center', padding: 20 }}>
        <View accessibilityViewIsModal style={{ width: '100%', maxWidth: exampleType === 'analysis' ? 600 : 900, padding: 16, gap: 14, backgroundColor: C.canvas, borderRadius: 20 }}>
          <View style={{ flexDirection: 'row', alignItems: 'center', gap: 12 }}><Text style={[s.heading, { flex: 1 }]}>{exampleType === 'analysis' ? 'Voorbeeld analyse' : 'Voorbeeld ingrediëntenlijst'}</Text><RoundButton label="Voorbeeld sluiten" onPress={() => setExampleOpen(false)}><X size={22} color={C.ink} /></RoundButton></View>
          <View style={[{ width: '100%', backgroundColor: '#fff', borderRadius: 8, overflow: 'hidden' }, exampleType === 'analysis' ? { height: Math.min(480, viewportHeight * 0.5) } : { aspectRatio: 1599 / 199 }]}><Image source={exampleType === 'analysis' ? require('@/assets/images/scanner-analysis-example.jpg') : require('@/assets/images/scanner-ingredients-example.jpg')} accessibilityLabel={exampleType === 'analysis' ? 'Voorbeeld van analytische bestanddelen met voedingswaarden' : 'Echte ingrediëntenlijst met het kopje Samenstelling'} resizeMode="contain" style={{ width: '100%', height: '100%' }} /></View>
          <Text style={s.body}>{exampleType === 'analysis' ? 'Fotografeer de lijst waarop alle voedingswaarden, zoals suiker, zetmeel, eiwit, vet en ruwe celstof staan.' : 'Zoek op de zak naar “Samenstelling” of “Ingrediënten”. Fotografeer de volledige lijst, inclusief eventuele toevoegingen.'}</Text>
          <ScannerButton title="Sluiten" variant="outline" onPress={() => setExampleOpen(false)} />
        </View>
      </View>
    </Modal>
  </ScannerFrame>;
}

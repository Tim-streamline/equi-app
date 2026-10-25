import { createElement, useState } from 'react';
import { Platform, Pressable, ScrollView, Text, View } from 'react-native';
import { Redirect } from 'expo-router';
import { useScanResults } from '@/db/hooks';
import { recentScans } from '@/lib/scanner-presentation';
import { C, FeedIcon, ScannerButton, ScannerFrame, ScanScore, s } from '@/components/scanner/ScannerUI';
import { PhotoReplacementSheet, ScannerPhotoCard, type PhotoPhase } from '@/components/scanner/ScannerPhotoCard';
import { AnalyticalConstituents, FindingsCard, MissingDataCard, SugarStarchCard } from '@/components/scanner/ScannerAnalysisStates';
import { ScannerCameraOverlay, ScannerProcessingState } from '@/components/scanner/ScannerProcessingStates';

const STATES = [
  ['start', 'Leeg / start'], ['search', 'Zoeken'], ['camera', 'Camera'], ['lookup', 'Opzoeken'], ['found', 'Product gevonden'],
  ['unknown', 'Product onbekend'], ['uploading', 'Uploaden bezig'], ['blurry', 'Onscherpe foto'], ['uploadError', 'Uploadfout'],
  ['analysing', 'Analyse bezig'], ['provisional', 'Voorlopige analyse'], ['final', 'Definitieve analyse'], ['missing', 'Waarde niet herkend'],
  ['puregras', 'Pure grasbrok · zetmeel niet vermeld'], ['nvmix', 'Samengesteld · zetmeel niet vermeld'], ['history', 'Scanhistorie'],
  ['replace', 'Foto vervangen'], ['variant', 'Andere samenstelling'],
] as const;
type State = typeof STATES[number][0];
const LIVE: Partial<Record<State, string>> = { start: '/scanner?designState=start', search: '/scanner?designState=search', found: '/scanner?designState=found', unknown: '/scanner?designState=unknown', history: '/scanner/history', variant: '/scanner?designState=variant' };

// Opt-in local design inspector. It cannot be opened in normal production builds.
export default function ScannerDesignPreview() {
  if (Platform.OS !== 'web' || process.env.EXPO_PUBLIC_SCANNER_DESIGN_PREVIEW !== 'true') return <Redirect href="/(tabs)/scanner" />;
  return <DesignInspector />;
}

function DesignInspector() {
  const [state, setState] = useState<State>('unknown');
  const [sheet, setSheet] = useState(false);
  const [step, setStep] = useState(0);
  const scans = recentScans(useScanResults());
  const live = state === 'final' && scans[0] ? `/scanner/result?id=${encodeURIComponent(scans[0].id)}` : LIVE[state];
  const isUpload = ['uploading', 'blurry', 'uploadError', 'replace'].includes(state);
  const isResult = ['provisional', 'final', 'missing', 'puregras', 'nvmix'].includes(state);
  const phase: PhotoPhase = state === 'uploading' ? 'uploading' : state === 'blurry' ? 'blurry' : state === 'uploadError' ? 'error' : 'selected';
  return <ScrollView style={{ flex: 1, backgroundColor: C.canvas }} contentContainerStyle={{ padding: 24, alignItems: 'center' }}>
    <View style={{ maxWidth: 1100, width: '100%', gap: 20 }}>
      <View style={[s.note, { gap: 4 }]}><Text style={s.heading}>Ontwerpcontrole voerscanner</Text><Text style={s.body}>Alleen lokaal ingeschakeld. De toestanden hieronder zijn vormvoorbeelden, geen echte uploads of nieuwe beoordelingen. De gewone schermen gebruiken de eigen catalogus en scanhistorie.</Text></View>
      <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 32, justifyContent: 'center', alignItems: 'flex-start' }}>
        <View style={{ width: 390, height: 844, maxWidth: '100%', borderRadius: 32, overflow: 'hidden', borderWidth: 8, borderColor: '#0E2A2A', backgroundColor: C.canvas }}>
          {live ? createElement('iframe', { key: live, title: 'Scannerontwerp met echte gegevens', src: live, style: { border: 0, width: '100%', height: '100%' } })
            : state === 'camera' ? <ScannerCameraOverlay title="Voorkant fotograferen" hint="Zorg dat alle tekst goed leesbaar in beeld is." onClose={() => setState('start')} />
              : state === 'lookup' || state === 'analysing' ? <ScannerProcessingState title={state === 'lookup' ? 'We zoeken je voer op…' : 'We bekijken de samenstelling van je voer…'} steps={state === 'analysing' ? ['Ingrediënten worden herkend', 'Voedingswaarden worden gecontroleerd', 'We stellen je voeranalyse samen'] : []} activeStep={step} />
                : isUpload ? <ScannerFrame title="" showHistory={false} onBack={() => setState('unknown')} footer={<View style={{ paddingBottom: 12 }}><ScannerButton title="Ontwerpvoorbeeld · niet verzonden" disabled /></View>}>
                  <Text style={[s.title, { fontSize: 28, lineHeight: 31 }]}>Dit voer kennen we nog niet!</Text>
                  <Text style={s.small}>Vormcontrole van de fotokaarten. Er is geen product of bestand toegevoegd.</Text>
                  <ScannerPhotoCard title="Foto 1 · Samenstelling" detail="De volledige ingrediëntenlijst zoals op de verpakking." required phase="selected" onReplace={() => setSheet(true)} />
                  <ScannerPhotoCard title="Foto 2 · Analyse" detail="Analytische bestanddelen en, waar mogelijk, vitaminen en mineralen." required phase={phase} onReplace={() => setSheet(true)} onCamera={() => setState('camera')} onRetry={() => setState('uploading')} onUseAnyway={() => setState('replace')} />
                </ScannerFrame>
                  : isResult ? <ScannerFrame title="Voeranalyse" onBack={() => setState('start')}>
                    <View style={s.card}>
                      <View style={{ flexDirection: 'row', gap: 14, alignItems: 'center' }}><FeedIcon size={64} /><View style={{ flex: 1 }}><Text style={s.small}>Vormvoorbeeld · geen productgegevens</Text><Text style={s.heading}>Productnaam</Text></View></View>
                      {state === 'provisional' && <View style={{ alignSelf: 'flex-start', borderRadius: 30, borderWidth: 1, borderColor: C.border, paddingHorizontal: 10, paddingVertical: 4 }}><Text style={s.small}>Voorlopige beoordeling</Text></View>}
                      <View style={{ flexDirection: 'row', gap: 16, alignItems: 'center', borderTopWidth: 1, borderColor: C.border, paddingTop: 18 }}><ScanScore value={null} size={92} /><View style={{ flex: 1 }}><Text style={[s.heading, { fontSize: 22 }]}>Nog geen rapportcijfer</Text><Text style={s.small}>Dit vormvoorbeeld kent geen cijfer toe.</Text></View></View>
                      <Text style={s.small}>Deze score beoordeelt de samenstelling van het voer volgens de voedingsprincipes van De Paardentherapeut en houdt geen rekening met de individuele behoeften van jouw paard.</Text>
                    </View>
                    {state === 'missing' && <MissingDataCard items={[{ label: 'Zetmeel niet herkend', detail: 'Vormvoorbeeld van een ontbrekende waarde die opnieuw kan worden aangeleverd.', availability: 'unread' }]} onPhoto={() => setState('unknown')} />}
                    {state === 'nvmix' && <MissingDataCard items={[{ label: 'Zetmeel niet vermeld', detail: 'Zonder deze waarde is het totaal niet exact te berekenen.', availability: 'not_declared' }]} onPhoto={() => setState('unknown')} />}
                    <SugarStarchCard availability={state === 'missing' ? 'unread' : state === 'nvmix' || state === 'puregras' ? 'not_declared' : 'unknown'} summaryLabel={state === 'puregras' ? 'Zetmeel: niet vermeld' : undefined} explanation={state === 'puregras' ? 'Het totaal is niet exact bekend. Er wordt geen laag zetmeelgehalte aangenomen.' : undefined} />
                    <FindingsCard heading="Positief" items={['Hier verschijnt een aangeleverde bevinding. Dit is geen inhoudelijk productoordeel.']} tone="positive" />
                    <FindingsCard heading="Verdient aandacht" items={['Hier verschijnt een aangeleverd aandachtspunt. Dit is geen inhoudelijk productoordeel.']} tone="attention" />
                    <AnalyticalConstituents rows={['Ruw eiwit', 'Ruw vet', 'Ruwe celstof', 'Ruwe as', 'Suiker', 'Zetmeel'].map(label => ({ label, availability: label !== 'Zetmeel' ? 'unknown' : state === 'missing' ? 'unread' : state === 'nvmix' || state === 'puregras' ? 'not_declared' : 'unknown' }))} />
                  </ScannerFrame> : null}
        </View>
        <View style={{ width: 310, maxWidth: '100%', gap: 14 }}>
          <View style={[s.card, { borderRadius: 18 }]}><Text style={s.heading}>Foto van de voorkant</Text><ScannerButton title="Wordt herkend" variant="outline" onPress={() => setState('found')} /><ScannerButton title="Niet herkend" variant="outline" onPress={() => setState('unknown')} /><Text style={s.rowTitle}>Na uploaden van onbekend voer</Text><ScannerButton title="Voldoende gegevens" variant="outline" onPress={() => setState('provisional')} /><ScannerButton title="Gegevens ontbreken" variant="outline" onPress={() => setState('missing')} /><Text style={s.heading}>Schermen en toestanden</Text><View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 6 }}>{STATES.map(([key, title]) => <Pressable key={key} accessibilityRole="button" accessibilityState={{ selected: state === key }} onPress={() => { setState(key); setSheet(key === 'replace'); }} style={{ minHeight: 36, borderRadius: 20, paddingHorizontal: 12, paddingVertical: 7, borderWidth: 1, borderColor: C.border, backgroundColor: state === key ? C.deep : '#fff' }}><Text style={[s.small, { color: state === key ? '#fff' : C.ink, fontFamily: 'SourceSans3_600SemiBold' }]}>{title}</Text></Pressable>)}</View></View>
          <View style={[s.note, { gap: 8 }]}><Text style={s.rowTitle}>Status van deze toestand</Text><Text style={s.body}>{live ? state === 'final' ? 'Dit is een echte eerdere analyse; niet opnieuw berekend en niet als definitieve nieuwe beoordeling gelabeld.' : 'Het echte appscherm met eigen gegevens. Ontwerpstand is alleen in deze lokale build beschikbaar.' : 'Gedeelde visuele componenten zijn voorbereid. De technische herkenning, upload, scherptecontrole en beoordeling zijn nog niet aangesloten.'}</Text></View>
          {state === 'analysing' && <View style={s.card}><Text style={s.rowTitle}>Voortgang handmatig bekijken</Text>{[0, 1, 2].map(i => <ScannerButton key={i} title={`Stap ${i + 1}`} variant="outline" onPress={() => setStep(i)} />)}</View>}
          {(state === 'puregras' || state === 'nvmix') && <Text style={s.small}>“Niet vermeld” is neutraal. Er wordt geen laag zetmeelgehalte aangenomen en geen voedingsregel uit het HTML-voorbeeld overgenomen.</Text>}
          <Text style={s.small}>De systeemcamera blijft in de echte app actief. De cameravorm hier is een overlay zonder camerastream; de opnameknoppen zijn daarom uitgeschakeld.</Text>
        </View>
      </View>
    </View>
    <PhotoReplacementSheet visible={sheet} onClose={() => setSheet(false)} onCamera={() => setSheet(false)} onUpload={() => setSheet(false)} />
  </ScrollView>;
}

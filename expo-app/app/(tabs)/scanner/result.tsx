import { useState } from 'react';
import { Pressable, Text, View } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
import { BookOpen, ChevronDown, ChevronRight, ChevronUp, Leaf } from 'lucide-react-native';
import { useScanIngredients, useScanResult } from '@/db/hooks';
import { legacyScanScore, scanScoreLabel } from '@/lib/scanner-presentation';
import { C, FeedIcon, ScannerButton, ScannerFrame, ScannerStepProgress, ScanScore, s } from '@/components/scanner/ScannerUI';
import { AnalyticalConstituents, MissingDataCard, SugarStarchCard } from '@/components/scanner/ScannerAnalysisStates';
import { useFeedScanResult } from '@/hooks/useFeedScanResult';
import { FeedScanResult } from '@/components/scanner/FeedScanResult';

const DOT: Record<string, string> = { good: C.success, warn: C.warning, danger: C.danger };

export default function ScannerResultScreen() {
  const { id } = useLocalSearchParams<{ id?: string }>();
  const scan = useScanResult(id ?? '');
  const ingredients = useScanIngredients(id ?? '');
  const [expanded, setExpanded] = useState(false);
  const score = legacyScanScore(scan?.score);
  const remote = useFeedScanResult(id ?? '');
  if (remote.loading) return <ScannerFrame title="Voeranalyse"><Text style={s.body}>Beoordeling ophalen…</Text></ScannerFrame>;
  if (remote.result) return <ScannerFrame title="Voeranalyse"><ScannerStepProgress step={4} /><FeedScanResult result={remote.result} /><ScannerButton title="Ander voer bekijken" variant="outline" onPress={() => router.navigate('/(tabs)/scanner')} /></ScannerFrame>;
  if (['Voorlopig', 'Gecontroleerd'].includes(scan?.rating ?? '')) return <ScannerFrame title="Voeranalyse"><Text style={s.heading}>Beoordeling ophalen</Text><Text style={s.body}>{remote.error || 'Maak verbinding om de volledige bevindingen bij deze inzending te bekijken.'}</Text><ScannerButton title="Naar scanhistorie" onPress={() => router.replace('/(tabs)/scanner/history')} /></ScannerFrame>;
  return <ScannerFrame title="Voeranalyse">
    {!!remote.error && <Text accessibilityRole="alert" style={s.small}>{remote.error}</Text>}
    {!scan ? <><View style={[s.card, { marginTop: 12 }]}><Text style={s.heading}>Geen analyse gevonden</Text><Text style={s.body}>Deze analyse is nog niet beschikbaar. Open een eerdere scan vanuit je scanhistorie.</Text></View><ScannerButton title="Bekijk scanhistorie" onPress={() => router.replace('/(tabs)/scanner/history')} /></> : <>
      <View style={s.card}>
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 14 }}><FeedIcon size={64} /><View style={{ flex: 1, gap: 3 }}><Text style={s.small}>{scan.brand}</Text><Text style={s.heading}>{scan.productName || 'Voeranalyse'}</Text></View></View>
        <View style={{ alignSelf: 'flex-start', borderRadius: 30, borderWidth: 1, borderColor: C.border, paddingHorizontal: 10, paddingVertical: 4 }}><Text style={[s.small, { fontFamily: 'SourceSans3_600SemiBold' }]}>Eerdere beoordeling</Text></View>
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 16, borderTopWidth: 1, borderColor: C.border, paddingTop: 18, marginTop: 4 }}>
          <ScanScore value={scan.score} size={92} /><View style={{ flex: 1, gap: 3 }}><Text style={s.small}>{score === null ? 'Nog geen rapportcijfer' : `Rapportcijfer · ${scanScoreLabel(scan.score)} van 10`}</Text><Text style={[s.heading, { fontSize: 22 }]}>{scan.rating || (score === null ? 'Nog geen beoordeling' : 'Opgeslagen beoordeling')}</Text></View>
        </View>
        <Text style={s.small}>Deze score beoordeelt de samenstelling van het voer volgens de voedingsprincipes van De Paardentherapeut en houdt geen rekening met de individuele behoeften van jouw paard.</Text>
        {!!scan.advice && <Text style={s.body}>{scan.advice}</Text>}
        <Text style={s.small}>Dit is de opgeslagen eerdere beoordeling. Deze is niet opnieuw berekend met de nieuwe beoordelingsregels.</Text>
      </View>
      {score === null && <MissingDataCard items={[{ label: 'Rapportcijfer niet beschikbaar', detail: 'Bij deze eerdere scan is geen geldig rapportcijfer opgeslagen.', availability: 'unknown' }]} />}
      <SugarStarchCard explanation="Bij deze eerdere scan zijn geen gestructureerde suiker- en zetmeelwaarden beschikbaar. We vullen geen ontbrekende waarden in." />
      <View style={[s.card, { borderRadius: 20, padding: 0, overflow: 'hidden' }]}>
        <Pressable accessibilityRole="button" accessibilityState={{ expanded }} onPress={() => setExpanded(value => !value)} style={{ minHeight: 62, padding: 18, flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 12 }}><Text style={[s.rowTitle, { fontSize: 17 }]}>Ingrediënten ({ingredients.length})</Text>{expanded ? <ChevronUp size={20} color={C.muted} /> : <ChevronDown size={20} color={C.muted} />}</Pressable>
        {expanded && <View style={{ paddingHorizontal: 18, paddingBottom: 18, gap: 14 }}>{ingredients.length ? ingredients.map(ingredient => <View key={ingredient.id} style={{ flexDirection: 'row', gap: 10, borderTopWidth: 1, borderColor: C.border, paddingTop: 12 }}><View style={{ width: 8, height: 8, borderRadius: 4, marginTop: 7, backgroundColor: DOT[ingredient.tag] ?? C.muted }} /><View style={{ flex: 1, gap: 4 }}><Text style={s.rowTitle}>{ingredient.name}</Text>{!!ingredient.description && <Text style={s.body}>{ingredient.description}</Text>}</View></View>) : <Text style={s.small}>Er zijn geen ingrediënten opgeslagen bij deze scan.</Text>}</View>}
      </View>
      <AnalyticalConstituents rows={[]} />
      <Text style={[s.eyebrow, { marginTop: 8 }]}>Lees verder in de bibliotheek</Text>
      <Pressable accessibilityRole="button" onPress={() => router.navigate('/(tabs)/(pager)/library')} style={s.row}><View style={{ backgroundColor: C.deep, borderRadius: 12, padding: 13 }}><BookOpen size={22} color="#fff" /></View><View style={{ flex: 1 }}><Text style={s.rowTitle}>Meer over paardenvoeding</Text><Text style={s.small}>Ontdek de artikelen en video’s</Text></View><ChevronRight size={18} color={C.muted} /></Pressable>
      {!!scan.productId && <ScannerButton title="Heeft jouw verpakking een andere samenstelling?" variant="text" onPress={() => router.push({ pathname: '/(tabs)/scanner', params: { mode: 'variant', productId: scan.productId } })} />}
      <ScannerButton title="Ander voer bekijken" variant="outline" icon={<Leaf size={19} color={C.teal} />} onPress={() => router.navigate('/(tabs)/scanner')} />
    </>}
  </ScannerFrame>;
}

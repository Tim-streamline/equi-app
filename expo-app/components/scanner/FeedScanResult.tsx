import { Text, View } from 'react-native';
import type { FeedResult } from '@/lib/feed-scanner-api';
import { C, ScanScore, s } from './ScannerUI';
import { AnalyticalConstituents, FindingsCard, MissingDataCard } from './ScannerAnalysisStates';

const nutrients: Record<string, string> = { sugar: 'Suiker', starch: 'Zetmeel', moisture: 'Vocht', dry_matter: 'Droge stof', protein: 'Ruw eiwit', fibre: 'Ruwe celstof', fat: 'Ruw vet', ash: 'Ruwe as', calcium: 'Calcium', phosphorus: 'Fosfor', selenium_total: 'Selenium totaal' };
const qualifiers: Record<string, string> = { exact: '', less_than: '< ', greater_than: '> ', minimum: 'Minimaal ', maximum: 'Maximaal ' };
export function FeedScanResult({ result }: { result: FeedResult }) {
  return <>
    <View style={s.card}>
      <Text style={s.small}>{result.brand}</Text><Text style={s.heading}>{result.name}</Text>
      <Text style={[s.eyebrow, { color: C.teal }]}>{result.provisional ? 'Voorlopige beoordeling' : 'Gecontroleerde samenstelling'}</Text>
      <View style={{ flexDirection: 'row', alignItems: 'center', gap: 16 }}><ScanScore value={result.score === null ? null : Number(result.score) * 10} size={92} /><View style={{ flex: 1 }}><Text style={s.heading}>{result.score === null ? 'Nog geen rapportcijfer' : `${Number(result.score).toLocaleString('nl-NL')}/10`}</Text>{result.score_cap !== null && <Text style={s.small}>Geldend scoreplafond: {Number(result.score_cap).toLocaleString('nl-NL')}/10</Text>}</View></View>
      <Text style={s.small}>Deze score beoordeelt de samenstelling van het voer volgens de voedingsprincipes van De Paardentherapeut en houdt geen rekening met de individuele behoeften van jouw paard.</Text>
      <Text style={s.body}>{result.message}</Text>
    </View>
    <FindingsCard heading="Bevindingen volgens de beoordelingsregels" items={result.findings.map(f => f.message)} tone="attention" />
    <MissingDataCard items={result.missing_data.map(text => ({ label: text, detail: 'Wordt gecontroleerd bij beoordeling.', availability: 'unknown' as const }))} />
    <View style={s.card}><Text style={s.heading}>Ingrediënten</Text>{result.ingredients.map((i, n) => <View key={n} style={{ borderTopWidth: 1, borderColor: C.border, paddingTop: 12, gap: 4 }}><Text style={s.rowTitle}>{i.label}</Text><Text style={s.small}>{i.match_status === 'confirmed' ? ({ preferred: 'Voorkeur', conditional: 'Onder voorwaarden', critical: 'Aandachtspunt', unrated: 'Nog niet beoordeeld' }[i.assessment] ?? 'Kenniskaart') : 'Kenniskaart nog te koppelen'}</Text>{!!i.explanation && <Text style={s.body}>{i.explanation}</Text>}{!!i.exceptions && <Text style={s.small}>{i.exceptions}</Text>}</View>)}{!result.ingredients.length && <Text style={s.small}>De ingrediënten konden nog niet betrouwbaar worden uitgelezen.</Text>}</View>
    <AnalyticalConstituents rows={result.nutrients.map((n, i) => ({ label: `${nutrients[n.code] ?? n.code}${result.nutrients.filter(r => r.code === n.code).length > 1 ? ` (${i + 1})` : ''}`, value: `${qualifiers[n.qualifier] ?? ''}${n.value} ${n.unit}`, availability: 'known', note: n.basis === 'product' ? 'Op productbasis' : n.basis === 'dry_matter' ? 'Op droge-stofbasis' : 'Analysebasis niet bekend; niet gebruikt voor standaardvergelijking.' }))} />
  </>;
}

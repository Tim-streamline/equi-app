import { useState } from 'react';
import { Pressable, Text, View } from 'react-native';
import { Check, ChevronDown, ChevronUp, CircleAlert } from 'lucide-react-native';
import { C, ScannerButton, s } from './ScannerUI';

export type NutrientAvailability = 'known' | 'not_declared' | 'unread' | 'unknown';
export type NutrientDisplayRow = { label: string; value?: string; availability: NutrientAvailability; note?: string };

// These views display supplied facts only. They never calculate a score or infer
// a product type, analysis basis, missing value or nutritional conclusion.
export function SugarStarchCard({ availability = 'unknown', total, sugar, starch, explanation, policyMessage, onPhoto, summaryLabel }: {
  availability?: NutrientAvailability; total?: string; sugar?: string; starch?: string; explanation?: string; policyMessage?: string; onPhoto?: () => void; summaryLabel?: string;
}) {
  return <View style={[s.card, { borderRadius: 20, paddingVertical: 16, gap: 6 }]}>
    <Text style={s.eyebrow}>Suiker + zetmeel</Text>
    <Text style={[s.heading, availability === 'known' && { fontSize: 32, lineHeight: 38, color: C.deep }]}>
      {summaryLabel ?? (availability === 'known' && total ? total : availability === 'not_declared' ? 'Niet exact te berekenen' : availability === 'unread' ? 'Nog niet bekend' : 'Niet beschikbaar')}
    </Text>
    {(sugar || starch) && <Text style={s.small}>Suiker {sugar ?? 'onbekend'} · zetmeel {starch ?? 'onbekend'}</Text>}
    {!!explanation && <Text style={[s.body, { color: C.muted }]}>{explanation}</Text>}
    {!!policyMessage && <Text style={s.body}>{policyMessage}</Text>}
    {availability === 'unread' && onPhoto && <ScannerButton title="Nieuwe foto uploaden" variant="text" onPress={onPhoto} />}
  </View>;
}

export function MissingDataCard({ items, onPhoto }: { items: { label: string; detail: string; availability: 'not_declared' | 'unread' | 'unknown' }[]; onPhoto?: () => void }) {
  if (!items.length) return null;
  return <View style={[s.card, { borderRadius: 20, paddingVertical: 16 }]}>
    <Text style={[s.heading, { fontSize: 17 }]}>Wat nog ontbreekt</Text>
    {items.map((item, i) => <View key={`${item.label}-${i}`} style={{ flexDirection: 'row', gap: 12, borderBottomWidth: 1, borderColor: C.border, paddingBottom: 12 }}>
      <View style={{ width: 8, height: 8, borderRadius: 4, backgroundColor: item.availability === 'unread' ? C.warning : 'rgba(27,42,42,0.3)', marginTop: 7 }} />
      <View style={{ flex: 1, gap: 2 }}><Text style={s.rowTitle}>{item.label}</Text><Text style={s.small}>{item.detail}</Text></View>
    </View>)}
    {onPhoto && <ScannerButton title={items.some(item => item.availability === 'unread') ? 'Nieuwe foto uploaden' : 'Staat het wel op jouw verpakking? Foto toevoegen'} onPress={onPhoto} />}
  </View>;
}

export function FindingsCard({ heading, items, tone }: { heading: string; items: string[]; tone: 'positive' | 'attention' }) {
  if (!items.length) return null;
  return <View style={[s.card, { borderRadius: 20, paddingVertical: 16, gap: 8 }]}>
    <Text style={[s.heading, { fontSize: 17 }]}>{heading}</Text>
    {items.map((item, i) => <View key={`${i}-${item}`} style={{ flexDirection: 'row', gap: 10, alignItems: 'flex-start' }}>
      {tone === 'positive' ? <Check size={18} color="#1A6B49" /> : <CircleAlert size={18} color="#9A6A1E" />}
      <Text style={[s.body, { flex: 1 }]}>{item}</Text>
    </View>)}
  </View>;
}

export function AnalyticalConstituents({ rows }: { rows: NutrientDisplayRow[] }) {
  const [expanded, setExpanded] = useState(false);
  return <View style={[s.card, { borderRadius: 20, padding: 0, overflow: 'hidden' }]}>
    <Pressable accessibilityRole="button" accessibilityState={{ expanded }} onPress={() => setExpanded(value => !value)} style={{ padding: 18, minHeight: 62, flexDirection: 'row', alignItems: 'center', gap: 8 }}>
      <Text style={[s.rowTitle, { flex: 1, fontSize: 17 }]}>Analytische bestanddelen</Text><Text style={[s.small, { color: C.teal }]}>{expanded ? 'Verbergen' : 'Bekijken'}</Text>{expanded ? <ChevronUp size={18} color={C.muted} /> : <ChevronDown size={18} color={C.muted} />}
    </Pressable>
    {expanded && <View style={{ paddingHorizontal: 18, paddingBottom: 16, gap: 12 }}>
      {rows.length ? rows.map(row => <View key={row.label} style={{ gap: 4 }}>
        <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 12 }}>
          <Text style={s.body}>{row.label}</Text>
          {row.availability === 'known' && row.value ? <Text style={s.body}>{row.value}</Text> : <View style={{ borderRadius: 20, paddingHorizontal: 9, paddingVertical: 2, backgroundColor: row.availability === 'unread' ? 'rgba(217,164,65,0.18)' : '#F4EFE7' }}><Text style={[s.small, { color: row.availability === 'unread' ? '#6E4B12' : C.ink, fontFamily: 'SourceSans3_700Bold', fontSize: 12 }]}>{row.availability === 'not_declared' ? 'Niet vermeld' : row.availability === 'unread' ? 'Niet herkend' : 'Niet beschikbaar'}</Text></View>}
        </View>
        {!!row.note && <Text style={[s.small, { fontStyle: 'italic' }]}>{row.note}</Text>}
      </View>) : <Text style={s.small}>Er zijn geen gestructureerde analysewaarden opgeslagen bij deze scan.</Text>}
    </View>}
  </View>;
}

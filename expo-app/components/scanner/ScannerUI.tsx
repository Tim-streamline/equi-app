import type { ReactNode } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView, useSafeAreaInsets } from 'react-native-safe-area-context';
import { StatusBar } from 'expo-status-bar';
import { router } from 'expo-router';
import { ChevronLeft, BookOpen, ChevronRight, ClipboardList, History, Home, UserRound, ShoppingBag, ScanLine } from 'lucide-react-native';
import { legacyScanScore, scanScoreLabel, type ScannerRow } from '@/lib/scanner-presentation';

export const C = { canvas: '#FBF8F3', ink: '#1B2A2A', muted: 'rgba(27,42,42,0.7)', border: 'rgba(27,42,42,0.08)',
  teal: '#127A79', deep: '#0D5C5B', mint: '#18BAB0', pale: '#EAFBF9', success: '#2EA875', warning: '#D9A441', danger: '#C2543E' };

export function ScannerStepProgress({ step }: { step: 1 | 2 | 3 | 4 }) {
  return <View accessibilityRole="progressbar" accessibilityLabel={`Stap ${step} van 4`} accessibilityValue={{ min: 1, max: 4, now: step }} style={{ gap: 6 }}>
    <Text style={s.small}>Stap {step} van 4 · {['Ingrediëntenlijst', 'Analysefoto', 'Voorkant', 'Resultaat'][step - 1]}</Text>
    <View style={{ flexDirection: 'row', gap: 5 }}>{[1, 2, 3, 4].map(n => <View key={n} style={{ flex: 1, height: 3, borderRadius: 2, backgroundColor: n <= step ? C.teal : C.border }} />)}</View>
  </View>;
}

export function ScannerFrame({ title, onBack, children, navigation = false, header = true, scroll = true, showHistory = true, headerContent, footer, largeTitle = false }: {
  title?: string; onBack?: () => void; children: ReactNode; navigation?: boolean; header?: boolean; scroll?: boolean; showHistory?: boolean; headerContent?: ReactNode; footer?: ReactNode; largeTitle?: boolean;
}) {
  return <View style={s.screen}>
    <StatusBar style="dark" />
    <SafeAreaView edges={['top', 'left', 'right']} style={{ flex: 1 }}>
      {header && <View style={s.header}>
        <RoundButton label="Terug" onPress={onBack ?? (() => router.canGoBack() ? router.back() : router.replace('/(tabs)/scanner'))}><ChevronLeft size={22} color={C.ink} /></RoundButton>
        {headerContent ?? <Text style={[s.headerTitle, largeTitle && { textAlign: 'left', fontSize: 24, fontFamily: 'SourceSans3_700Bold' }]}>{title}</Text>}
        {!headerContent && (showHistory ? <RoundButton label="Scanhistorie" onPress={() => router.push('/(tabs)/scanner/history')}><History size={20} color={C.ink} /></RoundButton> : !largeTitle && <View style={{ width: 44 }} />)}
      </View>}
      {scroll ? <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={s.content}>{children}</ScrollView> : children}
      {footer && <View style={{ paddingHorizontal: 16, paddingTop: 12, gap: 4, backgroundColor: C.canvas }}>{footer}</View>}
      {navigation ? <ScannerNavigation /> : <SafeAreaView edges={['bottom']} />}
    </SafeAreaView>
  </View>;
}

function ScannerNavigation() {
  const insets = useSafeAreaInsets();
  const tabs = [
    { label: 'Home', icon: Home, href: '/(tabs)/(pager)/home' },
    { label: 'Protocol', icon: ClipboardList, href: '/(tabs)/(pager)/protocol' },
    { label: 'Scan', icon: ScanLine, href: '/(tabs)/scanner' },
    { label: 'Bibliotheek', icon: BookOpen, href: '/(tabs)/(pager)/library' },
    { label: 'Account', icon: UserRound, href: '/(tabs)/account' },
  ] as const;
  return <View style={{ flexDirection: 'row', borderTopWidth: 1, borderColor: C.border, backgroundColor: '#fff', paddingTop: 8, paddingBottom: Math.max(insets.bottom, 10) }}>
    {tabs.map(({ label, icon: Icon, href }) => <Pressable key={label} accessibilityRole="button" accessibilityLabel={label} accessibilityState={{ selected: label === 'Scan' }} onPress={() => router.navigate(href)} style={s.navItem}>
      <Icon size={23} color={label === 'Scan' ? C.teal : C.muted} strokeWidth={label === 'Scan' ? 2.5 : 1.8} />
      <Text style={{ fontFamily: 'SourceSans3_600SemiBold', fontSize: 11, color: label === 'Scan' ? C.teal : C.muted }}>{label}</Text>
    </Pressable>)}
  </View>;
}

export function RoundButton({ label, onPress, children }: { label: string; onPress: () => void; children: ReactNode }) {
  // These inline-only controls bypass NativeWind v4, which drops native Pressable style callbacks.
  return <Pressable cssInterop={false} accessibilityRole="button" accessibilityLabel={label} onPress={onPress} style={({ pressed }) => [s.round, { opacity: pressed ? 0.6 : 1 }]}>{children}</Pressable>;
}

export function ScannerButton({ title, onPress, icon, variant = 'primary', disabled = false }: {
  title: string; onPress?: () => void; icon?: ReactNode; variant?: 'primary' | 'outline' | 'white' | 'light' | 'dark-outline' | 'text'; disabled?: boolean;
}) {
  const color = variant === 'primary' || variant === 'dark-outline' ? '#fff' : variant === 'white' ? C.deep : C.teal;
  return <Pressable cssInterop={false} accessibilityRole="button" accessibilityState={{ disabled }} disabled={disabled} onPress={onPress}
    style={({ pressed }) => [s.button, { minHeight: variant === 'text' ? 44 : variant === 'primary' ? 56 : 52, backgroundColor: variant === 'primary' ? C.teal : variant === 'light' ? C.pale : variant === 'dark-outline' || variant === 'text' ? 'transparent' : '#fff', borderWidth: variant === 'outline' || variant === 'dark-outline' ? 1 : 0, borderColor: variant === 'dark-outline' ? 'rgba(255,255,255,0.4)' : 'rgba(27,42,42,0.15)', opacity: disabled ? 0.5 : pressed ? 0.75 : 1 }]}>
    {icon}<Text style={[s.buttonText, { color }]}>{title}</Text>
  </Pressable>;
}

export function FeedIcon({ size = 48 }: { size?: number }) {
  return <View style={{ width: size, height: size, borderRadius: size === 48 ? 12 : 14, backgroundColor: C.pale, alignItems: 'center', justifyContent: 'center' }}><ShoppingBag size={size * 0.48} color={C.teal} strokeWidth={1.6} /></View>;
}

export function ScanScore({ value, size = 48 }: { value: unknown; size?: number }) {
  const score = legacyScanScore(value);
  // Retain the existing UI's 75/50 status bands; never derive a new assessment.
  const color = score === null ? 'rgba(27,42,42,0.3)' : score >= 75 ? C.success : score >= 50 ? C.warning : C.danger;
  return <View accessibilityLabel={score === null ? 'Nog geen rapportcijfer' : `${scanScoreLabel(value)} van 10, eerdere beoordeling`}
    style={{ width: size, height: size, borderRadius: size / 2, borderWidth: score === null ? (size > 60 ? 3 : 2) : size > 60 ? 6 : 3, borderStyle: score === null ? 'dashed' : 'solid', borderColor: color, backgroundColor: score === null ? 'transparent' : `${color}15`, alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}>
    <Text style={{ fontFamily: 'SourceSans3_700Bold', fontSize: size > 60 ? 40 : size === 44 ? 17 : 19, color: score === null ? C.muted : C.ink }}>{scanScoreLabel(value)}</Text>
  </View>;
}

export function ScanRow({ scan, compact = false }: { scan: ScannerRow; compact?: boolean }) {
  return <Pressable cssInterop={false} accessibilityRole="button" accessibilityLabel={`${scan.brand ?? ''} ${scan.productName ?? 'Scan'} bekijken`}
    onPress={() => router.push({ pathname: '/(tabs)/scanner/result', params: { id: scan.id } })} style={({ pressed }) => [s.row, { paddingVertical: compact ? 10 : 12, opacity: pressed ? 0.7 : 1 }]}>
    <ScanScore value={scan.score} size={compact ? 44 : 48} />
    <View style={{ flex: 1, gap: 2 }}><Text style={[s.rowTitle, compact && { fontSize: 15 }]} numberOfLines={2}>{scan.productName || 'Voeranalyse'}</Text><Text style={s.small}>{[scan.brand, scan.whenLabel].filter(Boolean).join(' · ')}</Text></View>
    <ChevronRight size={18} color={C.muted} />
  </Pressable>;
}

export function ScannerNote({ children }: { children: ReactNode }) { return <View style={s.note}><Text style={s.body}>{children}</Text></View>; }

export const s = StyleSheet.create({
  screen: { flex: 1, backgroundColor: C.canvas },
  header: { flexDirection: 'row', alignItems: 'center', paddingHorizontal: 8, paddingVertical: 6, gap: 4 },
  headerTitle: { flex: 1, textAlign: 'center', fontSize: 17, color: C.ink, fontFamily: 'SourceSans3_600SemiBold' },
  content: { padding: 16, paddingTop: 4, paddingBottom: 40, gap: 12, flexGrow: 1 },
  round: { width: 44, height: 44, alignItems: 'center', justifyContent: 'center', borderRadius: 22 },
  navItem: { flex: 1, minHeight: 44, alignItems: 'center', justifyContent: 'center', gap: 3 },
  button: { minHeight: 52, borderRadius: 14, paddingHorizontal: 15, paddingVertical: 12, flexDirection: 'row', justifyContent: 'center', alignItems: 'center', gap: 8 },
  buttonText: { fontSize: 16, fontFamily: 'SourceSans3_600SemiBold', textAlign: 'center', flexShrink: 1 },
  title: { fontSize: 30, lineHeight: 33, color: C.ink, fontFamily: 'SourceSans3_700Bold' },
  heading: { fontSize: 20, lineHeight: 25, color: C.ink, fontFamily: 'SourceSans3_700Bold' },
  body: { fontSize: 15, lineHeight: 22, color: C.ink, fontFamily: 'SourceSans3_400Regular' },
  small: { fontSize: 13, lineHeight: 19, color: C.muted, fontFamily: 'SourceSans3_400Regular' },
  eyebrow: { fontSize: 12, letterSpacing: 1.7, color: C.muted, fontFamily: 'SourceSans3_700Bold', textTransform: 'uppercase' },
  card: { backgroundColor: '#fff', borderWidth: 1, borderColor: C.border, borderRadius: 24, padding: 18, gap: 12 },
  row: { backgroundColor: '#fff', borderWidth: 1, borderColor: C.border, borderRadius: 16, padding: 12, flexDirection: 'row', alignItems: 'center', gap: 12 },
  rowTitle: { fontSize: 16, lineHeight: 20, fontFamily: 'SourceSans3_700Bold', color: C.ink },
  note: { borderRadius: 16, padding: 14, backgroundColor: C.pale },
  input: { flex: 1, minWidth: 0, minHeight: 50, color: C.ink, fontSize: 16, fontFamily: 'SourceSans3_400Regular', paddingHorizontal: 14, paddingVertical: 10 },
});

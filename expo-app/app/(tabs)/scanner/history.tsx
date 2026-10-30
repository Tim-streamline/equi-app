import { FlatList, Text, View } from 'react-native';
import { router } from 'expo-router';
import { ScanLine } from 'lucide-react-native';
import { C, ScannerButton, ScannerFrame, ScanRow, s } from '@/components/scanner/ScannerUI';
import { useScannerHistory } from '@/hooks/useScannerHistory';

export default function ScannerHistoryScreen() {
  const { scans, pending, error } = useScannerHistory();
  return <ScannerFrame title="Scanhistorie" showHistory={false} largeTitle scroll={false}>
    {/* Virtualized: the history only grows. */}
    <FlatList
      data={scans}
      keyExtractor={scan => String(scan.id)}
      contentContainerStyle={[s.content, { gap: 8 }]}
      renderItem={({ item }) => <ScanRow scan={item} />}
      ListHeaderComponent={error || pending.length ? <View style={{ gap: 12, marginBottom: 4 }}>
        {!!error && <Text style={s.small}>{error}</Text>}
        {pending.map(item => <View key={item.id} style={s.card}><Text style={s.rowTitle}>{item.product?.name ?? item.submitted_name ?? 'Ingezonden voer'}</Text><Text style={s.small}>{item.message || (['processing', 'checking'].includes(item.status) ? 'Wordt verwerkt' : 'Ontvangen · nog te verwerken')}</Text><ScannerButton title="Bekijk inzending" variant="outline" onPress={() => item.workflow_version === 2 && ['needs_photos', 'awaiting_composition', 'candidate', 'failed'].includes(item.status) ? router.push({ pathname: '/(tabs)/scanner', params: { submissionId: item.id } }) : router.push({ pathname: '/(tabs)/scanner/processing', params: { id: item.id } })} /></View>)}
      </View> : null}
      ListEmptyComponent={<View style={{ gap: 12 }}>
        <View style={[s.card, { alignItems: 'center', paddingVertical: 30 }]}><ScanLine size={36} color={C.teal} /><Text style={s.heading}>Nog geen scans</Text><Text style={s.small}>Je eerdere analyses verschijnen hier.</Text></View>
        <ScannerButton title="Terug naar de scanner" variant="outline" onPress={() => router.navigate('/(tabs)/scanner')} />
      </View>}
    />
  </ScannerFrame>;
}

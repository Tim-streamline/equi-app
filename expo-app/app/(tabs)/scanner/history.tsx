import { Text, View } from 'react-native';
import { router } from 'expo-router';
import { ScanLine } from 'lucide-react-native';
import { C, ScannerButton, ScannerFrame, ScanRow, s } from '@/components/scanner/ScannerUI';
import { useScannerHistory } from '@/hooks/useScannerHistory';

export default function ScannerHistoryScreen() {
  const { scans, pending, error } = useScannerHistory();
  return <ScannerFrame title="Scanhistorie" navigation showHistory={false} largeTitle>
    {!!error && <Text style={s.small}>{error}</Text>}
    {pending.map(item => <View key={item.id} style={s.card}><Text style={s.rowTitle}>{item.product?.name ?? 'Ingezonden voer'}</Text><Text style={s.small}>{item.message || (item.status === 'processing' ? 'Wordt verwerkt' : 'Ontvangen · nog te verwerken')}</Text><ScannerButton title="Bekijk inzending" variant="outline" onPress={() => router.push({ pathname: '/(tabs)/scanner/processing', params: { id: item.id } })} /></View>)}
    <View style={{ gap: 8 }}>{scans.map(scan => <ScanRow key={scan.id} scan={scan} />)}</View>
    {!scans.length && <View style={[s.card, { alignItems: 'center', paddingVertical: 30 }]}><ScanLine size={36} color={C.teal} /><Text style={s.heading}>Nog geen scans</Text><Text style={s.small}>Je eerdere analyses verschijnen hier.</Text></View>}
    {!scans.length && <ScannerButton title="Terug naar de scanner" variant="outline" onPress={() => router.navigate('/(tabs)/scanner')} />}
  </ScannerFrame>;
}

import { Text, View } from 'react-native';
import { router } from 'expo-router';
import { ScanLine } from 'lucide-react-native';
import { useScanResults } from '@/db/hooks';
import { recentScans } from '@/lib/scanner-presentation';
import { C, ScannerButton, ScannerFrame, ScanRow, s } from '@/components/scanner/ScannerUI';

export default function ScannerHistoryScreen() {
  const scans = recentScans(useScanResults());
  return <ScannerFrame title="Scanhistorie" navigation showHistory={false}>
    <Text style={[s.title, { marginTop: 12 }]}>Jouw eerdere scans</Text>
    <Text style={[s.body, { color: C.muted, marginBottom: 8 }]}>Bekijk het voer dat je eerder hebt gescand.</Text>
    <View style={{ gap: 10 }}>{scans.map(scan => <ScanRow key={scan.id} scan={scan} />)}</View>
    {!scans.length && <View style={[s.card, { alignItems: 'center', paddingVertical: 30 }]}><ScanLine size={36} color={C.teal} /><Text style={s.heading}>Nog geen scans</Text><Text style={s.small}>Je eerdere analyses verschijnen hier.</Text></View>}
    <ScannerButton title="Terug naar de scanner" variant="outline" onPress={() => router.navigate('/(tabs)/scanner')} />
  </ScannerFrame>;
}

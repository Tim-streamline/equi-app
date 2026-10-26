import { useEffect, useState } from 'react';
import { Text, View } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
import { useCurrentUserId } from '@/db/hooks';
import { ScannerFrame, ScannerButton, ScannerStepProgress, s } from '@/components/scanner/ScannerUI';
import { ScannerProcessingState } from '@/components/scanner/ScannerProcessingStates';
import { scannerRequest, type FeedSubmission } from '@/lib/feed-scanner-api';

export default function ScannerProcessingScreen() {
  const { id, start, name } = useLocalSearchParams<{ id: string; start?: string; name?: string }>();
  const user = useCurrentUserId();
  const [state, setState] = useState<FeedSubmission>();
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(true);
  const [retry, setRetry] = useState(0);
  useEffect(() => {
    if (!user || !id) return;
    let alive = true;
    const controller = new AbortController();
    setState(undefined); setError(''); setBusy(true);
    async function run() {
      try {
        let current = await scannerRequest<FeedSubmission>(`/submissions/${id}`, undefined, controller.signal);
        if (retry || (start === '1' && ['draft', 'needs_photos', 'failed'].includes(current.status))) {
          current = await scannerRequest<FeedSubmission>(`/submissions/${id}/${['awaiting_composition', 'checking'].includes(current.status) ? 'check' : 'analyse'}`, name ? { name } : {}, controller.signal);
        }
        if (!alive) return;
        setState(current);
        if (current.status === 'completed' && current.scan_result_id) router.replace({ pathname: '/(tabs)/scanner/result', params: { id: current.scan_result_id } });
        else if (current.workflow_version === 2 && ['awaiting_composition', 'candidate', 'needs_photos'].includes(current.status)) router.replace({ pathname: '/(tabs)/scanner', params: { submissionId: current.id } });
      } catch (e) { if (alive) setError(e instanceof Error ? e.message : 'Verwerken is niet gelukt.'); }
      finally { if (alive) setBusy(false); }
    }
    void run();
    return () => { alive = false; controller.abort(); };
  }, [id, user, retry, start, name]);
  // Status polling also picks up manual approval without another paid inference.
  useEffect(() => {
    if (!['processing', 'checking', 'review_required'].includes(state?.status ?? '')) return;
    const controller = new AbortController();
    const timer = setInterval(() => {
      void scannerRequest<FeedSubmission>(`/submissions/${id}`, undefined, controller.signal).then(current => {
        if (controller.signal.aborted) return;
        setState(current);
        if (current.status === 'completed' && current.scan_result_id) router.replace({ pathname: '/(tabs)/scanner/result', params: { id: current.scan_result_id } });
        else if (current.workflow_version === 2 && ['candidate', 'needs_photos'].includes(current.status)) router.replace({ pathname: '/(tabs)/scanner', params: { submissionId: current.id } });
      }).catch(() => {});
    }, 4000);
    return () => { clearInterval(timer); controller.abort(); };
  }, [id, state?.status]);
  return <ScannerFrame title="Je voeranalyse" showHistory>
    <ScannerStepProgress step={state?.status === 'checking' ? 1 : 4} />
    {busy || ['processing', 'checking'].includes(state?.status ?? '') ? <><ScannerProcessingState title={state?.status === 'checking' ? 'Ingrediëntenlijst controleren' : 'We lezen je etiket uit'} /><Text style={s.small}>Je foto’s zijn ontvangen. Je kunt dit scherm verlaten en de inzending terugvinden in je scanhistorie.</Text></> : <View style={s.card}>
      <Text style={s.heading}>{error ? 'Verbinding onderbroken' : 'Inzending ontvangen'}</Text>
      <Text accessibilityRole="alert" style={s.body}>{error || state?.message || 'Je foto’s staan klaar voor verwerking. Bij ontbrekende foto’s kun je een nieuwe inzending starten.'}</Text>
      {state?.status !== 'review_required' && <ScannerButton title={error ? 'Status opnieuw ophalen / verwerken' : 'Opnieuw verwerken'} onPress={() => setRetry(n => n + 1)} />}
    </View>}
    {!busy && ['processing', 'checking'].includes(state?.status ?? '') && <ScannerButton title="Verwerking opnieuw controleren" variant="outline" onPress={() => setRetry(n => n + 1)} />}
    <ScannerButton title="Naar scanhistorie" variant="outline" onPress={() => router.replace('/(tabs)/scanner/history')} />
  </ScannerFrame>;
}

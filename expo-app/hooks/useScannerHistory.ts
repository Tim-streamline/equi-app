import { useCallback, useState } from 'react';
import { useFocusEffect } from 'expo-router';
import { useCurrentUserId, useScanResults } from '@/db/hooks';
import { scannerRequest, type FeedSubmission } from '@/lib/feed-scanner-api';
import { recentScans, type ScannerRow } from '@/lib/scanner-presentation';

export function useScannerHistory() {
  const user = useCurrentUserId();
  const local = useScanResults();
  const [snapshot, setSnapshot] = useState<{ user: string; scans: ScannerRow[]; pending: FeedSubmission[] }>();
  const [failure, setFailure] = useState<{ user: string; message: string }>();
  useFocusEffect(useCallback(() => {
    const controller = new AbortController();
    if (user) void scannerRequest<{ scans: any[]; pending: FeedSubmission[] }>('/scans', undefined, controller.signal).then(data => {
      if (!controller.signal.aborted) { setSnapshot({ user, scans: data.scans.map(s => ({ ...s, productName: s.product_name, productId: s.product_id, scannedAt: s.scanned_at })), pending: data.pending }); setFailure(undefined); }
    }).catch(e => { if (!controller.signal.aborted) setFailure({ user, message: e.message }); });
    return () => controller.abort();
  }, [user]));
  const remote = snapshot?.user === user ? snapshot : undefined;
  return { scans: recentScans([...new Map([...local, ...(remote?.scans ?? [])].map(s => [s.id, s])).values()]), pending: remote?.pending ?? [], error: failure?.user === user ? failure.message : null };
}

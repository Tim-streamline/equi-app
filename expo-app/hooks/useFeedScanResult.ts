import { useEffect, useState } from 'react';
import { useCurrentUserId } from '@/db/hooks';
import { scannerRequest, type FeedResult } from '@/lib/feed-scanner-api';

export function useFeedScanResult(id: string) {
  const user = useCurrentUserId();
  const key = `${user}:${id}`;
  const [state, setState] = useState<{ key: string; result: FeedResult | null; error?: string }>();
  useEffect(() => {
    if (!id || !user) return;
    const controller = new AbortController();
    void scannerRequest<{ result: FeedResult | null }>(`/scans/${id}`, undefined, controller.signal).then(data => {
      if (!controller.signal.aborted) setState({ key, result: data.result });
    }).catch(e => { if (!controller.signal.aborted) setState({ key, result: null, error: e.message }); });
    return () => controller.abort();
  }, [id, user, key]);
  return { result: state?.key === key ? state.result : null, loading: !!id && !!user && state?.key !== key, error: state?.key === key ? state.error : undefined };
}

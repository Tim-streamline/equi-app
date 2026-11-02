import { captureException } from '@/lib/error-tracking';
import { AbortOperation } from '@powersync/common';
import { SyncRequestError } from './sync-request';

type Operation = 'sync.credentials' | 'sync.upload' | 'sync.download' | 'sync.retry' | 'intake.attachment.upload' | 'intake.attachment.storage' | 'intake.attachment.pending';
const reported = new WeakSet<object>();
const recent = new Map<string, number>();

/** Never send raw response bodies, filenames, answers, URLs or account identifiers. */
export function reportSyncFailure(error: unknown, operation: Operation) {
  if (error instanceof AbortOperation) return;
  if (error instanceof SyncRequestError && error.kind === 'cancelled') return;
  if (error instanceof Error && error.name === 'AbortError') return;
  if (error && typeof error === 'object') {
    if (reported.has(error)) return;
    reported.add(error);
  }
  const rawStatus = error && typeof error === 'object' && 'status' in error ? error.status : undefined;
  const status = typeof rawStatus === 'number' && Number.isInteger(rawStatus) && rawStatus >= 400 && rawStatus <= 599
    ? rawStatus : undefined;
  const kind = error instanceof SyncRequestError ? error.kind : status !== undefined ? 'http'
    : operation === 'intake.attachment.storage' ? 'storage' : error instanceof TypeError ? 'network' : 'unknown';
  const key = `${operation}:${kind}:${status ?? ''}`;
  const now = Date.now();
  if (recent.has(key) && now - recent.get(key)! < 300_000) return;
  if (recent.size >= 100) recent.delete(recent.keys().next().value!);
  recent.set(key, now);
  // Reporting must never break persistence or the automatic retry loop.
  try {
    captureException(new Error(`${operation} failed (${kind}${status ? `, HTTP ${status}` : ''})`), {
      tags: { operation, failure_kind: kind, ...(status ? { http_status: String(status) } : {}) },
    });
  } catch { /* Telemetry is best effort. */ }
}

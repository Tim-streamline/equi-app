import { captureException } from '@/lib/error-tracking';
import { AbortOperation } from '@powersync/common';
import { SyncRequestError } from './sync-request';

type Operation = 'sync.credentials' | 'sync.upload' | 'sync.download' | 'sync.retry' | 'intake.attachment.upload' | 'intake.attachment.storage' | 'intake.attachment.pending';
const reported = new WeakSet<object>();
const recent = new Map<string, number>();

/** Opaque IDs are support references. Never send names, answers, URLs or raw response bodies. */
export function reportSyncFailure(error: unknown, operation: Operation, uploadDetails: Record<string, unknown> = {}) {
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
  const details = operation === 'intake.attachment.upload' ? safeUploadDetails(uploadDetails) : {};
  const key = `${operation}:${kind}:${status ?? ''}:${details.account_id ?? ''}:${details.attachment_id ?? ''}:${details.validation_failed ?? ''}`;
  const now = Date.now();
  if (recent.has(key) && now - recent.get(key)! < 300_000) return;
  if (recent.size >= 100) recent.delete(recent.keys().next().value!);
  recent.set(key, now);
  // Reporting must never break persistence or the automatic retry loop.
  try {
    captureException(new Error(`${operation} failed (${kind}${status ? `, HTTP ${status}` : ''})`), {
      tags: { operation, failure_kind: kind, ...(status ? { http_status: String(status) } : {}), ...details },
    });
  } catch { /* Telemetry is best effort. */ }
}

function safeUploadDetails(details: Record<string, unknown>): Record<string, string> {
  const tags: Record<string, string> = {};
  for (const key of ['upload_request_id', 'account_id', 'intake_id', 'attachment_id']) {
    const value = details[key];
    if (typeof value === 'string' && /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i.test(value)) tags[key] = value.toLowerCase();
  }
  if (typeof details.file_bytes === 'number' && Number.isSafeInteger(details.file_bytes) && details.file_bytes >= 0) tags.file_bytes = String(details.file_bytes);
  for (const key of ['client_mime', 'blob_mime']) {
    const value = details[key];
    if (typeof value === 'string') tags[key] = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif', 'image/mpo', 'application/pdf', 'application/octet-stream', ''].includes(value) ? value || 'empty' : 'other';
  }
  if (['blob', 'uri', 'missing'].includes(String(details.file_transport))) tags.file_transport = String(details.file_transport);
  const validation = details.validation_failed;
  if (typeof validation === 'string' && validation.length <= 512 && /^(?:unknown|(?:horse_id|section|field|attachment_id|file):(?:required|uuid|string|max|file|mimes|image|uploaded|dimensions|other)(?:,(?:horse_id|section|field|attachment_id|file):(?:required|uuid|string|max|file|mimes|image|uploaded|dimensions|other))*)$/.test(validation)) tags.validation_failed = validation;
  return tags;
}

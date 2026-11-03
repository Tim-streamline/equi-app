import { getApiBaseUrl } from '@/db/auth';
import { attachmentStorage } from './storage';
import { flushAttachmentUploads, type AttachmentOperation } from './upload';
import { syncRequest, syncHttpError } from '@/lib/sync-request';
import { reportSyncFailure } from '@/lib/sync-errors';

export async function uploadIntakeAttachments(operations: readonly AttachmentOperation[], userId: string, token: string, checkSession: () => void, signal?: AbortSignal) {
  let diagnostics: Record<string, unknown> = { account_id: userId };
  try {
    await flushAttachmentUploads(operations, userId, attachmentStorage, async record => {
      // A fresh correlation ID per attempt, including retries; never replace the attachment ID.
      const requestId = typeof crypto !== 'undefined' && crypto.randomUUID ? crypto.randomUUID()
        : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => { const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 3) | 8).toString(16); });
      diagnostics = { upload_request_id: requestId, account_id: userId, intake_id: record.bookingId, attachment_id: record.id,
        client_mime: record.mime, blob_mime: record.blob?.type, file_bytes: record.blob?.size,
        file_transport: record.blob ? 'blob' : record.uri ? 'uri' : 'missing' };
      const body = new FormData();
      body.append('attachment_id', record.id);
      body.append('horse_id', record.horseId);
      body.append('section', record.section);
      body.append('field', record.field);
      if (record.blob) body.append('file', record.blob, record.name);
      else body.append('file', { uri: record.uri, name: record.name, type: record.mime } as unknown as Blob);
      await syncRequest(120_000, async (requestSignal, check) => {
        checkSession();
        const response = await fetch(`${getApiBaseUrl()}/api/intakes/${record.bookingId}/attachments`, {
          method: 'POST', headers: { Accept: 'application/json', Authorization: `Bearer ${token}`, 'X-Upload-Request-Id': requestId }, body, signal: requestSignal,
        });
        check();
        if (!response.ok) {
          diagnostics.validation_failed = response.headers?.get('X-Upload-Validation');
          throw syncHttpError(response.status);
        }
        const data = await response.json();
        check();
        if (data.value?.split(':')[1] !== record.id) throw new Error('De server moet worden bijgewerkt voor offline foto’s.');
      }, signal);
    }, checkSession);
  } catch (error) {
    reportSyncFailure(error, 'intake.attachment.upload', diagnostics);
    throw error;
  }
}

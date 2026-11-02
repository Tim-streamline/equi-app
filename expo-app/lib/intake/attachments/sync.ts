import { getApiBaseUrl } from '@/db/auth';
import { attachmentStorage } from './storage';
import { flushAttachmentUploads, type AttachmentOperation } from './upload';
import { syncRequest, syncHttpError } from '@/lib/sync-request';
import { reportSyncFailure } from '@/lib/sync-errors';

export async function uploadIntakeAttachments(operations: readonly AttachmentOperation[], userId: string, token: string, checkSession: () => void, signal?: AbortSignal) {
  try {
    await flushAttachmentUploads(operations, userId, attachmentStorage, async record => {
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
          method: 'POST', headers: { Accept: 'application/json', Authorization: `Bearer ${token}` }, body, signal: requestSignal,
        });
        check();
        if (!response.ok) throw syncHttpError(response.status);
        const data = await response.json();
        check();
        if (data.value?.split(':')[1] !== record.id) throw new Error('De server moet worden bijgewerkt voor offline foto’s.');
      }, signal);
    }, checkSession);
  } catch (error) {
    reportSyncFailure(error, 'intake.attachment.upload');
    throw error;
  }
}

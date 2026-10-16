import { getApiBaseUrl } from '@/db/auth';
import { attachmentStorage } from './storage';
import { flushAttachmentUploads, type AttachmentOperation } from './upload';

export async function uploadIntakeAttachments(operations: readonly AttachmentOperation[], userId: string, token: string, checkSession: () => void) {
  await flushAttachmentUploads(operations, userId, attachmentStorage, async record => {
    const body = new FormData();
    body.append('attachment_id', record.id);
    body.append('horse_id', record.horseId);
    body.append('section', record.section);
    body.append('field', record.field);
    if (record.blob) body.append('file', record.blob, record.name);
    else body.append('file', { uri: record.uri, name: record.name, type: record.mime } as unknown as Blob);
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 120000);
    try {
      checkSession();
      const response = await fetch(`${getApiBaseUrl()}/api/intakes/${record.bookingId}/attachments`, {
        method: 'POST', headers: { Accept: 'application/json', Authorization: `Bearer ${token}` }, body, signal: controller.signal,
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.errors?.file?.[0] || data.message || 'Uploaden mislukt.');
      if (data.value?.split(':')[1] !== record.id) throw new Error('De server moet worden bijgewerkt voor offline foto’s.');
    } finally { clearTimeout(timeout); }
  }, checkSession);
}

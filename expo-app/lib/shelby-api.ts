import { getApiBaseUrl } from '@/db/auth';
import { getOrMintToken } from '@/db/connector';
import { SHELBY_UNAVAILABLE, type ShelbyChatMessage } from './shelby';

/** The AI may take a while; allow more time than ordinary dashboard requests. */
const DEADLINE_MS = 60000;

export async function askShelby(input: { horseId: string; clientId: string; body: string }): Promise<{ sessionId: string; messages: ShelbyChatMessage[] }> {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), DEADLINE_MS);
  try {
    const token = await getOrMintToken(controller.signal);
    if (!token) throw new Error('Meld je opnieuw aan om met Shelby te chatten.');
    const response = await fetch(`${getApiBaseUrl()}/api/shelby/messages`, {
      method: 'POST', signal: controller.signal,
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
      body: JSON.stringify({ horse_id: input.horseId, client_id: input.clientId, body: input.body }),
    });
    const json = await response.json().catch(() => null);
    if (!response.ok) {
      // Only show the server's own Dutch explanations for limits, access and outages.
      const message = [403, 429, 503].includes(response.status) && typeof json?.message === 'string' ? json.message : SHELBY_UNAVAILABLE;
      throw new Error(message);
    }
    return {
      sessionId: String(json.session_id),
      messages: (json.messages ?? []).map((m: Record<string, unknown>) => ({
        id: String(m.id), role: m.role === 'assistant' ? 'assistant' : 'user', body: String(m.body ?? ''), plusCta: m.plus_cta === true,
      })),
    };
  } catch (error) {
    if (error instanceof Error && error.name !== 'AbortError' && error.message && !/network|fetch|aborted/i.test(error.message)) throw error;
    throw new Error(SHELBY_UNAVAILABLE);
  } finally {
    clearTimeout(timer);
  }
}

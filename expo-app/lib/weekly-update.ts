/** A weekly update that blocks the app until it is filled in (GET /api/weekly-updates). */
export type OpenWeeklyUpdate = { horseId: string; horseName: string; protocolId: string; week: number };

export const WEEKLY_UPDATE_PLACEHOLDER =
  'Wat is je deze week opgevallen? Denk aan veranderingen in mest, eetlust, gedrag, energie, beweging of bestaande klachten. Ook als er niets veranderd is, mag je dat aangeven.';
export const WEEKLY_UPDATE_HINT = 'Na het opslaan van je weekupdate kun je weer verder in de app.';
/** The server's answer when the week changed while the form was open (HorseDashboardController::weeklyUpdate). */
export const WEEKLY_UPDATE_STALE = 'Er staat inmiddels een nieuwe weekupdate open.';

const NUMBERS = ['nul', 'één', 'twee', 'drie', 'vier', 'vijf', 'zes', 'zeven', 'acht', 'negen', 'tien'];

/**
 * Title and line below it for horse `position` (1-based) of `total` in this round.
 * The first horse gets the general line; every next horse says how many are left, this one included.
 */
export function weeklyUpdateCopy(horseName: string, position: number, total: number) {
  if (position <= 1) {
    return { title: `Hoe gaat het met ${horseName}?`, subtitle: 'Ook kleine veranderingen zijn waardevol om vast te leggen.' };
  }
  const left = Math.max(1, total - position + 1);
  const count = left === 1 ? 'Nog één paard' : `Nog ${NUMBERS[left] ?? left} paarden`;
  return { title: `En hoe gaat het met ${horseName}?`, subtitle: `${count}, dan ben je klaar. Paard ${position} van ${total}.` };
}

/** Parses the response; anything unexpected means nothing is open, so the app is never locked by a bad answer. */
export function openWeeklyUpdates(response: unknown): OpenWeeklyUpdate[] {
  const list = (response as { weeklyUpdates?: unknown })?.weeklyUpdates;
  if (!Array.isArray(list)) return [];
  return list.filter((item): item is OpenWeeklyUpdate => !!item && typeof item.horseId === 'string'
    && typeof item.protocolId === 'string' && typeof item.horseName === 'string' && Number.isInteger(item.week) && item.week > 0);
}

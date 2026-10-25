// Presentation only. Scores remain the stored legacy 0–100 values; the new
// assessment engine and its rules stay on the server.
export type ScannerRow = { id: string; [key: string]: any };

export function legacyScanScore(value: unknown): number | null {
  if (value === null || value === undefined || (typeof value === 'string' && !value.trim())) return null;
  const score = typeof value === 'number' ? value : typeof value === 'string' ? Number(value) : NaN;
  return Number.isFinite(score) && score >= 0 && score <= 100 ? score : null;
}

export function scanScoreLabel(value: unknown): string {
  const score = legacyScanScore(value);
  return score === null ? '?' : (score / 10).toLocaleString('nl-NL', { maximumFractionDigits: 2 });
}

export function recentScans<T extends ScannerRow>(scans: T[]): T[] {
  return [...scans].sort((a, b) => {
    const time = (row: T) => Date.parse(row.scannedAt ?? '') || 0;
    return time(b) - time(a) || a.id.localeCompare(b.id);
  });
}

export function matchesFeed(row: ScannerRow, query: string): boolean {
  const words = query.trim().toLocaleLowerCase('nl-NL').split(/\s+/).filter(Boolean);
  const name = `${row.brand ?? ''} ${row.name ?? row.productName ?? ''}`.toLocaleLowerCase('nl-NL');
  return words.every(word => name.includes(word));
}

export function feedCategory(category?: string): string {
  return ({ complete: 'Compleet paardenvoer', complementary: 'Aanvullend voer',
    balancer: 'Balancer', mash: 'Slobber', feed: 'Paardenvoer', supplement: 'Supplement', herb: 'Kruiden' } as Record<string, string>)[category ?? ''] ?? 'Paardenvoer';
}

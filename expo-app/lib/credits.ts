/** API timestamps may be ISO-8601 or PostgreSQL's `date time+00` representation. */
export function creditDate(value: string): string {
  let normalized = value.trim().replace(' ', 'T');
  if (/^\d{4}-\d{2}-\d{2}$/.test(normalized)) normalized += 'T00:00:00';
  normalized = normalized.replace(/([+-]\d{2})$/, '$1:00');
  if (!/(?:Z|[+-]\d{2}:\d{2})$/i.test(normalized)) normalized += 'Z';
  const date = new Date(normalized);
  return Number.isNaN(date.getTime()) ? 'Datum onbekend' : date.toLocaleDateString('nl-NL', { day: 'numeric', month: 'long', year: 'numeric' });
}

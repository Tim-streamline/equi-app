/** Match the server's single subscription rule, including while offline. */
export function subscriptionActive(subscription: Record<string, any>, now = new Date()): boolean {
  const time = now.getTime();
  const timestamp = (value: string) => new Date(/^\d{4}-\d\d-\d\d[ T]\d\d:\d\d:\d\d(?:\.\d+)?$/.test(value) ? value.replace(' ', 'T') + 'Z' : value).getTime();
  return subscription.status === 'active'
    && (!subscription.started_at || timestamp(subscription.started_at) <= time)
    && ['paid_through', 'ended_at', 'cancelled_at'].every(key => !subscription[key] || timestamp(subscription[key]) > time);
}

/** "€ 12 per maand"; whole euros drop their cents, other amounts use a Dutch decimal comma. */
export function subscriptionPriceLabel(priceCents: number | null | undefined, interval?: string | null, suffix?: string | null): string {
  if (priceCents === null || priceCents === undefined || Number.isNaN(Number(priceCents))) return '';
  const euros = Number(priceCents) / 100;
  const amount = `€ ${euros.toLocaleString('nl-NL', { minimumFractionDigits: Number.isInteger(euros) ? 0 : 2, maximumFractionDigits: 2 })}`;
  const period = suffix?.trim() || ({ monthly: 'per maand', yearly: 'per jaar', one_time: 'eenmalig' } as Record<string, string>)[interval ?? ''] || '';
  return period ? `${amount} ${period}` : amount;
}

/** Join optional metadata parts without leaving dangling separators. */
export function joinMeta(...parts: (string | number | null | undefined | false)[]): string {
  return parts.filter(part => part !== null && part !== undefined && part !== false && String(part).trim() !== '').join(' · ');
}

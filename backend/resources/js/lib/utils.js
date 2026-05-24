import { clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';

/** Merge conditional class lists, de-duping conflicting Tailwind classes. */
export function cn(...inputs) {
    return twMerge(clsx(inputs));
}

/** Format an integer with thousands separators (nl-NL). */
export function formatNumber(value) {
    return new Intl.NumberFormat('nl-NL').format(value ?? 0);
}

/** Format cents into a localized currency string. */
export function formatCents(cents, currency = 'EUR') {
    return new Intl.NumberFormat('nl-NL', { style: 'currency', currency }).format((cents ?? 0) / 100);
}

/** Format an ISO date/datetime into a short nl-NL date. */
export function formatDate(value, opts = { day: '2-digit', month: 'short', year: 'numeric' }) {
    if (!value) return '—';
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return '—';
    return new Intl.DateTimeFormat('nl-NL', opts).format(d);
}

export function formatDateTime(value) {
    return formatDate(value, { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

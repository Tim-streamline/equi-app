/** Lowercase without diacritics, so "creeren" finds "creëren". */
export function normalizeSearch(value) {
    return String(value ?? '')
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLowerCase()
        .trim();
}

/** True when every word of the query occurs in the measure's title or description. */
export function measureMatches(measure, query) {
    const words = normalizeSearch(query).split(/\s+/).filter(Boolean);
    if (!words.length) return true;

    const haystack = normalizeSearch(`${measure.title ?? ''} ${measure.description ?? ''}`);
    return words.every((word) => haystack.includes(word));
}

export function protocolCount(count) {
    return `${count} ${count === 1 ? 'protocol' : 'protocollen'}`;
}

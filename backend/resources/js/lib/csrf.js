// Inertia navigation keeps the original HTML head after login regenerates the
// session token. Read Laravel's current cookie for each request instead.
export function csrfHeaders() {
    const cookie = document.cookie.split('; ').find((value) => value.startsWith('XSRF-TOKEN='));
    return cookie ? { 'X-XSRF-TOKEN': decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) } : {};
}

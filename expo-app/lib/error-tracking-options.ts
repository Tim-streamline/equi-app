// These are public, write-only ingestion DSNs, not Better Stack API tokens.
export const BETTER_STACK_WEB_DSN = 'https://zL9oGMpzqHzc67x2Y4kf7fCW@s2781654.us-west-2a.betterstackdata.com/2781654';
export const BETTER_STACK_ANDROID_DSN = 'https://GWuJcK8Dy1Pui3y1ttD2oAic@s2781655.us-west-2a.betterstackdata.com/2781655';

export function redactErrorText(value: string): string {
  return value
    .replace(/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/gi, '[email]')
    .replace(/\bBearer\s+[^\s,;]+/gi, 'Bearer [redacted]')
    .replace(/\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\b/g, '[token]')
    .replace(/((?:password|token|secret|authorization|api[_-]?key)\s*[=:]\s*)[^\s,;&]+/gi, '$1[redacted]');
}

export function sanitizeErrorUrl(value: string): string {
  return redactErrorText(value.split(/[?#]/, 1)[0])
    .replace(/(\/password\/reset\/)[^/]+/g, '$1[redacted]')
    .replace(/(\/confirm(?:ation)?\/)[^/]+/g, '$1[redacted]');
}

// Independent of the SDK so web, admin, and native use the same policy.
// Preserve stack traces and release tags; omit arbitrary application data.
export function sanitizeErrorEvent<T>(event: T): T {
  const clean = JSON.parse(JSON.stringify(event), (key, value) => {
    if (['user', 'extra', 'data', 'headers', 'cookies', 'query_string', 'vars', 'contexts'].includes(key)) return undefined;
    if (key === 'breadcrumbs') return [];
    if (typeof value !== 'string') return value;
    return ['url', 'filename', 'abs_path', 'transaction'].includes(key) ? sanitizeErrorUrl(value) : redactErrorText(value);
  });
  if (clean.request) clean.request = { method: clean.request.method, url: clean.request.url };
  return clean;
}

export function errorTrackingOptions(dsn: string, environment: string, release?: string, enabled = true) {
  return {
    dsn,
    enabled: enabled && Boolean(dsn),
    environment,
    ...(release ? { release } : {}),
    sendDefaultPii: false,
    sampleRate: 1,
    tracesSampleRate: 0,
    enableLogs: false,
    maxBreadcrumbs: 0,
    beforeSend: sanitizeErrorEvent,
  };
}

export async function readWebRelease(fetcher: typeof fetch = fetch): Promise<string> {
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 10000);
  try {
    const response = await fetcher('/web-version.json', { cache: 'no-store', signal: controller.signal });
    if (!response.ok) throw new Error('De update kan nu niet worden gecontroleerd. Probeer opnieuw zodra je online bent.');
    const data = await response.json();
    if (typeof data.buildId !== 'string' || !/^[a-zA-Z0-9-]{1,80}$/.test(data.buildId)) throw new Error('De update is nog niet beschikbaar. Probeer opnieuw.');
    return data.buildId;
  } finally { clearTimeout(timeout); }
}

export async function refreshWebApp(options: {
  prepare: () => Promise<void>;
  verify: () => Promise<unknown>;
  reload: () => void;
}) {
  await options.verify();
  await options.prepare();
  options.reload();
}

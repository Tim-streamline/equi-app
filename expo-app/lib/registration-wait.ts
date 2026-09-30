type RegistrationStatus = 'pending' | 'confirmed' | 'expired';

function delay(signal: AbortSignal) {
  return new Promise<void>(resolve => {
    const done = () => { clearTimeout(timer); signal.removeEventListener('abort', done); resolve(); };
    const timer = setTimeout(done, 3000);
    signal.addEventListener('abort', done, { once: true });
    if (signal.aborted) done();
  });
}

/** Stop immediately when the screen closes or a new confirmation mail is requested. */
export async function waitForRegistration(options: {
  signal: AbortSignal;
  check: (signal: AbortSignal) => Promise<{ status: RegistrationStatus }>;
  finish: () => Promise<void>;
  onError: (message: string) => void;
}): Promise<'completed' | 'expired' | 'cancelled'> {
  const { signal, check, finish, onError } = options;
  while (!signal.aborted) {
    try {
      const { status } = await check(signal);
      if (signal.aborted) return 'cancelled';
      if (status === 'expired') return 'expired';
      onError('');
      if (status === 'confirmed') {
        await finish();
        return 'completed';
      }
    } catch (error) {
      if (signal.aborted) return 'cancelled';
      onError(error instanceof Error ? error.message : 'Verbinding niet beschikbaar. We proberen het opnieuw.');
    }
    await delay(signal);
  }
  return 'cancelled';
}

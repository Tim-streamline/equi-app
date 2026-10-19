import { useEffect, useRef, useState, type ReactNode } from 'react';
import { refreshSafety } from '../../expo-app/lib/refresh-safety.web';
import { readWebRelease, refreshWebApp } from '../lib/web-release';

export function MandatoryRefresh({ children }: { children: ReactNode }) {
  const [required, setRequired] = useState(false);
  const [reason, setReason] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const button = useRef<HTMLButtonElement>(null);
  const refreshing = useRef(false);
  const blocked = required && !reason;

  useEffect(() => {
    const update = () => setReason(refreshSafety.reason());
    const unsubscribe = refreshSafety.subscribe(update);
    update();
    return unsubscribe;
  }, []);
  useEffect(() => {
    const current = document.querySelector<HTMLMetaElement>('meta[name="equi-build"]')?.content;
    if (!current) return; // Development has no published release marker.
    let active = true, checking = false;
    const check = async () => {
      if (checking || document.visibilityState === 'hidden') return;
      checking = true;
      try {
        const next = await readWebRelease();
        if (active && next !== current) setRequired(true);
      } catch { /* Offline or a failed deployment check must not trigger a reload. */ }
      finally { checking = false; }
    };
    void check();
    const interval = setInterval(check, 60000);
    window.addEventListener('focus', check);
    window.addEventListener('online', check);
    document.addEventListener('visibilitychange', check);
    return () => {
      active = false;
      clearInterval(interval);
      window.removeEventListener('focus', check);
      window.removeEventListener('online', check);
      document.removeEventListener('visibilitychange', check);
    };
  }, []);
  useEffect(() => { if (blocked) button.current?.focus(); }, [blocked]);

  const refresh = async () => {
    if (refreshing.current) return;
    refreshing.current = true;
    setBusy(true); setError('');
    try {
      await refreshWebApp({ prepare: () => refreshSafety.prepare(), verify: readWebRelease, reload: () => window.location.reload() });
    } catch {
      setError('Vernieuwen is nog niet gelukt. Je gegevens blijven op deze pagina bewaard. Controleer je verbinding en probeer opnieuw.');
    } finally { refreshing.current = false; setBusy(false); }
  };

  return <>
    {/* Keep providers, forms and file selections mounted while blocking interaction. */}
    <div inert={blocked} aria-hidden={blocked || undefined} style={{ display: 'flex', flex: 1, minHeight: 0 }}>{children}</div>
    {required && reason && <div role="status" style={{ position: 'fixed', top: 0, left: 0, right: 0, zIndex: 10000, padding: '16px 24px', background: '#E8F4F2', color: '#174B49', boxShadow: '0 2px 8px #0002' }}>
      <strong>Er staat een update klaar.</strong> {reason} Daarna vernieuwen we de app.
    </div>}
    {blocked && <div role="dialog" aria-modal="true" aria-labelledby="refresh-title" aria-describedby="refresh-description"
      onKeyDown={event => { if (event.key === 'Tab') { event.preventDefault(); button.current?.focus(); } }}
      style={{ position: 'fixed', inset: 0, zIndex: 10001, display: 'flex', flexDirection: 'column', alignItems: 'center', overflowY: 'auto', padding: 24, background: '#FBF8F3', color: '#1B2A2A', fontFamily: 'SourceSans3_400Regular, sans-serif' }}>
      <div style={{ width: '100%', maxWidth: 440, margin: 'auto', flexShrink: 0 }}>
        <p style={{ color: '#127A79', fontWeight: 600 }}>EquiApp</p>
        <h1 id="refresh-title" style={{ fontSize: 28, lineHeight: 1.2 }}>Vernieuw de app om verder te gaan</h1>
        <p id="refresh-description" style={{ fontSize: 17, lineHeight: 1.6 }}>Er is een nieuwe versie beschikbaar. We slaan je intake-antwoorden eerst op dit apparaat op. Opgeslagen foto’s die nog moeten uploaden, blijven bewaard en worden daarna verder geüpload.</p>
        {error && <p role="alert" style={{ color: '#9B3F3F', lineHeight: 1.5 }}>{error}</p>}
        <button ref={button} type="button" onClick={() => void refresh()} aria-disabled={busy}
          style={{ width: '100%', border: 0, borderRadius: 28, padding: '15px 24px', background: '#127A79', color: 'white', font: 'inherit', fontWeight: 600, cursor: busy ? 'wait' : 'pointer', opacity: busy ? 0.7 : 1 }}>
          {busy ? 'Gegevens bewaren…' : error ? 'Opnieuw proberen' : 'Bewaren en vernieuwen'}
        </button>
        <p role="status" aria-live="polite" style={{ fontSize: 14, color: '#536565' }}>{busy ? 'Houd deze pagina open totdat de app is vernieuwd.' : 'Je hoeft niets opnieuw te installeren.'}</p>
      </div>
    </div>}
  </>;
}

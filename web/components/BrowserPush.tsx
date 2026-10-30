import { useEffect, useState } from 'react';
import { Text, View } from 'react-native';
import { SwitchRow } from '@/components/ui/SwitchRow';
import { useDb } from '../db/provider';
import { useCurrentUser } from '@/db/hooks';
import { Button } from '@/components/ui/Button';
import { bindPushUser, browserPushOptedIn, browserPushSupported, enableBrowserPush, disableBrowserPush } from '../lib/browser-push';

export function BrowserPushLifecycle() {
  const { currentUserId, isLoggedIn } = useDb();
  const user = useCurrentUser();
  useEffect(() => {
    let active = true;
    if (!browserPushSupported()) return;
    const sync = async () => {
      if (!isLoggedIn || !currentUserId || user.notificationsOn === false) { await disableBrowserPush(); return; }
      if (Notification.permission === 'granted' && active && browserPushOptedIn(currentUserId)) await enableBrowserPush(currentUserId, false);
    };
    void bindPushUser(null).then(sync).catch(() => {});
    const resume = () => { void sync().catch(() => {}); };
    window.addEventListener('focus', resume);
    return () => { active = false; window.removeEventListener('focus', resume); };
  }, [currentUserId, isLoggedIn, user.notificationsOn]);
  return null;
}
export function BrowserPushSettings() {
  const { currentUserId } = useDb();
  const user = useCurrentUser();
  const [busy, setBusy] = useState(false), [message, setMessage] = useState('');
  const [enabled, setEnabled] = useState(() => !currentUserId || browserPushOptedIn(currentUserId));
  const [permission, setPermission] = useState(() => typeof Notification === 'undefined' ? 'default' : Notification.permission);
  useEffect(() => {
    setEnabled(!currentUserId || browserPushOptedIn(currentUserId));
    setMessage('');
  }, [currentUserId]);
  async function toggle(enable: boolean) {
    if (busy || !currentUserId) return;
    setBusy(true); setMessage('');
    try { if (enable) await enableBrowserPush(currentUserId); else await disableBrowserPush(currentUserId); setMessage(enable ? 'Browsermeldingen staan aan.' : 'Browsermeldingen staan uit.'); }
    catch (error) { setMessage(error instanceof Error ? error.message : 'Probeer opnieuw.'); }
    finally {
      setEnabled(browserPushOptedIn(currentUserId));
      setPermission(typeof Notification === 'undefined' ? 'default' : Notification.permission);
      setBusy(false);
    }
  }
  const body = 'font-sans text-[14px] leading-[20px] text-ink-70';
  return <View className="mt-4 gap-3 rounded-[22px] bg-white p-5">
    <SwitchRow label="Meldingen in deze browser" description="Ontvang intakeherinneringen, meldingen over je volgende protocolfase en aflopende credits. Op iPhone/iPad voeg je de webapp eerst toe aan je beginscherm."
      value={enabled} disabled={busy || (!enabled && user.notificationsOn === false)} onChange={value => void toggle(value)} />
    {user.notificationsOn === false && <Text className={body}>Zet eerst meldingen aan bij je accountinstellingen.</Text>}
    {enabled && permission !== 'granted' && <><Text className={body}>{permission === 'denied' ? 'Sta meldingen toe in de instellingen van je browser om ze te ontvangen.' : 'Geef je browser nog toestemming om meldingen te ontvangen.'}</Text><Button title="Toestemming voor meldingen geven" disabled={busy || user.notificationsOn === false} onPress={() => void toggle(true)} /></>}
    {!!message && <Text accessibilityRole="alert" className="font-sans text-[14px] leading-[20px] text-ink">{message}</Text>}
  </View>;
}

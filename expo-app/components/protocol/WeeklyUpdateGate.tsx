import { useCallback, useEffect, useRef, useState } from 'react';
import { Alert, AppState, Modal, Pressable, Text, View } from 'react-native';
import { router } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { KeyboardTextInput as TextInput, KeyboardViewport, KeyboardScrollView } from '@/components/ui/KeyboardForm';
import { Button } from '@/components/ui/Button';
import { EYEBROW } from '@/components/ui/styles';
import { useDb } from '@/db/provider';
import { dashboardRequest, deviceTimezone } from '@/hooks/useHorseDashboard';
import { accountSession } from '@/lib/account-session';
import {
  openWeeklyUpdates, weeklyUpdateCopy, WEEKLY_UPDATE_HINT, WEEKLY_UPDATE_PLACEHOLDER, WEEKLY_UPDATE_STALE, type OpenWeeklyUpdate,
} from '@/lib/weekly-update';

/** Also picks up the update day while the app stays open. */
const CHECK_EVERY_MS = 5 * 60 * 1000;

/**
 * The weekly update of every horse with an open week (ProtocolWeeklyUpdate on the server), one horse at a time,
 * over the whole app. It cannot be closed: saving is the way through, and logging out is the only other action.
 * Mounted once at the root for a logged-in, subscribed account.
 */
export function WeeklyUpdateGate() {
  const { currentUserId, isLoggedIn } = useDb();
  if (!isLoggedIn || !currentUserId) return null;
  return <AccountWeeklyUpdateGate key={currentUserId} />;
}

function AccountWeeklyUpdateGate() {
  const { logout } = useDb();
  const [open, setOpen] = useState<OpenWeeklyUpdate[]>([]);
  // Horses saved in this round, so the screen can say "Paard 2 van 2".
  const [saved, setSaved] = useState(0);
  const [note, setNote] = useState('');
  const [busy, setBusy] = useState(false);
  const [loggingOut, setLoggingOut] = useState(false);
  const revision = useRef(accountSession.revision);
  const request = useRef(0);

  const check = useCallback(async () => {
    const current = ++request.current;
    try {
      const response = await dashboardRequest(`/api/weekly-updates?${new URLSearchParams({ timezone: deviceTimezone() })}`);
      if (current !== request.current || revision.current !== accountSession.revision) return;
      const next = openWeeklyUpdates(response);
      setOpen(next);
      if (!next.length) setSaved(0);
    } catch {
      // Offline or a server error: keep what is known. The app is never locked without a confirmed open week.
    }
  }, []);

  useEffect(() => {
    void check();
    const timer = setInterval(() => void check(), CHECK_EVERY_MS);
    const listener = AppState.addEventListener('change', state => { if (state === 'active') void check(); });
    return () => { clearInterval(timer); listener.remove(); request.current++; };
  }, [check]);

  const current = open[0];
  if (!current) return null;
  const position = saved + 1;
  const total = saved + open.length;
  const { title, subtitle } = weeklyUpdateCopy(current.horseName, position, total);

  const save = async () => {
    if (!note.trim() || busy) return;
    setBusy(true);
    try {
      await dashboardRequest(`/api/horses/${current.horseId}/weekly-update`, {
        protocol_id: current.protocolId, week: current.week, note: note.trim(), timezone: deviceTimezone(),
      });
      setSaved(value => value + 1);
      setOpen(list => list.filter(item => item.protocolId !== current.protocolId));
      setNote('');
      void check();
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Probeer het opnieuw.';
      Alert.alert('Weekupdate niet opgeslagen', message);
      // The week moved on while typing: show the open week, the text stays for that one.
      if (message.startsWith(WEEKLY_UPDATE_STALE)) {
        await check();
      } else if (message === 'Er staat geen weekupdate open.') {
        // Filled in elsewhere or no longer open: move on without carrying this text to another horse.
        setNote('');
        await check();
      }
    } finally {
      setBusy(false);
    }
  };

  const confirmLogout = () => {
    if (loggingOut || busy) return;
    Alert.alert('Uitloggen?', 'Je antwoorden uit de protocol-intake en lokaal opgeslagen data worden gewist.', [
      { text: 'Annuleer', style: 'cancel' },
      {
        text: 'Uitloggen', style: 'destructive', onPress: async () => {
          setLoggingOut(true);
          try {
            await logout();
            router.replace('/onboarding/welcome');
          } finally {
            setLoggingOut(false);
          }
        },
      },
    ]);
  };

  return (
    // No way to dismiss: the Android back button does nothing.
    <Modal visible animationType="fade" presentationStyle="fullScreen" onRequestClose={() => {}}>
      <SafeAreaView edges={['top', 'bottom']} style={{ flex: 1 }} className="bg-canvas">
        <KeyboardViewport>
          <KeyboardScrollView contentContainerStyle={{ flexGrow: 1, padding: 20 }} keyboardShouldPersistTaps="handled">
            <View accessibilityViewIsModal className="flex-1">
              <View className="flex-row items-center">
                <Text className={`${EYEBROW} text-teal-600`}>Weekupdate · week {current.week}</Text>
                {total > 1 && (
                  <View className="ml-auto flex-row gap-1.5" accessibilityLabel={`Paard ${position} van ${total}`}>
                    {Array.from({ length: total }, (_, index) => (
                      <View key={index} className={`h-2 w-2 rounded-full ${index === position - 1 ? 'bg-teal-500' : 'bg-ink-15'}`} />
                    ))}
                  </View>
                )}
              </View>
              <Text accessibilityRole="header" className="mt-4 font-bold text-[28px] leading-[33px] text-ink">{title}</Text>
              <Text className="mt-2 font-sans text-[16px] leading-[22px] text-ink-70">{subtitle}</Text>
              <TextInput
                accessibilityLabel="Weekupdate"
                multiline
                value={note}
                onChangeText={setNote}
                placeholder={WEEKLY_UPDATE_PLACEHOLDER}
                maxLength={10000}
                textAlignVertical="top"
                editable={!busy}
                className="mt-5 min-h-[200px] rounded-2xl border border-ink-15 bg-white p-4 font-sans text-[16px] leading-[22px] text-ink"
              />
              <View className="mt-auto pt-5">
                <Button title={busy ? 'Opslaan…' : 'Weekupdate opslaan'} onPress={() => void save()} disabled={busy || !note.trim()} />
                <Text className="mt-3 text-center font-sans text-[13px] leading-[18px] text-ink-50">{WEEKLY_UPDATE_HINT}</Text>
                <Pressable accessibilityRole="button" onPress={confirmLogout} disabled={loggingOut || busy} className="mt-2 min-h-11 items-center justify-center">
                  <Text className="font-semi text-[14px] text-ink-70 underline">{loggingOut ? 'Uitloggen…' : 'Uitloggen'}</Text>
                </Pressable>
              </View>
            </View>
          </KeyboardScrollView>
        </KeyboardViewport>
      </SafeAreaView>
    </Modal>
  );
}

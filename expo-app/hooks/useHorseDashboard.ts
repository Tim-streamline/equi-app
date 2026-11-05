import { useCallback, useEffect, useRef, useState } from "react";
import { onLibraryUnlock } from "@/lib/library-events";
import { AppState } from "react-native";
import { useFocusEffect } from "expo-router";
import AsyncStorage from "@react-native-async-storage/async-storage";
import { useDb } from "@/db/provider";
import { useCurrentHorseId } from "@/db/hooks";
import { getApiBaseUrl } from "@/db/auth";
import { getOrMintToken } from "@/db/connector";
import { accountSession } from "@/lib/account-session";
import { dashboardAtTime, dashboardForHorse, type HorseDashboard } from "@/lib/horse-dashboard";

export const deviceTimezone = () =>
  Intl.DateTimeFormat().resolvedOptions().timeZone || "Europe/Amsterdam";

export async function dashboardRequest(path: string, body?: object) {
  const revision = accountSession.revision;
  const checkSession = () => {
    if (revision !== accountSession.revision) throw new Error('Je sessie is gewijzigd.');
  };
  const controller = new AbortController();
  let timeout: ReturnType<typeof setTimeout> | undefined;
  // Bound token acquisition and response parsing too. The race releases account
  // transitions even when a native operation does not settle after cancellation.
  const deadline = new Promise<never>((_, reject) => {
    timeout = setTimeout(() => {
      reject(new Error("De verbinding duurde te lang. Probeer het opnieuw."));
      controller.abort();
    }, 15000);
  });
  try {
    return await Promise.race([deadline, (async () => {
      const token = await getOrMintToken(controller.signal);
      checkSession();
      if (controller.signal.aborted) throw new Error("Request cancelled.");
      if (!token) throw new Error("Meld je opnieuw aan om gegevens op te halen.");
      const response = await fetch(`${getApiBaseUrl()}${path}`, {
        method: body ? "POST" : "GET",
        headers: {
          Accept: "application/json",
          "Content-Type": "application/json",
          Authorization: `Bearer ${token}`,
        },
        ...(body ? { body: JSON.stringify(body) } : {}),
        signal: controller.signal,
      });
      if (!response.ok)
        throw new Error(
          `Gegevens konden niet worden bijgewerkt (${response.status}).`,
        );
      const result = await response.json();
      checkSession();
      return result;
    })()]);
  } finally {
    clearTimeout(timeout);
  }
}

export function useHorseDashboard(month?: string) {
  const horseId = useCurrentHorseId();
  const { currentUserId, syncStatus } = useDb();
  const key = `horse-dashboard:v4:${currentUserId}:${horseId}:${month ?? "today"}`;
  const [snapshot, setSnapshot] = useState<{
    key: string;
    data: HorseDashboard;
  } | null>(null);
  const [error, setError] = useState<string | null>(null);
  const generation = useRef(0);
  const sessionRevision = useRef(accountSession.revision);
  const [clock, setClock] = useState(() => new Date());
  const refresh = useCallback(async () => {
    setClock(new Date());
    if (!currentUserId || sessionRevision.current !== accountSession.revision) return;
    const request = ++generation.current;
    try {
      const params = new URLSearchParams({
        timezone: deviceTimezone(),
        ...(month ? { month } : {}),
      });
      const data = (await dashboardRequest(
        horseId ? `/api/horses/${horseId}/dashboard?${params}` : `/api/home?${params}`,
      )) as HorseDashboard;
      if (request !== generation.current || sessionRevision.current !== accountSession.revision) return;
      setSnapshot({ key, data });
      setError(null);
      await AsyncStorage.setItem(key, JSON.stringify(data));
    } catch (err) {
      if (request === generation.current)
        setError(
          err instanceof Error ? err.message : "Verbinding niet beschikbaar.",
        );
    }
  }, [horseId, currentUserId, month, key]);

  useEffect(() => onLibraryUnlock(itemId => {
    setSnapshot(previous => previous?.key === key ? { ...previous, data: {
      ...previous.data, recommendations: previous.data.recommendations.filter(item => item.id !== itemId),
    } } : previous);
    void AsyncStorage.removeItem(key);
    void refresh();
  }), [key, refresh]);

  useEffect(() => {
    let active = true;
    setError(null);
    // Remove snapshots written by older app versions: v1/v2 were unrestricted and v3 stored
    // per-supplement order rows instead of aggregated order items (no quantity, no links).
    void AsyncStorage.getAllKeys().then((keys) => AsyncStorage.multiRemove(keys.filter((item) => /^horse-dashboard:v[123]:/.test(item))));
    AsyncStorage.getItem(key).then((raw) => {
      if (active && raw) {
        try {
          setSnapshot((previous) =>
            previous?.key === key ? previous : { key, data: JSON.parse(raw) },
          );
        } catch {
          /* ignore invalid cache */
        }
      }
    });
    return () => {
      active = false;
      generation.current++;
    };
  }, [key]);
  useFocusEffect(
    useCallback(() => {
      void refresh();
      const timer = setInterval(() => { setClock(new Date()); void refresh(); }, 30000);
      const listener = AppState.addEventListener("change", (state) => {
        if (state === "active") { setClock(new Date()); void refresh(); }
      });
      return () => {
        clearInterval(timer);
        listener.remove();
      };
    }, [refresh]),
  );
  useEffect(() => {
    if (syncStatus === "connected") void refresh();
  }, [syncStatus, refresh]);
  const data =
    snapshot?.key === key ? dashboardForHorse(dashboardAtTime(snapshot.data, clock), horseId) : null;
  return { data, error, refresh, horseId, loading: !!currentUserId && !data && !error };
}

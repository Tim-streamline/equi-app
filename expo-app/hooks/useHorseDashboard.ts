import { useCallback, useEffect, useRef, useState } from "react";
import { AppState } from "react-native";
import { useFocusEffect } from "expo-router";
import AsyncStorage from "@react-native-async-storage/async-storage";
import { useDb } from "@/db/provider";
import { useCurrentHorseId } from "@/db/hooks";
import { getApiBaseUrl } from "@/db/auth";
import { getOrMintToken } from "@/db/connector";
import { dashboardAtTime, dashboardForHorse, type HorseDashboard } from "@/lib/horse-dashboard";

export const deviceTimezone = () =>
  Intl.DateTimeFormat().resolvedOptions().timeZone || "Europe/Amsterdam";

export async function dashboardRequest(path: string, body?: object) {
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
      return await response.json();
    })()]);
  } finally {
    clearTimeout(timeout);
  }
}

export function useHorseDashboard(month?: string) {
  const horseId = useCurrentHorseId();
  const { currentUserId, syncStatus } = useDb();
  const key = `horse-dashboard:v2:${currentUserId}:${horseId}:${month ?? "today"}`;
  const [snapshot, setSnapshot] = useState<{
    key: string;
    data: HorseDashboard;
  } | null>(null);
  const [error, setError] = useState<string | null>(null);
  const generation = useRef(0);
  const [clock, setClock] = useState(() => new Date());
  const refresh = useCallback(async () => {
    setClock(new Date());
    if (!horseId || !currentUserId) return;
    const request = ++generation.current;
    try {
      const params = new URLSearchParams({
        timezone: deviceTimezone(),
        ...(month ? { month } : {}),
      });
      const data = (await dashboardRequest(
        `/api/horses/${horseId}/dashboard?${params}`,
      )) as HorseDashboard;
      if (request !== generation.current) return;
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

  useEffect(() => {
    let active = true;
    setError(null);
    // Remove unrestricted snapshots written by older app versions.
    void AsyncStorage.getAllKeys().then((keys) => AsyncStorage.multiRemove(keys.filter((item) => item.startsWith('horse-dashboard:v1:'))));
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
  return { data, error, refresh, horseId, loading: !data && !error };
}

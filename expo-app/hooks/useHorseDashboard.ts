import { useCallback, useEffect, useRef, useState } from "react";
import { AppState } from "react-native";
import { useFocusEffect } from "expo-router";
import AsyncStorage from "@react-native-async-storage/async-storage";
import { useDb } from "@/db/provider";
import { useCurrentHorseId } from "@/db/hooks";
import { getApiBaseUrl } from "@/db/auth";
import { getOrMintToken } from "@/db/connector";
import { dashboardForHorse, type HorseDashboard } from "@/lib/horse-dashboard";

export const deviceTimezone = () =>
  Intl.DateTimeFormat().resolvedOptions().timeZone || "Europe/Amsterdam";

export async function dashboardRequest(path: string, body?: object) {
  const token = await getOrMintToken();
  if (!token) throw new Error("Meld je opnieuw aan om gegevens op te halen.");
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 15000);
  let response: Response;
  try {
    response = await fetch(`${getApiBaseUrl()}${path}`, {
      method: body ? "POST" : "GET",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
        Authorization: `Bearer ${token}`,
      },
      ...(body ? { body: JSON.stringify(body) } : {}),
      signal: controller.signal,
    });
  } finally {
    clearTimeout(timeout);
  }
  if (!response.ok)
    throw new Error(
      `Gegevens konden niet worden bijgewerkt (${response.status}).`,
    );
  return response.json();
}

export function useHorseDashboard(month?: string) {
  const horseId = useCurrentHorseId();
  const { currentUserId, syncStatus } = useDb();
  const key = `horse-dashboard:v1:${currentUserId}:${horseId}:${month ?? "today"}`;
  const [snapshot, setSnapshot] = useState<{
    key: string;
    data: HorseDashboard;
  } | null>(null);
  const [error, setError] = useState<string | null>(null);
  const generation = useRef(0);
  const refresh = useCallback(async () => {
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
      const timer = setInterval(() => void refresh(), 30000);
      const listener = AppState.addEventListener("change", (state) => {
        if (state === "active") void refresh();
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
    snapshot?.key === key ? dashboardForHorse(snapshot.data, horseId) : null;
  return { data, error, refresh, horseId, loading: !data && !error };
}

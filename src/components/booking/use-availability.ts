"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import type { MonthAvailability } from "@/lib/availability";

type State =
  | { status: "loading"; data: MonthAvailability | null }
  | { status: "ready"; data: MonthAvailability }
  | { status: "error"; data: MonthAvailability | null; message: string };

const FALLBACK_ERROR = "Les disponibilités ne peuvent pas être chargées. Vérifiez votre connexion.";

async function fetchMonth(month: string | null): Promise<MonthAvailability> {
  const response = await fetch(`/api/availability${month ? `?month=${month}` : ""}`, {
    cache: "no-store",
    signal: AbortSignal.timeout(15_000),
  });
  const body = await response.json().catch(() => null);
  if (!response.ok || !body?.month) throw new Error(body?.message ?? FALLBACK_ERROR);
  return body as MonthAvailability;
}

const hasAvailableDay = (data: MonthAvailability) => data.days.some((d) => d.slots.some((s) => s.available));

/** Loads month availability from the server, with a small per-month cache. */
export function useAvailability() {
  const [state, setState] = useState<State>({ status: "loading", data: null });
  const cache = useRef(new Map<string, MonthAvailability>());
  const requestSeq = useRef(0);

  const load = useCallback(async (month: string | null, options: { fresh?: boolean; skipEmpty?: boolean } = {}) => {
    const seq = ++requestSeq.current;
    const cached = month && !options.fresh ? cache.current.get(month) : undefined;
    if (cached) {
      setState({ status: "ready", data: cached });
      return;
    }
    setState((prev) => ({ status: "loading", data: prev.data }));
    try {
      let data = await fetchMonth(month);
      // At the end of a month, open directly on the next month with free slots.
      if (options.skipEmpty && !hasAvailableDay(data) && data.month < data.lastMonth) {
        cache.current.set(data.month, data);
        const [y, m] = data.month.split("-").map(Number);
        const next = new Date(Date.UTC(y, m, 1)).toISOString().slice(0, 7);
        data = await fetchMonth(next);
      }
      if (seq !== requestSeq.current) return;
      cache.current.set(data.month, data);
      setState({ status: "ready", data });
    } catch (error) {
      if (seq !== requestSeq.current) return;
      setState((prev) => ({
        status: "error",
        data: prev.data,
        message: error instanceof Error && error.message ? error.message : FALLBACK_ERROR,
      }));
    }
  }, []);

  useEffect(() => {
    // Initial load once mounted: the date of "today" comes from the server.
    void load(null, { skipEmpty: true });
  }, [load]);

  const showMonth = useCallback((month: string) => void load(month), [load]);

  /** Forgets cached months and reloads the visible one (after a conflict). */
  const refresh = useCallback(() => {
    cache.current.clear();
    const month = state.data?.month ?? null;
    void load(month, { fresh: true });
  }, [load, state.data?.month]);

  return { state, showMonth, refresh };
}

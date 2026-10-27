"use client";

import type { MonthAvailability } from "@/lib/availability";
import { daysInMonth, formatDateLong, formatMonthTitle, isoWeekday } from "@/lib/time";
import { ErrorText } from "./field";

const WEEKDAYS = [
  ["L", "lundi"],
  ["M", "mardi"],
  ["M", "mercredi"],
  ["J", "jeudi"],
  ["V", "vendredi"],
  ["S", "samedi"],
  ["D", "dimanche"],
] as const;

function shiftMonth(month: string, delta: number) {
  const [y, m] = month.split("-").map(Number);
  return new Date(Date.UTC(y, m - 1 + delta, 1)).toISOString().slice(0, 7);
}

type DayState = "available" | "full" | "closed";

type Props = {
  data: MonthAvailability | null;
  loading: boolean;
  errorMessage?: string;
  onRetry: () => void;
  onMonthChange: (month: string) => void;
  selectedDate: string;
  onSelect: (date: string) => void;
  error?: string;
};

function ChevronIcon({ direction }: { direction: "left" | "right" }) {
  return (
    <svg viewBox="0 0 20 20" aria-hidden="true" className="size-5" fill="none" stroke="currentColor" strokeWidth="1.8">
      <path strokeLinecap="round" strokeLinejoin="round" d={direction === "left" ? "M12 4.5 6.5 10l5.5 5.5" : "M8 4.5l5.5 5.5L8 15.5"} />
    </svg>
  );
}

export function DatePicker({ data, loading, errorMessage, onRetry, onMonthChange, selectedDate, onSelect, error }: Props) {
  const month = data?.month;
  const canPrev = !!data && data.month > data.firstMonth && !loading;
  const canNext = !!data && data.month < data.lastMonth && !loading;

  const days = new Map(data?.days.map((d) => [d.date, d]));
  const cells: { date: string; state: DayState }[] = [];
  if (month) {
    for (let i = 1; i <= daysInMonth(month); i++) {
      const date = `${month}-${String(i).padStart(2, "0")}`;
      const day = days.get(date);
      const state: DayState = !day ? "closed" : day.slots.some((s) => s.available) ? "available" : "full";
      cells.push({ date, state });
    }
  }
  const leading = month ? isoWeekday(`${month}-01`) - 1 : 0;
  const errorId = "date-error";

  return (
    <fieldset aria-describedby={error ? errorId : undefined}>
      <legend className="text-[19px] font-semibold tracking-[-0.015em] text-ink">Date d&apos;enlèvement</legend>

      <div className="mt-4 flex items-center justify-between">
        <p className="text-[16px] font-medium capitalize" aria-live="polite">
          {month ? formatMonthTitle(month) : <span className="text-muted">Chargement…</span>}
        </p>
        <div className="flex gap-1">
          <button
            type="button"
            onClick={() => month && onMonthChange(shiftMonth(month, -1))}
            disabled={!canPrev}
            aria-label="Mois précédent"
            className="flex size-10 items-center justify-center rounded-full text-ink transition-colors hover:bg-fill disabled:cursor-not-allowed disabled:text-faint disabled:hover:bg-transparent"
          >
            <ChevronIcon direction="left" />
          </button>
          <button
            type="button"
            onClick={() => month && onMonthChange(shiftMonth(month, 1))}
            disabled={!canNext}
            aria-label="Mois suivant"
            className="flex size-10 items-center justify-center rounded-full text-ink transition-colors hover:bg-fill disabled:cursor-not-allowed disabled:text-faint disabled:hover:bg-transparent"
          >
            <ChevronIcon direction="right" />
          </button>
        </div>
      </div>

      <div className="mt-3 grid grid-cols-7 text-center text-[12.5px] font-medium text-muted" aria-hidden="true">
        {WEEKDAYS.map(([letter, name]) => (
          <span key={name} className="py-1.5" title={name}>
            {letter}
          </span>
        ))}
      </div>

      {errorMessage && !data ? (
        <div className="flex min-h-[17rem] flex-col items-center justify-center gap-3 text-center" role="alert">
          <p className="max-w-[30ch] text-[15px] text-muted">{errorMessage}</p>
          <button
            type="button"
            onClick={onRetry}
            className="rounded-full border border-line px-4 py-2 text-[15px] font-medium transition-colors hover:border-ink"
          >
            Réessayer
          </button>
        </div>
      ) : !data ? (
        <div className="grid grid-cols-7 gap-y-1" aria-hidden="true">
          {Array.from({ length: 35 }, (_, i) => (
            <span key={i} className="flex aspect-square items-center justify-center">
              <span className="size-9 rounded-full bg-fill [animation:rc-pulse_1.4s_ease-in-out_infinite]" />
            </span>
          ))}
        </div>
      ) : (
        <div className={`grid grid-cols-7 gap-y-1 transition-opacity ${loading ? "opacity-50" : ""}`}>
          {Array.from({ length: leading }, (_, i) => (
            <span key={`blank-${i}`} aria-hidden="true" />
          ))}
          {cells.map(({ date, state }) => {
            const isToday = date === data.today;
            const label = `${formatDateLong(date)}${state === "full" ? ", complet" : state === "closed" ? ", indisponible" : ""}`;
            return (
              <label key={date} className="relative flex aspect-square max-h-14 items-center justify-center">
                <input
                  type="radio"
                  name="date"
                  value={date}
                  checked={selectedDate === date}
                  disabled={state !== "available"}
                  onChange={() => onSelect(date)}
                  aria-label={label}
                  className="peer sr-only"
                />
                <span
                  aria-hidden="true"
                  className={[
                    "relative flex size-10 items-center justify-center rounded-full text-[15.5px] transition-colors duration-150 sm:size-11",
                    "peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-ink",
                    state === "available"
                      ? "cursor-pointer font-medium text-ink hover:bg-fill peer-checked:bg-ink peer-checked:text-white"
                      : state === "full"
                        ? "text-faint line-through decoration-1"
                        : "text-faint",
                  ].join(" ")}
                >
                  {Number(date.slice(8))}
                  {isToday ? (
                    <span className="absolute bottom-[5px] left-1/2 size-1 -translate-x-1/2 rounded-full bg-current opacity-70" />
                  ) : null}
                </span>
              </label>
            );
          })}
        </div>
      )}

      {errorMessage && data ? (
        <p className="mt-2 text-[13.5px] text-muted" role="status">
          {errorMessage}{" "}
          <button type="button" onClick={onRetry} className="font-medium text-ink underline underline-offset-2">
            Réessayer
          </button>
        </p>
      ) : null}
      {error ? <ErrorText id={errorId}>{error}</ErrorText> : null}
    </fieldset>
  );
}


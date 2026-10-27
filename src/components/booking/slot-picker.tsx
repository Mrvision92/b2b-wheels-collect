"use client";

import type { PublicDay } from "@/lib/availability";
import { formatDayMonth } from "@/lib/time";
import { ErrorText } from "./field";

type Props = {
  day: PublicDay | null;
  mode: "ranges" | "times";
  selected: string;
  onSelect: (key: string) => void;
  error?: string;
};

export function SlotPicker({ day, mode, selected, onSelect, error }: Props) {
  const errorId = "slot-error";
  const title = mode === "ranges" ? "Plage d'enlèvement" : "Heure d'enlèvement";
  return (
    <fieldset aria-describedby={error ? errorId : undefined}>
      <legend className="text-[19px] font-semibold tracking-[-0.015em] text-ink">{title}</legend>
      {!day ? (
        <p className="mt-2 text-[15px] text-muted">Choisissez d&apos;abord une date.</p>
      ) : (
        <>
          <p className="mt-1 text-[15px] text-muted first-letter:uppercase">{formatDayMonth(day.date)}</p>
          <div className={`mt-4 grid gap-2.5 ${mode === "times" ? "grid-cols-3" : "grid-cols-2"}`}>
            {day.slots.map((slot) => (
              <label key={slot.key} className="relative block">
                <input
                  type="radio"
                  name="slot"
                  value={slot.key}
                  checked={selected === slot.key}
                  disabled={!slot.available}
                  onChange={() => onSelect(slot.key)}
                  aria-label={`${slot.label}${slot.available ? "" : ", complet"}`}
                  className="peer sr-only"
                />
                <span
                  aria-hidden="true"
                  className={[
                    "flex h-12 items-center justify-center rounded-full border text-[15.5px] transition-colors duration-150",
                    "peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-ink",
                    slot.available
                      ? "cursor-pointer border-line font-medium text-ink hover:border-ink peer-checked:border-ink peer-checked:bg-ink peer-checked:text-white"
                      : "border-hairline text-faint",
                  ].join(" ")}
                >
                  {slot.available ? (
                    slot.label
                  ) : (
                    <>
                      <span className="line-through decoration-1">{slot.label}</span>
                      <span className="ml-1.5 text-[12.5px] no-underline">complet</span>
                    </>
                  )}
                </span>
              </label>
            ))}
          </div>
        </>
      )}
      {error ? <ErrorText id={errorId}>{error}</ErrorText> : null}
    </fieldset>
  );
}

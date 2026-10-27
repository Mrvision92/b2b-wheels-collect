import type { BookingConfig } from "@/config/booking";
import type { CalendarClient, CalendarEvent } from "./calendar/types";
import { bookingWindow, isWithinBookingWindow, slotsForDate } from "./schedule";
import { addDays, daysInMonth, monthOf, zonedTimeToUtc } from "./time";

/**
 * Event titles that close a slot entirely, whatever the capacity.
 * Matched without accents or case: "FERMÉ", "Bloqué", "indisponible", "complet", "congé".
 */
const BLOCKING_WORDS = /\b(bloque|bloquee|ferme|fermee|fermeture|indisponible|complet|conge|conges)\b/;

function normalize(text: string): string {
  return text
    .normalize("NFD")
    .replace(/[̀-ͯ]/g, "")
    .toLowerCase();
}

export function isBlockingEvent(event: CalendarEvent): boolean {
  return event.allDay || BLOCKING_WORDS.test(normalize(event.summary));
}

/** Events that take a place in the agenda (not cancelled, not marked "Disponible"). */
export function isActiveEvent(event: CalendarEvent): boolean {
  return event.status !== "cancelled" && !event.transparent;
}

export function overlaps(event: CalendarEvent, start: Date, end: Date): boolean {
  return event.start.getTime() < end.getTime() && event.end.getTime() > start.getTime();
}

export type SlotOccupancy = {
  blocked: boolean;
  occupants: CalendarEvent[];
  available: boolean;
};

/**
 * Every active event overlapping the slot uses one place; a blocking event
 * (all-day, or titled "Fermé", "Bloqué"…) closes it.
 */
export function evaluateSlot(
  slot: { start: Date; end: Date },
  events: CalendarEvent[],
  capacity: number,
): SlotOccupancy {
  const occupants = events.filter((e) => isActiveEvent(e) && overlaps(e, slot.start, slot.end));
  const blocked = occupants.some(isBlockingEvent);
  return { blocked, occupants, available: !blocked && occupants.length < capacity };
}

/* ------------------------------------------------------------------ */
/* Month view sent to the browser                                      */
/* ------------------------------------------------------------------ */

/** Only what the booking calendar needs: never who booked what. */
export type PublicSlot = { key: string; label: string; available: boolean };
export type PublicDay = { date: string; slots: PublicSlot[] };
export type MonthAvailability = {
  month: string;
  today: string;
  firstMonth: string;
  lastMonth: string;
  mode: BookingConfig["mode"];
  days: PublicDay[];
};

export function monthRange(month: string): { first: string; last: string } {
  return { first: `${month}-01`, last: `${month}-${String(daysInMonth(month)).padStart(2, "0")}` };
}

export function bookableMonths(now: Date, config: BookingConfig) {
  const { today, lastDate } = bookingWindow(now, config);
  return { firstMonth: monthOf(today), lastMonth: monthOf(lastDate) };
}

export async function getMonthAvailability(
  month: string,
  deps: { calendar: CalendarClient; config: BookingConfig; now: Date },
): Promise<MonthAvailability> {
  const { calendar, config, now } = deps;
  const { today, lastDate } = bookingWindow(now, config);
  const { firstMonth, lastMonth } = bookableMonths(now, config);
  const base = { month, today, firstMonth, lastMonth, mode: config.mode };

  const range = monthRange(month);
  const from = range.first > today ? range.first : today;
  const to = range.last < lastDate ? range.last : lastDate;
  if (from > to) return { ...base, days: [] };

  const candidateDays: { date: string; slots: ReturnType<typeof slotsForDate> }[] = [];
  for (let date = from; date <= to; date = addDays(date, 1)) {
    const slots = slotsForDate(date, config).filter((s) => isWithinBookingWindow(s, now, config));
    if (slots.length > 0) candidateDays.push({ date, slots });
  }
  if (candidateDays.length === 0) return { ...base, days: [] };

  const timeMin = zonedTimeToUtc(from, "00:00") ?? new Date(`${from}T00:00:00Z`);
  const timeMax = zonedTimeToUtc(addDays(to, 1), "00:00") ?? new Date(`${addDays(to, 1)}T00:00:00Z`);
  const events = await calendar.listEvents({ timeMin, timeMax });

  return {
    ...base,
    days: candidateDays.map(({ date, slots }) => ({
      date,
      slots: slots.map((slot) => ({
        key: slot.key,
        label: slot.label,
        available: evaluateSlot(slot, events, config.capacityPerSlot).available,
      })),
    })),
  };
}

export function isValidMonth(value: string): boolean {
  return /^\d{4}-(0[1-9]|1[0-2])$/.test(value);
}

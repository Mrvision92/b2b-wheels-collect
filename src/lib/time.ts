/**
 * Calendar-date and wall-clock helpers for Europe/Brussels.
 *
 * Dates are handled as "YYYY-MM-DD" strings and times as "HH:MM" strings in
 * the business time zone; instants are plain Date objects (UTC). Conversions
 * go through Intl so daylight-saving changes are always respected.
 */

export const TIME_ZONE = "Europe/Brussels";

const partsFormatter = new Intl.DateTimeFormat("en-US", {
  timeZone: TIME_ZONE,
  hourCycle: "h23",
  year: "numeric",
  month: "2-digit",
  day: "2-digit",
  hour: "2-digit",
  minute: "2-digit",
  second: "2-digit",
});

export type ZonedParts = {
  year: number;
  month: number;
  day: number;
  hour: number;
  minute: number;
  second: number;
};

export function zonedParts(instant: Date): ZonedParts {
  const parts: Record<string, number> = {};
  for (const part of partsFormatter.formatToParts(instant)) {
    if (part.type !== "literal") parts[part.type] = Number(part.value);
  }
  return {
    year: parts.year,
    month: parts.month,
    day: parts.day,
    hour: parts.hour === 24 ? 0 : parts.hour,
    minute: parts.minute,
    second: parts.second,
  };
}

/** Offset of Brussels from UTC at the given instant, in minutes. */
function offsetMinutes(instant: Date): number {
  const p = zonedParts(instant);
  const asUtc = Date.UTC(p.year, p.month - 1, p.day, p.hour, p.minute, p.second);
  const truncated = Math.floor(instant.getTime() / 1000) * 1000;
  return Math.round((asUtc - truncated) / 60000);
}

const DATE_RE = /^(\d{4})-(\d{2})-(\d{2})$/;
const TIME_RE = /^([01]\d|2[0-3]):([0-5]\d)$/;

export function isValidDateString(value: string): boolean {
  const m = DATE_RE.exec(value);
  if (!m) return false;
  const [y, mo, d] = [Number(m[1]), Number(m[2]), Number(m[3])];
  const probe = new Date(Date.UTC(y, mo - 1, d));
  return probe.getUTCFullYear() === y && probe.getUTCMonth() === mo - 1 && probe.getUTCDate() === d;
}

export function isValidTimeString(value: string): boolean {
  return TIME_RE.test(value);
}

/**
 * Converts a Brussels wall-clock time to an instant. Returns null for times
 * that do not exist (the skipped hour when clocks go forward).
 */
export function zonedTimeToUtc(date: string, time: string): Date | null {
  if (!isValidDateString(date) || !isValidTimeString(time)) return null;
  const [y, mo, d] = date.split("-").map(Number);
  const [h, mi] = time.split(":").map(Number);
  const wall = Date.UTC(y, mo - 1, d, h, mi);
  let utc = wall - offsetMinutes(new Date(wall)) * 60000;
  const corrected = offsetMinutes(new Date(utc));
  utc = wall - corrected * 60000;
  const check = zonedParts(new Date(utc));
  if (check.year !== y || check.month !== mo || check.day !== d || check.hour !== h || check.minute !== mi) {
    return null;
  }
  return new Date(utc);
}

const pad = (n: number) => String(n).padStart(2, "0");

export function zonedDateString(instant: Date): string {
  const p = zonedParts(instant);
  return `${p.year}-${pad(p.month)}-${pad(p.day)}`;
}

export function zonedTimeString(instant: Date): string {
  const p = zonedParts(instant);
  return `${pad(p.hour)}:${pad(p.minute)}`;
}

export function addDays(date: string, days: number): string {
  const [y, m, d] = date.split("-").map(Number);
  const t = new Date(Date.UTC(y, m - 1, d + days));
  return `${t.getUTCFullYear()}-${pad(t.getUTCMonth() + 1)}-${pad(t.getUTCDate())}`;
}

/** ISO weekday: 1 = Monday … 7 = Sunday. */
export function isoWeekday(date: string): 1 | 2 | 3 | 4 | 5 | 6 | 7 {
  const [y, m, d] = date.split("-").map(Number);
  const day = new Date(Date.UTC(y, m - 1, d)).getUTCDay();
  return (day === 0 ? 7 : day) as 1 | 2 | 3 | 4 | 5 | 6 | 7;
}

export function timeToMinutes(time: string): number {
  const [h, m] = time.split(":").map(Number);
  return h * 60 + m;
}

export function minutesToTime(minutes: number): string {
  return `${pad(Math.floor(minutes / 60))}:${pad(minutes % 60)}`;
}

/** "YYYY-MM" of a "YYYY-MM-DD" date. */
export function monthOf(date: string): string {
  return date.slice(0, 7);
}

export function addMonths(month: string, delta: number): string {
  const [y, m] = month.split("-").map(Number);
  const t = new Date(Date.UTC(y, m - 1 + delta, 1));
  return `${t.getUTCFullYear()}-${pad(t.getUTCMonth() + 1)}`;
}

export function daysInMonth(month: string): number {
  const [y, m] = month.split("-").map(Number);
  return new Date(Date.UTC(y, m, 0)).getUTCDate();
}

/* ------------------------------------------------------------------ */
/* French labels                                                       */
/* ------------------------------------------------------------------ */

const longDate = new Intl.DateTimeFormat("fr-BE", {
  timeZone: "UTC",
  weekday: "long",
  day: "numeric",
  month: "long",
  year: "numeric",
});

const shortDate = new Intl.DateTimeFormat("fr-BE", {
  timeZone: "UTC",
  weekday: "short",
  day: "numeric",
  month: "short",
});

const dayMonth = new Intl.DateTimeFormat("fr-BE", {
  timeZone: "UTC",
  weekday: "long",
  day: "numeric",
  month: "long",
});

const monthTitle = new Intl.DateTimeFormat("fr-BE", { timeZone: "UTC", month: "long", year: "numeric" });

function utcNoon(date: string): Date {
  const [y, m, d] = date.split("-").map(Number);
  return new Date(Date.UTC(y, m - 1, d, 12));
}

/** "jeudi 29 octobre 2026" */
export function formatDateLong(date: string): string {
  return longDate.format(utcNoon(date));
}

/** "jeudi 29 octobre" */
export function formatDayMonth(date: string): string {
  return dayMonth.format(utcNoon(date));
}

/** "jeu. 29 oct." */
export function formatDateShort(date: string): string {
  return shortDate.format(utcNoon(date));
}

/** "novembre 2026" */
export function formatMonthTitle(month: string): string {
  return monthTitle.format(utcNoon(`${month}-01`));
}

/** "9 h", "9 h 30", "14 h" — French typographic convention. */
export function formatClock(time: string): string {
  const [h, m] = time.split(":").map(Number);
  return m === 0 ? `${h} h` : `${h} h ${pad(m)}`;
}

/** "9 h – 11 h" for ranges, "9 h 30" for precise times. */
export function formatSlotLabel(start: string, end: string, mode: "ranges" | "times"): string {
  return mode === "ranges" ? `${formatClock(start)} – ${formatClock(end)}` : formatClock(start);
}

/** "entre 9 h et 11 h" or "à 9 h 30" — used inside sentences. */
export function formatSlotPhrase(start: string, end: string, mode: "ranges" | "times"): string {
  return mode === "ranges" ? `entre ${formatClock(start)} et ${formatClock(end)}` : `à ${formatClock(start)}`;
}

export function capitalize(value: string): string {
  return value.charAt(0).toUpperCase() + value.slice(1);
}

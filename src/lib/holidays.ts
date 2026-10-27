import { addDays } from "./time";

/** Easter Sunday (Gregorian calendar, anonymous algorithm). */
function easterSunday(year: number): string {
  const a = year % 19;
  const b = Math.floor(year / 100);
  const c = year % 100;
  const d = Math.floor(b / 4);
  const e = b % 4;
  const f = Math.floor((b + 8) / 25);
  const g = Math.floor((b - f + 1) / 3);
  const h = (19 * a + b - d - g + 15) % 30;
  const i = Math.floor(c / 4);
  const k = c % 4;
  const l = (32 + 2 * e + 2 * i - h - k) % 7;
  const m = Math.floor((a + 11 * h + 22 * l) / 451);
  const month = Math.floor((h + l - 7 * m + 114) / 31);
  const day = ((h + l - 7 * m + 114) % 31) + 1;
  return `${year}-${String(month).padStart(2, "0")}-${String(day).padStart(2, "0")}`;
}

const cache = new Map<number, Map<string, string>>();

/** The ten Belgian legal public holidays of a year, keyed by date. */
export function belgianPublicHolidays(year: number): Map<string, string> {
  const cached = cache.get(year);
  if (cached) return cached;
  const easter = easterSunday(year);
  const holidays = new Map<string, string>([
    [`${year}-01-01`, "Jour de l'an"],
    [addDays(easter, 1), "Lundi de Pâques"],
    [`${year}-05-01`, "Fête du Travail"],
    [addDays(easter, 39), "Ascension"],
    [addDays(easter, 50), "Lundi de Pentecôte"],
    [`${year}-07-21`, "Fête nationale"],
    [`${year}-08-15`, "Assomption"],
    [`${year}-11-01`, "Toussaint"],
    [`${year}-11-11`, "Armistice"],
    [`${year}-12-25`, "Noël"],
  ]);
  cache.set(year, holidays);
  return holidays;
}

export function isBelgianPublicHoliday(date: string): boolean {
  return belgianPublicHolidays(Number(date.slice(0, 4))).has(date);
}

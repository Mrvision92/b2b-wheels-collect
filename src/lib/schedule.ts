import type { BookingConfig } from "@/config/booking";
import { isBelgianPublicHoliday } from "./holidays";
import {
  addDays,
  formatSlotLabel,
  isoWeekday,
  isValidDateString,
  minutesToTime,
  timeToMinutes,
  zonedDateString,
  zonedTimeToUtc,
} from "./time";

/** A slot as configured for a given day, before looking at the agenda. */
export type ScheduledSlot = {
  /** Stable identifier within the day, e.g. "0900". */
  key: string;
  date: string;
  startTime: string;
  endTime: string;
  start: Date;
  end: Date;
  label: string;
};

export function isOpenDay(date: string, config: BookingConfig): boolean {
  if (config.closedDates.includes(date)) return false;
  if (config.closeOnPublicHolidays && isBelgianPublicHoliday(date)) return false;
  const windows = config.weeklySchedule[isoWeekday(date)] ?? [];
  return windows.length > 0;
}

/** Every configured slot of a day, ignoring notice and booking-window rules. */
export function slotsForDate(date: string, config: BookingConfig): ScheduledSlot[] {
  if (!isValidDateString(date) || !isOpenDay(date, config)) return [];
  const windows = config.weeklySchedule[isoWeekday(date)] ?? [];
  const step = config.slotStepMinutes ?? config.slotMinutes;
  const slots: ScheduledSlot[] = [];
  for (const window of windows) {
    const close = timeToMinutes(window.to);
    for (let t = timeToMinutes(window.from); t + config.slotMinutes <= close; t += step) {
      const startTime = minutesToTime(t);
      const endTime = minutesToTime(t + config.slotMinutes);
      const start = zonedTimeToUtc(date, startTime);
      const end = zonedTimeToUtc(date, endTime);
      // Wall-clock times that do not exist on a DST change day are skipped.
      if (!start || !end) continue;
      slots.push({
        key: startTime.replace(":", ""),
        date,
        startTime,
        endTime,
        start,
        end,
        label: formatSlotLabel(startTime, endTime, config.mode),
      });
    }
  }
  return slots.sort((a, b) => a.start.getTime() - b.start.getTime());
}

/** The span of dates a garage can currently choose from. */
export function bookingWindow(now: Date, config: BookingConfig) {
  const today = zonedDateString(now);
  return {
    today,
    lastDate: addDays(today, config.maxAdvanceDays),
    earliestStart: new Date(now.getTime() + config.minNoticeHours * 3600_000),
  };
}

/** True when a slot respects the notice delay and the advance-booking limit. */
export function isWithinBookingWindow(slot: ScheduledSlot, now: Date, config: BookingConfig): boolean {
  const { lastDate, earliestStart } = bookingWindow(now, config);
  return slot.start.getTime() >= earliestStart.getTime() && slot.date <= lastDate;
}

export function findSlot(date: string, key: string, config: BookingConfig): ScheduledSlot | null {
  return slotsForDate(date, config).find((slot) => slot.key === key) ?? null;
}

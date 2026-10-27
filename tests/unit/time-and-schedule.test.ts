import { describe, expect, it } from "vitest";
import { belgianPublicHolidays } from "@/lib/holidays";
import { bookingWindow, findSlot, isWithinBookingWindow, slotsForDate } from "@/lib/schedule";
import {
  formatDateLong,
  formatSlotLabel,
  formatSlotPhrase,
  isoWeekday,
  isValidDateString,
  zonedDateString,
  zonedTimeToUtc,
} from "@/lib/time";
import { NOW, testConfig } from "../support/fixtures";

describe("Europe/Brussels time handling", () => {
  it("converts winter and summer wall-clock times to the right instant", () => {
    expect(zonedTimeToUtc("2026-11-03", "09:00")?.toISOString()).toBe("2026-11-03T08:00:00.000Z");
    expect(zonedTimeToUtc("2026-07-01", "09:00")?.toISOString()).toBe("2026-07-01T07:00:00.000Z");
  });

  it("handles the days the clocks change", () => {
    // 28 March 2027: 02:00 → 03:00. 25 October 2026: 03:00 → 02:00.
    expect(zonedTimeToUtc("2027-03-28", "09:00")?.toISOString()).toBe("2027-03-28T07:00:00.000Z");
    expect(zonedTimeToUtc("2027-03-27", "09:00")?.toISOString()).toBe("2027-03-27T08:00:00.000Z");
    expect(zonedTimeToUtc("2026-10-25", "09:00")?.toISOString()).toBe("2026-10-25T08:00:00.000Z");
    expect(zonedTimeToUtc("2026-10-24", "09:00")?.toISOString()).toBe("2026-10-24T07:00:00.000Z");
    // The skipped hour does not exist.
    expect(zonedTimeToUtc("2027-03-28", "02:30")).toBeNull();
  });

  it("reads the local date of an instant", () => {
    expect(zonedDateString(new Date("2026-11-02T23:30:00Z"))).toBe("2026-11-03");
    expect(isoWeekday("2026-11-02")).toBe(1);
    expect(isoWeekday("2026-11-08")).toBe(7);
  });

  it("rejects impossible dates", () => {
    expect(isValidDateString("2026-02-30")).toBe(false);
    expect(isValidDateString("2026-13-01")).toBe(false);
    expect(isValidDateString("2026-11-03")).toBe(true);
  });

  it("formats French labels", () => {
    expect(formatDateLong("2026-11-03")).toBe("mardi 3 novembre 2026");
    expect(formatSlotLabel("09:00", "11:00", "ranges")).toBe("9 h – 11 h");
    expect(formatSlotLabel("09:30", "10:30", "times")).toBe("9 h 30");
    expect(formatSlotPhrase("09:00", "11:00", "ranges")).toBe("entre 9 h et 11 h");
  });
});

describe("Belgian public holidays", () => {
  it("includes the moveable feasts", () => {
    const h2027 = belgianPublicHolidays(2027);
    expect(h2027.get("2027-03-29")).toBe("Lundi de Pâques");
    expect(h2027.get("2027-05-06")).toBe("Ascension");
    expect(h2027.get("2027-05-17")).toBe("Lundi de Pentecôte");
    expect(belgianPublicHolidays(2026).size).toBe(10);
  });
});

describe("slot schedule", () => {
  it("splits opening hours into slots of the configured length", () => {
    const slots = slotsForDate("2026-11-03", testConfig);
    expect(slots.map((s) => s.key)).toEqual(["0900", "1100", "1300", "1500"]);
    expect(slots[0].label).toBe("9 h – 11 h");
    expect(slots[0].start.toISOString()).toBe("2026-11-03T08:00:00.000Z");
  });

  it("supports precise times with a custom step", () => {
    const slots = slotsForDate("2026-11-03", { ...testConfig, mode: "times", slotMinutes: 60, slotStepMinutes: 30 });
    expect(slots.map((s) => s.label).slice(0, 3)).toEqual(["9 h", "9 h 30", "10 h"]);
    expect(slots.at(-1)?.label).toBe("16 h");
  });

  it("closes weekends, public holidays and exceptional closures", () => {
    expect(slotsForDate("2026-11-07", testConfig)).toEqual([]); // Saturday
    expect(slotsForDate("2026-11-11", testConfig)).toEqual([]); // Armistice
    expect(slotsForDate("2026-11-04", { ...testConfig, closedDates: ["2026-11-04"] })).toEqual([]);
    expect(slotsForDate("2026-11-11", { ...testConfig, closeOnPublicHolidays: false })).toHaveLength(4);
  });

  it("enforces the notice delay and the advance limit", () => {
    const window = bookingWindow(NOW, testConfig);
    expect(window.today).toBe("2026-11-02");
    const today = findSlot("2026-11-02", "1500", testConfig)!;
    const tomorrow = findSlot("2026-11-03", "0900", testConfig)!;
    expect(isWithinBookingWindow(today, NOW, testConfig)).toBe(false);
    expect(isWithinBookingWindow(tomorrow, NOW, testConfig)).toBe(true);
    const tooFar = findSlot("2027-01-04", "0900", testConfig)!;
    expect(isWithinBookingWindow(tooFar, NOW, testConfig)).toBe(false);
  });

  it("returns null for unknown slots", () => {
    expect(findSlot("2026-11-03", "0800", testConfig)).toBeNull();
    expect(findSlot("not-a-date", "0900", testConfig)).toBeNull();
  });
});

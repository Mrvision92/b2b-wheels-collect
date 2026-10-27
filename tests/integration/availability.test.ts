import { describe, expect, it } from "vitest";
import { evaluateSlot, getMonthAvailability, isBlockingEvent } from "@/lib/availability";
import { createBooking } from "@/lib/booking/create-booking";
import type { CalendarEvent } from "@/lib/calendar/types";
import { findSlot } from "@/lib/schedule";
import { zonedTimeToUtc } from "@/lib/time";
import { bookingInput, makeDeps, NOW, testConfig } from "../support/fixtures";

const at = (date: string, time: string) => zonedTimeToUtc(date, time)!;

function event(partial: Partial<CalendarEvent> & { start: Date; end: Date }): CalendarEvent {
  return { id: "e1", status: "confirmed", summary: "", allDay: false, transparent: false, privateProps: {}, ...partial };
}

describe("slot occupancy rules", () => {
  const slot = findSlot("2026-11-03", "0900", testConfig)!;

  it("counts every active overlapping event against capacity", () => {
    const one = [event({ start: at("2026-11-03", "09:30"), end: at("2026-11-03", "10:00") })];
    expect(evaluateSlot(slot, one, 2).available).toBe(true);
    const two = [...one, event({ id: "e2", start: at("2026-11-03", "10:00"), end: at("2026-11-03", "12:00") })];
    expect(evaluateSlot(slot, two, 2).available).toBe(false);
  });

  it("ignores cancelled, free (transparent) and non-overlapping events", () => {
    const events = [
      event({ status: "cancelled", start: slot.start, end: slot.end }),
      event({ id: "e2", transparent: true, start: slot.start, end: slot.end }),
      event({ id: "e3", start: at("2026-11-03", "11:00"), end: at("2026-11-03", "12:00") }),
    ];
    expect(evaluateSlot(slot, events, 1).available).toBe(true);
  });

  it("treats all-day events and closing words as blocks", () => {
    expect(isBlockingEvent(event({ summary: "FERMÉ", start: slot.start, end: slot.end }))).toBe(true);
    expect(isBlockingEvent(event({ summary: "Camion bloqué au garage", start: slot.start, end: slot.end }))).toBe(true);
    expect(isBlockingEvent(event({ summary: "Congés", start: slot.start, end: slot.end }))).toBe(true);
    expect(isBlockingEvent(event({ summary: "Complet", start: slot.start, end: slot.end }))).toBe(true);
    expect(isBlockingEvent(event({ summary: "Fermeture des comptes", start: slot.start, end: slot.end }))).toBe(true);
    expect(isBlockingEvent(event({ summary: "Formation", start: slot.start, end: slot.end }))).toBe(false);
    expect(isBlockingEvent(event({ allDay: true, summary: "Salon", start: slot.start, end: slot.end }))).toBe(true);
    const blocked = [event({ summary: "Indisponible", start: slot.start, end: slot.end })];
    expect(evaluateSlot(slot, blocked, 10).available).toBe(false);
  });
});

describe("month availability sent to the browser", () => {
  it("lists only bookable days and marks full slots", async () => {
    const { deps, calendar } = makeDeps({ config: { capacityPerSlot: 1 } });
    await calendar.addManualEvent({ summary: "Tournée", start: at("2026-11-03", "09:00"), end: at("2026-11-03", "11:00") });
    await calendar.addManualEvent({
      summary: "FERMÉ",
      allDay: true,
      start: at("2026-11-05", "00:00"),
      end: at("2026-11-06", "00:00"),
    });

    const month = await getMonthAvailability("2026-11", { calendar, config: deps.config, now: NOW });
    expect(month.today).toBe("2026-11-02");
    expect(month.firstMonth).toBe("2026-11");
    expect(month.lastMonth).toBe("2027-01");

    const dates = month.days.map((d) => d.date);
    expect(dates[0]).toBe("2026-11-03"); // today is past the notice delay
    expect(dates).not.toContain("2026-11-07"); // Saturday
    expect(dates).not.toContain("2026-11-11"); // public holiday

    const tuesday = month.days.find((d) => d.date === "2026-11-03")!;
    expect(tuesday.slots.find((s) => s.key === "0900")?.available).toBe(false);
    expect(tuesday.slots.find((s) => s.key === "1100")?.available).toBe(true);
    const thursday = month.days.find((d) => d.date === "2026-11-05")!;
    expect(thursday.slots.every((s) => !s.available)).toBe(true);
  });

  it("never exposes who booked: no names, emails, phones or references", async () => {
    const { deps, calendar } = makeDeps();
    const result = await createBooking(bookingInput(), deps);
    expect(result.ok).toBe(true);

    const month = await getMonthAvailability("2026-11", { calendar, config: deps.config, now: NOW });
    const payload = JSON.stringify(month);
    for (const secret of ["Garage Dupont", "Marie", "garage-dupont", "470123456", "Station", "BWC-"]) {
      expect(payload).not.toContain(secret);
    }
    const slot = month.days[0].slots[0];
    expect(Object.keys(slot).sort()).toEqual(["available", "key", "label"]);
  });

  it("returns no days for a month outside the booking window", async () => {
    const { deps, calendar } = makeDeps();
    const past = await getMonthAvailability("2026-09", { calendar, config: deps.config, now: NOW });
    expect(past.days).toEqual([]);
  });
});

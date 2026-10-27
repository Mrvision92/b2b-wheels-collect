import { describe, expect, it } from "vitest";
import { createBooking, holdsPlace } from "@/lib/booking/create-booking";
import {
  completeNotifications,
  MAX_BACKGROUND_ATTEMPTS,
  retryPendingNotifications,
} from "@/lib/booking/notifications";
import { fromCalendarEvent, PROP } from "@/lib/booking/record";
import { CalendarUnavailableError } from "@/lib/calendar/types";
import { MailerError } from "@/lib/email/mailer";
import { findSlot } from "@/lib/schedule";
import { zonedTimeToUtc } from "@/lib/time";
import { bookingInput, makeDeps, noLock, NOW, testConfig } from "../support/fixtures";

const at = (date: string, time: string) => zonedTimeToUtc(date, time)!;
const activeIn = async (calendar: ReturnType<typeof makeDeps>["calendar"]) =>
  (await calendar.allEvents()).filter((e) => e.status !== "cancelled");

describe("valid booking", () => {
  it("creates the agenda event, sends the confirmation and keeps every detail", async () => {
    const { deps, calendar, mailer } = makeDeps();
    const result = await createBooking(bookingInput({ comment: "Avec pneus\nAccès par l'arrière" }), deps);

    expect(result.ok).toBe(true);
    if (!result.ok) return;
    expect(result.alreadyExisted).toBe(false);
    expect(result.garageEmail).toBe("sent");
    expect(result.booking.reference).toMatch(/^BWC-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}$/);

    const [event] = await activeIn(calendar);
    expect(event.summary).toBe("ENLÈVEMENT — Garage Dupont — 4 jantes");
    expect(event.location).toBe("Rue de la Station 12, 1000 Bruxelles, Belgique");
    expect(event.start.toISOString()).toBe("2026-11-03T08:00:00.000Z");
    expect(event.end.toISOString()).toBe("2026-11-03T10:00:00.000Z");
    expect(event.description).toContain("+32 470 12 34 56");
    expect(event.description).toContain("atelier@garage-dupont.be");
    expect(event.description).toContain("Commentaire : Avec pneus");
    expect(event.description).toContain(result.booking.reference);
    expect(event.description).toContain("https://www.google.com/maps/search/");

    expect(mailer.delivered).toHaveLength(1);
    const [email] = mailer.delivered;
    expect(email.to).toEqual(["atelier@garage-dupont.be"]);
    expect(email.subject).toBe(`Confirmation de votre enlèvement — ${result.booking.reference}`);
    expect(email.text).toContain("Bonjour Marie Dupont,");
    expect(email.text).toContain("Nombre de jantes : 4");
    expect(email.replyTo).toBe("planning@vbm.test");
  });

  it("notifies VBM afterwards and records both deliveries on the event", async () => {
    const { deps, calendar, mailer } = makeDeps();
    const result = await createBooking(bookingInput(), deps);
    if (!result.ok) throw new Error("booking failed");
    await completeNotifications(result.booking, result.garageEmail, deps);

    const vbm = mailer.delivered.find((e) => e.to.includes("planning@vbm.test"))!;
    expect(vbm.subject).toBe("Nouvel enlèvement — Garage Dupont — Mardi 3 novembre 2026");
    expect(vbm.html).toContain("https://www.google.com/maps/search/?api=1&amp;query=");
    expect(vbm.html).toContain('href="tel:+32470123456"');
    expect(vbm.html).toContain("mailto:atelier@garage-dupont.be");
    expect(vbm.replyTo).toBe("atelier@garage-dupont.be");

    const [event] = await activeIn(calendar);
    expect(event.privateProps[PROP.garageMail]).toBe("sent");
    expect(event.privateProps[PROP.vbmMail]).toBe("sent");
  });

  it("escapes HTML typed in the form", async () => {
    const { deps, mailer } = makeDeps();
    const result = await createBooking(bookingInput({ garageName: '<img src=x onerror="alert(1)">', comment: "<b>hi</b>" }), deps);
    if (!result.ok) throw new Error("booking failed");
    await completeNotifications(result.booking, result.garageEmail, deps);
    for (const email of mailer.delivered) {
      expect(email.html).not.toContain("<img src=x");
      expect(email.html).not.toContain("<b>hi</b>");
      expect(email.html).toContain("&lt;img src=x onerror=&quot;alert(1)&quot;&gt;");
    }
  });
});

describe("dates and slots that cannot be booked", () => {
  it.each([
    ["a past date", { date: "2026-10-30" }],
    ["today, inside the notice delay", { date: "2026-11-02", slot: "1500" }],
    ["a weekend", { date: "2026-11-07" }],
    ["a public holiday", { date: "2026-11-11" }],
    ["beyond the advance limit", { date: "2027-02-01" }],
    ["a slot that does not exist", { slot: "0800" }],
  ])("refuses %s", async (_label, overrides) => {
    const { deps, calendar, mailer } = makeDeps();
    const result = await createBooking(bookingInput(overrides), deps);
    expect(result).toMatchObject({ ok: false, code: "slot_closed" });
    expect(await calendar.allEvents()).toHaveLength(0);
    expect(mailer.attempts).toBe(0);
  });

  it("refuses a full slot", async () => {
    const { deps, calendar } = makeDeps({ config: { capacityPerSlot: 1 } });
    await calendar.addManualEvent({ summary: "Tournée Anderlecht", start: at("2026-11-03", "09:00"), end: at("2026-11-03", "11:00") });
    const result = await createBooking(bookingInput(), deps);
    expect(result).toMatchObject({ ok: false, code: "slot_unavailable" });
  });

  it("refuses a slot blocked by VBM in Google Agenda", async () => {
    const { deps, calendar } = makeDeps({ config: { capacityPerSlot: 5 } });
    await calendar.addManualEvent({ summary: "BLOQUÉ", start: at("2026-11-03", "08:00"), end: at("2026-11-03", "12:00") });
    expect(await createBooking(bookingInput(), deps)).toMatchObject({ ok: false, code: "slot_unavailable" });
  });

  it("frees the place again when VBM deletes the event in Google Agenda", async () => {
    const { deps, calendar } = makeDeps({ config: { capacityPerSlot: 1 } });
    const first = await createBooking(bookingInput(), deps);
    if (!first.ok) throw new Error("booking failed");
    expect(await createBooking(bookingInput({ email: "autre@garage.be", street: "Avenue Louise 1" }), deps)).toMatchObject({
      ok: false,
    });
    await calendar.deleteEvent(first.booking.eventId);
    const again = await createBooking(bookingInput({ email: "autre@garage.be", street: "Avenue Louise 1" }), deps);
    expect(again.ok).toBe(true);
  });
});

describe("concurrency and duplicates", () => {
  it("lets exactly one of several simultaneous garages take the last place", async () => {
    const { deps, calendar, mailer } = makeDeps({ config: { capacityPerSlot: 1 }, calendar: { latencyMs: 15 } });
    const attempts = Array.from({ length: 8 }, (_, i) =>
      createBooking(bookingInput({ email: `garage${i}@test.be`, garageName: `Garage ${i}` }), deps),
    );
    const results = await Promise.all(attempts);
    expect(results.filter((r) => r.ok)).toHaveLength(1);
    expect(results.filter((r) => !r.ok).every((r) => !r.ok && r.code === "slot_unavailable")).toBe(true);
    expect(await activeIn(calendar)).toHaveLength(1);
    expect(mailer.delivered).toHaveLength(1);
  });

  it("respects capacity even without a shared lock (several servers) thanks to the post-insert check", async () => {
    const { deps, calendar } = makeDeps({ lock: noLock, config: { capacityPerSlot: 2 }, calendar: { latencyMs: 20 } });
    const results = await Promise.all(
      Array.from({ length: 6 }, (_, i) => createBooking(bookingInput({ email: `g${i}@test.be` }), deps)),
    );
    expect(results.filter((r) => r.ok).length).toBeLessThanOrEqual(2);
    expect(results.filter((r) => r.ok).length).toBeGreaterThanOrEqual(1);
    expect((await activeIn(calendar)).length).toBe(results.filter((r) => r.ok).length);
  });

  it("treats a double click (same request sent twice at once) as a single booking", async () => {
    const { deps, calendar, mailer } = makeDeps({ calendar: { latencyMs: 10 } });
    const input = bookingInput();
    const [a, b] = await Promise.all([createBooking(input, deps), createBooking(input, deps)]);
    expect(a.ok && b.ok).toBe(true);
    if (!a.ok || !b.ok) return;
    expect(a.booking.reference).toBe(b.booking.reference);
    expect([a.alreadyExisted, b.alreadyExisted].sort()).toEqual([false, true]);
    expect(await activeIn(calendar)).toHaveLength(1);
    expect(mailer.delivered).toHaveLength(1);
  });

  it("returns the existing booking when the browser resends after a lost connection", async () => {
    const { deps, calendar, mailer } = makeDeps();
    const input = bookingInput();
    const first = await createBooking(input, deps);
    const retry = await createBooking(input, deps);
    expect(retry).toMatchObject({ ok: true, alreadyExisted: true });
    if (!first.ok || !retry.ok) return;
    expect(retry.booking.reference).toBe(first.booking.reference);
    expect(await activeIn(calendar)).toHaveLength(1);
    expect(mailer.delivered).toHaveLength(1);
  });

  it("recognises the same garage re-booking the same slot after a page reload", async () => {
    const { deps, calendar } = makeDeps();
    const first = await createBooking(bookingInput(), deps);
    const reloaded = await createBooking(bookingInput({ email: "ATELIER@garage-dupont.be", street: "rue de la station, 12" }), deps);
    expect(reloaded).toMatchObject({ ok: true, alreadyExisted: true });
    if (!first.ok || !reloaded.ok) return;
    expect(reloaded.booking.reference).toBe(first.booking.reference);
    expect(await activeIn(calendar)).toHaveLength(1);
  });

  it("ranks places by agenda creation time", () => {
    const slot = findSlot("2026-11-03", "0900", testConfig)!;
    const mk = (id: string, created: string) => ({
      id,
      status: "confirmed" as const,
      summary: "",
      allDay: false,
      transparent: false,
      privateProps: {},
      start: slot.start,
      end: slot.end,
      created: new Date(created),
    });
    const events = [mk("b", "2026-11-02T10:00:01Z"), mk("a", "2026-11-02T10:00:00Z"), mk("c", "2026-11-02T10:00:02Z")];
    expect(holdsPlace("a", events, slot, 2)).toBe(true);
    expect(holdsPlace("b", events, slot, 2)).toBe(true);
    expect(holdsPlace("c", events, slot, 2)).toBe(false);
  });
});

describe("Google Agenda failures", () => {
  it("does not confirm anything and sends no email when the agenda cannot be reached", async () => {
    const { deps, mailer } = makeDeps({ calendar: { failure: () => new Error("ECONNRESET") } });
    await expect(createBooking(bookingInput(), deps)).rejects.toBeInstanceOf(CalendarUnavailableError);
    expect(mailer.attempts).toBe(0);
  });

  it("does not confirm when the insertion itself fails", async () => {
    const { deps, calendar, mailer } = makeDeps({
      calendar: { failure: (op) => (op === "insert" ? new Error("503 backend error") : undefined) },
    });
    await expect(createBooking(bookingInput(), deps)).rejects.toBeInstanceOf(CalendarUnavailableError);
    expect(await calendar.allEvents()).toHaveLength(0);
    expect(mailer.attempts).toBe(0);
  });

  it("keeps a booking whose event exists even if the extra verification read fails", async () => {
    let lists = 0;
    const { deps } = makeDeps({
      calendar: { failure: (op) => (op === "list" && ++lists === 2 ? new Error("timeout") : undefined) },
    });
    const result = await createBooking(bookingInput(), deps);
    expect(result.ok).toBe(true);
  });
});

describe("email failures", () => {
  it("keeps the booking, reports the email as pending, and retries later without duplicates", async () => {
    const { deps, calendar, mailer } = makeDeps();
    mailer.failWith = () => new MailerError("Resend 503", true);

    const result = await createBooking(bookingInput(), deps);
    expect(result).toMatchObject({ ok: true, garageEmail: "pending" });
    if (!result.ok) return;
    expect(mailer.attempts).toBe(3); // three quick attempts during the request
    await completeNotifications(result.booking, result.garageEmail, deps);

    let [event] = await activeIn(calendar);
    expect(event.privateProps[PROP.garageMail]).toBe("pending");
    expect(event.privateProps[PROP.vbmMail]).toBe("pending");

    // Service is back. The retry job runs later (the event is older than 2 minutes).
    mailer.failWith = null;
    const later = { ...deps, now: () => new Date(Date.now() + 10 * 60_000) };
    const summary = await retryPendingNotifications(later);
    expect(summary).toMatchObject({ sent: 2, stillPending: 0, failed: 0 });
    expect(mailer.delivered).toHaveLength(2);
    expect(mailer.delivered[0].to).toEqual(["atelier@garage-dupont.be"]);
    // The garage confirmation went out first, so VBM is not told it is missing.
    expect(mailer.delivered[1].html).not.toContain("pas encore pu être envoyé au garage");

    [event] = await activeIn(calendar);
    expect(event.privateProps[PROP.garageMail]).toBe("sent");
    expect(event.privateProps[PROP.vbmMail]).toBe("sent");

    // Running the job again sends nothing.
    await retryPendingNotifications(later);
    expect(mailer.delivered).toHaveLength(2);
  });

  it("warns VBM when the garage confirmation could not be sent", async () => {
    const { deps, mailer } = makeDeps();
    mailer.failWith = (email) =>
      email.to.includes("atelier@garage-dupont.be") ? new MailerError("mailbox down", true) : undefined;
    const result = await createBooking(bookingInput(), deps);
    if (!result.ok) throw new Error("booking failed");
    await completeNotifications(result.booking, result.garageEmail, deps);
    const vbm = mailer.delivered.find((e) => e.to.includes("planning@vbm.test"))!;
    expect(vbm.html).toContain("pas encore pu être envoyé au garage");
    expect(vbm.text).toContain("ATTENTION");
  });

  it("leaves fresh bookings to their own request", async () => {
    const { deps, mailer } = makeDeps();
    mailer.failWith = () => new MailerError("down", true);
    await createBooking(bookingInput(), deps);
    mailer.failWith = null;
    const summary = await retryPendingNotifications({ ...deps, now: () => new Date() });
    expect(summary.sent).toBe(0);
  });

  it("gives up after the maximum number of background attempts", async () => {
    const { deps, calendar, mailer } = makeDeps();
    mailer.failWith = () => new MailerError("invalid", false);
    const result = await createBooking(bookingInput(), deps);
    if (!result.ok) throw new Error("booking failed");
    const later = { ...deps, now: () => new Date(Date.now() + 10 * 60_000) };
    for (let i = 0; i < MAX_BACKGROUND_ATTEMPTS; i++) await retryPendingNotifications(later);
    const [event] = await activeIn(calendar);
    expect(event.privateProps[PROP.garageMail]).toBe("failed");
  });

  it("re-renders exactly the same confirmation from the agenda (needed for idempotent retries)", async () => {
    const { deps, calendar, mailer } = makeDeps();
    const result = await createBooking(bookingInput({ comment: "Ligne 1\nLigne 2" }), deps);
    if (!result.ok) throw new Error("booking failed");
    const [event] = await activeIn(calendar);
    const rebuilt = fromCalendarEvent(event)!;
    expect({ ...rebuilt, htmlLink: undefined }).toEqual({ ...result.booking, htmlLink: undefined });
    expect(mailer.delivered).toHaveLength(1);
  });
});

describe("demo agenda", () => {
  it("keeps deleted ids reserved, like Google", async () => {
    const { calendar } = makeDeps();
    const input = { id: "rcabc123", summary: "x", description: "", location: "", start: NOW, end: NOW, privateProps: {} };
    expect((await calendar.insertEvent(input)).created).toBe(true);
    await calendar.deleteEvent("rcabc123");
    expect((await calendar.insertEvent(input)).created).toBe(false);
  });
});

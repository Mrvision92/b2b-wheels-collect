import { evaluateSlot, isActiveEvent, isBlockingEvent, overlaps } from "../availability";
import type { CalendarEvent } from "../calendar/types";
import { CalendarUnavailableError } from "../calendar/types";
import { eventIdFor, referenceFor, requestFingerprint } from "../ids";
import type { SlotLock } from "../lock";
import { log } from "../log";
import { findSlot, isWithinBookingWindow, type ScheduledSlot } from "../schedule";
import type { BookingInput } from "../validation";
import { sendGarageConfirmation, type NotificationDeps } from "./notifications";
import { fromCalendarEvent, PROP, toCalendarEvent, type BookingRecord } from "./record";

export type BookingDeps = NotificationDeps & {
  lock: SlotLock;
  secret: string;
  /** Re-reads the slot after inserting and withdraws if capacity was exceeded. */
  verifyAfterInsert?: boolean;
};

export type BookingSuccess = {
  ok: true;
  booking: BookingRecord;
  /** True when this request (or the same booking) had already been recorded. */
  alreadyExisted: boolean;
  garageEmail: "sent" | "pending";
};

export type BookingFailure = {
  ok: false;
  code: "slot_unavailable" | "slot_closed" | "request_closed";
  message: string;
};

export type BookingResult = BookingSuccess | BookingFailure;

const SLOT_TAKEN: BookingFailure = {
  ok: false,
  code: "slot_unavailable",
  message: "Ce créneau vient d'être complété. Choisissez un autre créneau : vos informations sont conservées.",
};

const SLOT_CLOSED: BookingFailure = {
  ok: false,
  code: "slot_closed",
  message: "Ce créneau n'est plus réservable (date passée, délai trop court ou jour fermé). Choisissez une autre date.",
};

function sameAddress(a: string, b: string) {
  const norm = (s: string) => s.toLowerCase().replace(/[^a-z0-9]/g, "");
  return norm(a) === norm(b);
}

/**
 * Among the events in the slot, keep the earliest ones up to capacity
 * (agenda creation time, then id). Used after inserting to detect a race
 * that the lock could not prevent.
 */
export function holdsPlace(ownId: string, events: CalendarEvent[], slot: ScheduledSlot, capacity: number): boolean {
  const occupants = events.filter((e) => isActiveEvent(e) && overlaps(e, slot.start, slot.end));
  if (occupants.some((e) => e.id !== ownId && isBlockingEvent(e))) return false;
  const ranked = [...occupants].sort(
    (a, b) => (a.created?.getTime() ?? 0) - (b.created?.getTime() ?? 0) || a.id.localeCompare(b.id),
  );
  const index = ranked.findIndex((e) => e.id === ownId);
  return index !== -1 && index < capacity;
}

function existingResult(event: CalendarEvent): BookingSuccess | BookingFailure {
  if (event.status === "cancelled") {
    return {
      ok: false,
      code: "request_closed",
      message: "Cette demande n'a pas pu aboutir : le créneau n'est plus disponible. Choisissez un autre créneau.",
    };
  }
  const booking = fromCalendarEvent(event);
  if (!booking) throw new CalendarUnavailableError("Événement existant illisible");
  return {
    ok: true,
    booking,
    alreadyExisted: true,
    garageEmail: event.privateProps[PROP.garageMail] === "sent" ? "sent" : "pending",
  };
}

/**
 * Books a pickup. The confirmation is only returned once the event exists in
 * the agenda; any agenda failure throws CalendarUnavailableError and nothing
 * is confirmed to the garage.
 */
export async function createBooking(input: BookingInput, deps: BookingDeps): Promise<BookingResult> {
  const now = (deps.now ?? (() => new Date()))();
  const { config, calendar } = deps;

  const slot = findSlot(input.date, input.slot, config);
  if (!slot || !isWithinBookingWindow(slot, now, config)) return SLOT_CLOSED;

  const eventId = eventIdFor(input.requestId, deps.secret);
  const fingerprint = requestFingerprint(input.requestId, deps.secret);

  // Same request seen before (double click, retry after a lost connection).
  const previous = await calendar.getEvent(eventId);
  if (previous) {
    if (previous.privateProps[PROP.request] !== fingerprint) throw new CalendarUnavailableError("Conflit d'identifiant");
    return existingResult(previous);
  }

  const booking: BookingRecord = {
    reference: referenceFor(input.requestId, deps.secret),
    eventId,
    garageName: input.garageName,
    contactName: input.contactName,
    phone: input.phone,
    email: input.email,
    street: input.street,
    postalCode: input.postalCode,
    city: input.city,
    rimCount: input.rimCount,
    comment: input.comment,
    date: slot.date,
    startTime: slot.startTime,
    endTime: slot.endTime,
    start: slot.start,
    end: slot.end,
    mode: config.mode,
  };

  const outcome = await deps.lock.withLock(`day:${slot.date}`, async (): Promise<BookingResult> => {
    const events = await calendar.listEvents({ timeMin: slot.start, timeMax: slot.end });

    // Same garage already booked on this slot (e.g. page reloaded after a
    // lost confirmation): show that booking instead of creating a second one.
    const duplicate = events.find(
      (e) =>
        isActiveEvent(e) &&
        e.privateProps[PROP.marker] === "1" &&
        e.privateProps[PROP.email]?.toLowerCase() === input.email.toLowerCase() &&
        sameAddress(e.privateProps[PROP.street] ?? "", input.street),
    );
    if (duplicate) return existingResult(duplicate);

    if (!evaluateSlot(slot, events, config.capacityPerSlot).available) return SLOT_TAKEN;

    const inserted = await calendar.insertEvent(toCalendarEvent(booking, fingerprint));
    if (!inserted.created) {
      const existing = await calendar.getEvent(eventId);
      if (existing && existing.privateProps[PROP.request] === fingerprint) return existingResult(existing);
      throw new CalendarUnavailableError("Insertion refusée par l'agenda");
    }
    booking.htmlLink = inserted.event.htmlLink;

    if (deps.verifyAfterInsert !== false) {
      try {
        const after = await calendar.listEvents({ timeMin: slot.start, timeMax: slot.end });
        const view = after.some((e) => e.id === eventId) ? after : [...after, inserted.event];
        if (!holdsPlace(eventId, view, slot, config.capacityPerSlot)) {
          await calendar.deleteEvent(eventId);
          log.warn("booking.withdrawn_after_race", { reference: booking.reference });
          return SLOT_TAKEN;
        }
      } catch (error) {
        // The event exists; only the extra check failed. Keep the booking.
        log.warn("booking.verification_skipped", { reference: booking.reference, error: (error as Error).message });
      }
    }
    return { ok: true, booking, alreadyExisted: false, garageEmail: "pending" };
  });

  if (!outcome.ok || outcome.alreadyExisted) return outcome;

  log.info("booking.created", { reference: booking.reference, date: booking.date, slot: input.slot });
  let garageEmail: "sent" | "pending" = "pending";
  try {
    garageEmail = await sendGarageConfirmation(booking, deps);
  } catch (error) {
    log.error("email.unexpected_error", { reference: booking.reference, error: (error as Error).message });
  }
  return { ...outcome, garageEmail };
}

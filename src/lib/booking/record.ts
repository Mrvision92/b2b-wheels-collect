import type { BookingConfig } from "@/config/booking";
import type { CalendarEvent, NewCalendarEvent } from "../calendar/types";
import { formatPhone } from "../phone";
import {
  capitalize,
  formatDateLong,
  formatSlotLabel,
  formatSlotPhrase,
  zonedDateString,
  zonedTimeString,
} from "../time";

/**
 * A booking as it lives in Google Agenda. The agenda is the record: every
 * field needed to rebuild the confirmation email is stored in the event's
 * private extended properties (invisible to anyone the agenda is shared with
 * read-only, and never sent to the browser except to the garage who booked).
 */
export type BookingRecord = {
  reference: string;
  eventId: string;
  garageName: string;
  contactName: string;
  phone: string;
  email: string;
  street: string;
  postalCode: string;
  city: string;
  rimCount: number;
  comment: string;
  date: string;
  startTime: string;
  endTime: string;
  start: Date;
  end: Date;
  mode: BookingConfig["mode"];
  htmlLink?: string;
};

export type NotificationStatus = "pending" | "sent" | "failed";

export const PROP = {
  marker: "rc",
  reference: "rcRef",
  request: "rcReq",
  garage: "rcGarage",
  contact: "rcName",
  phone: "rcPhone",
  email: "rcEmail",
  street: "rcStreet",
  postalCode: "rcZip",
  city: "rcCity",
  rims: "rcRims",
  comment: "rcNote",
  mode: "rcMode",
  garageMail: "rcMailG",
  vbmMail: "rcMailV",
  garageMailTries: "rcTryG",
  vbmMailTries: "rcTryV",
} as const;

export function rimsLabel(count: number): string {
  return `${count} jante${count > 1 ? "s" : ""}`;
}

export function fullAddress(b: Pick<BookingRecord, "street" | "postalCode" | "city">): string {
  return `${b.street}, ${b.postalCode} ${b.city}`;
}

export function mapsUrl(b: Pick<BookingRecord, "street" | "postalCode" | "city">): string {
  return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(`${fullAddress(b)}, Belgique`)}`;
}

/** Human labels shared by the confirmation screen, emails and the agenda. */
export function describeBooking(b: BookingRecord) {
  return {
    dateLong: capitalize(formatDateLong(b.date)),
    slotLabel: formatSlotLabel(b.startTime, b.endTime, b.mode),
    slotPhrase: formatSlotPhrase(b.startTime, b.endTime, b.mode),
    address: fullAddress(b),
    phone: formatPhone(b.phone),
    rims: rimsLabel(b.rimCount),
    mapsUrl: mapsUrl(b),
  };
}

export function eventSummary(b: BookingRecord): string {
  return `ENLÈVEMENT — ${b.garageName} — ${rimsLabel(b.rimCount)}`;
}

export function eventDescription(b: BookingRecord): string {
  const d = describeBooking(b);
  const lines = [
    `Référence : ${b.reference}`,
    `Créneau : ${d.dateLong}, ${d.slotLabel}`,
    "",
    `Garage : ${b.garageName}`,
    `Contact : ${b.contactName}`,
    `Téléphone : ${d.phone}`,
    `Email : ${b.email}`,
    `Adresse : ${d.address}`,
    `Nombre de jantes : ${b.rimCount}`,
  ];
  if (b.comment) lines.push(`Commentaire : ${b.comment}`);
  lines.push("", `Itinéraire : ${d.mapsUrl}`, "", "Réservé en ligne sur RimCollect.");
  return lines.join("\n");
}

export function toCalendarEvent(b: BookingRecord, requestFingerprint: string): NewCalendarEvent {
  return {
    id: b.eventId,
    summary: eventSummary(b),
    description: eventDescription(b),
    location: `${fullAddress(b)}, Belgique`,
    start: b.start,
    end: b.end,
    privateProps: {
      [PROP.marker]: "1",
      [PROP.reference]: b.reference,
      [PROP.request]: requestFingerprint,
      [PROP.garage]: b.garageName,
      [PROP.contact]: b.contactName,
      [PROP.phone]: b.phone,
      [PROP.email]: b.email,
      [PROP.street]: b.street,
      [PROP.postalCode]: b.postalCode,
      [PROP.city]: b.city,
      [PROP.rims]: String(b.rimCount),
      [PROP.comment]: b.comment,
      [PROP.mode]: b.mode,
      [PROP.garageMail]: "pending",
      [PROP.vbmMail]: "pending",
      [PROP.garageMailTries]: "0",
      [PROP.vbmMailTries]: "0",
    },
  };
}

export function isRimCollectEvent(event: CalendarEvent): boolean {
  return event.privateProps[PROP.marker] === "1" && !!event.privateProps[PROP.reference];
}

/** Rebuilds a booking from its agenda event (date and time follow the event if VBM moved it). */
export function fromCalendarEvent(event: CalendarEvent): BookingRecord | null {
  if (!isRimCollectEvent(event)) return null;
  const p = event.privateProps;
  return {
    reference: p[PROP.reference],
    eventId: event.id,
    garageName: p[PROP.garage] ?? "",
    contactName: p[PROP.contact] ?? "",
    phone: p[PROP.phone] ?? "",
    email: p[PROP.email] ?? "",
    street: p[PROP.street] ?? "",
    postalCode: p[PROP.postalCode] ?? "",
    city: p[PROP.city] ?? "",
    rimCount: Number(p[PROP.rims] ?? "0"),
    comment: p[PROP.comment] ?? "",
    date: zonedDateString(event.start),
    startTime: zonedTimeString(event.start),
    endTime: zonedTimeString(event.end),
    start: event.start,
    end: event.end,
    mode: p[PROP.mode] === "times" ? "times" : "ranges",
    htmlLink: event.htmlLink,
  };
}

/** What the browser receives after booking: the garage's own data only. */
export function publicSummary(b: BookingRecord) {
  const d = describeBooking(b);
  return {
    reference: b.reference,
    garageName: b.garageName,
    contactName: b.contactName,
    email: b.email,
    address: d.address,
    date: b.date,
    dateLabel: d.dateLong,
    slotLabel: d.slotLabel,
    rimCount: b.rimCount,
    rimsLabel: d.rims,
  };
}

export type PublicBookingSummary = ReturnType<typeof publicSummary>;

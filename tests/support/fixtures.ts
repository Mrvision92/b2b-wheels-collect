import type { BookingConfig } from "@/config/booking";
import type { BookingDeps } from "@/lib/booking/create-booking";
import { DemoCalendarClient, type DemoCalendarOptions } from "@/lib/calendar/demo";
import { MailerError, type Mailer, type OutgoingEmail } from "@/lib/email/mailer";
import { MemoryLock, type SlotLock } from "@/lib/lock";
import type { BookingInput } from "@/lib/validation";

/** Monday 2 November 2026, 08:00 in Brussels (winter time, UTC+1). */
export const NOW = new Date("2026-11-02T07:00:00Z");

export const testConfig: BookingConfig = {
  timeZone: "Europe/Brussels",
  mode: "ranges",
  slotMinutes: 120,
  capacityPerSlot: 2,
  minNoticeHours: 12,
  maxAdvanceDays: 60,
  closeOnPublicHolidays: true,
  closedDates: [],
  weeklySchedule: {
    1: [{ from: "09:00", to: "17:00" }],
    2: [{ from: "09:00", to: "17:00" }],
    3: [{ from: "09:00", to: "17:00" }],
    4: [{ from: "09:00", to: "17:00" }],
    5: [{ from: "09:00", to: "17:00" }],
  },
  maxRimsPerBooking: 40,
};

export function bookingInput(overrides: Partial<BookingInput> = {}): BookingInput {
  return {
    requestId: crypto.randomUUID(),
    garageName: "Garage Dupont",
    contactName: "Marie Dupont",
    phone: "+32470123456",
    email: "atelier@garage-dupont.be",
    street: "Rue de la Station 12",
    postalCode: "1000",
    city: "Bruxelles",
    rimCount: 4,
    comment: "",
    date: "2026-11-03",
    slot: "0900",
    ...overrides,
  };
}

/** Behaves like Resend: an idempotency key is only ever delivered once. */
export class FakeMailer implements Mailer {
  readonly kind = "demo" as const;
  delivered: OutgoingEmail[] = [];
  attempts = 0;
  failWith: ((email: OutgoingEmail) => MailerError | undefined) | null = null;
  private seen = new Map<string, string>();

  async send(email: OutgoingEmail) {
    this.attempts++;
    const failure = this.failWith?.(email);
    if (failure) throw failure;
    const existing = this.seen.get(email.idempotencyKey);
    if (existing) return { id: existing };
    const id = `email-${this.seen.size + 1}`;
    this.seen.set(email.idempotencyKey, id);
    this.delivered.push(email);
    return { id };
  }
}

export function makeDeps(options: {
  calendar?: Partial<DemoCalendarOptions>;
  lock?: SlotLock;
  config?: Partial<BookingConfig>;
  now?: Date;
  verifyAfterInsert?: boolean;
} = {}) {
  const calendar = new DemoCalendarClient({ file: null, ...options.calendar });
  const mailer = new FakeMailer();
  const deps: BookingDeps = {
    calendar,
    mailer,
    lock: options.lock ?? new MemoryLock(),
    config: { ...testConfig, ...options.config },
    secret: "test-secret-0123456789abcdefghijklmnop",
    emailContext: { appUrl: "https://rimcollect.test", contactPhone: "02 123 45 67" },
    vbmRecipients: ["planning@vbm.test"],
    replyTo: "planning@vbm.test",
    now: () => options.now ?? NOW,
    retryDelaysMs: [0, 0, 0],
    verifyAfterInsert: options.verifyAfterInsert,
  };
  return { deps, calendar, mailer };
}

/** A lock that does nothing: simulates several serverless instances. */
export const noLock: SlotLock = { kind: "memory", withLock: (_key, fn) => fn() };

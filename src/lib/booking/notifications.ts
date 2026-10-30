import type { BookingConfig } from "@/config/booking";
import type { CalendarClient, CalendarEvent } from "../calendar/types";
import { MailerError, type EmailAttachment, type Mailer } from "../email/mailer";
import { renderGarageConfirmation, renderVbmNotification, type EmailContext } from "../email/templates";
import { log } from "../log";
import { fromCalendarEvent, PROP, type BookingRecord } from "./record";

export type NotificationDeps = {
  calendar: CalendarClient;
  mailer: Mailer;
  config: BookingConfig;
  emailContext: EmailContext;
  vbmRecipients: string[];
  replyTo?: string;
  now?: () => Date;
  /** Delays between in-request attempts. */
  retryDelaysMs?: number[];
};

export type DeliveryStatus = "sent" | "pending" | "failed" | "skipped";

/** After this many background attempts an email is marked "failed" and left alone. */
export const MAX_BACKGROUND_ATTEMPTS = 12;

const sleep = (ms: number) => new Promise((r) => setTimeout(r, ms));

async function deliver(
  deps: NotificationDeps,
  build: () => { to: string[]; subject: string; html: string; text: string; replyTo?: string; idempotencyKey: string },
  attempts: number[],
  logContext: Record<string, string>,
): Promise<"sent" | "pending"> {
  const email = build();
  for (const [index, delay] of attempts.entries()) {
    if (delay) await sleep(delay);
    try {
      await deps.mailer.send(email);
      return "sent";
    } catch (error) {
      const retryable = error instanceof MailerError ? error.retryable : true;
      log.warn("email.send_failed", { ...logContext, attempt: index + 1, retryable, error: (error as Error).message });
      if (!retryable) break;
    }
  }
  return "pending";
}

export const garageIdempotencyKey = (eventId: string) => `rimcollect/garage-confirmation/${eventId}`;
export const vbmIdempotencyKey = (eventId: string) => `rimcollect/vbm-notification/${eventId}`;

export function sendGarageConfirmation(
  booking: BookingRecord,
  deps: NotificationDeps,
  attempts = deps.retryDelaysMs ?? [0, 800, 2500],
) {
  return deliver(
    deps,
    () => ({
      ...renderGarageConfirmation(booking, deps.emailContext),
      to: [booking.email],
      replyTo: deps.replyTo,
      idempotencyKey: garageIdempotencyKey(booking.eventId),
    }),
    attempts,
    { kind: "garage", reference: booking.reference },
  );
}

export async function sendVbmNotification(
  booking: BookingRecord,
  garageStatus: DeliveryStatus,
  deps: NotificationDeps,
  attempts = deps.retryDelaysMs ?? [0, 800, 2500],
  photos: EmailAttachment[] = [],
): Promise<DeliveryStatus> {
  if (deps.vbmRecipients.length === 0) {
    log.warn("email.vbm_recipient_missing", { reference: booking.reference });
    return "skipped";
  }
  return deliver(
    deps,
    () => ({
      ...renderVbmNotification(
        booking,
        deps.emailContext,
        garageStatus === "sent" ? "sent" : "pending",
        photos.length > 0,
      ),
      to: deps.vbmRecipients,
      replyTo: booking.email,
      attachments: photos,
      idempotencyKey: vbmIdempotencyKey(booking.eventId),
    }),
    attempts,
    { kind: "vbm", reference: booking.reference },
  );
}

/**
 * Runs after the response has been sent: notifies VBM and records both
 * delivery states on the agenda event, so the retry job knows what is left.
 */
export async function completeNotifications(
  booking: BookingRecord,
  garageStatus: "sent" | "pending",
  deps: NotificationDeps,
  photos: EmailAttachment[] = [],
) {
  const vbmStatus = await sendVbmNotification(booking, garageStatus, deps, undefined, photos);
  const props = { [PROP.garageMail]: garageStatus, [PROP.vbmMail]: vbmStatus };
  for (const delay of [0, 1000, 3000]) {
    if (delay) await sleep(delay);
    try {
      await deps.calendar.patchPrivateProps(booking.eventId, props);
      return { garage: garageStatus, vbm: vbmStatus };
    } catch (error) {
      log.warn("calendar.status_patch_failed", { reference: booking.reference, error: (error as Error).message });
    }
  }
  return { garage: garageStatus, vbm: vbmStatus };
}

/**
 * Background retry of emails still marked "pending" on agenda events.
 * Triggered by the cron route and opportunistically after each booking.
 */
export async function retryPendingNotifications(deps: NotificationDeps, options: { limit?: number } = {}) {
  const now = (deps.now ?? (() => new Date()))();
  const limit = options.limit ?? 20;
  const timeMin = new Date(now.getTime() - 14 * 86_400_000);
  const timeMax = new Date(now.getTime() + (deps.config.maxAdvanceDays + 2) * 86_400_000);
  const summary = { sent: 0, stillPending: 0, failed: 0 };

  const kinds = [
    { status: PROP.garageMail, tries: PROP.garageMailTries },
    { status: PROP.vbmMail, tries: PROP.vbmMailTries },
  ] as const;

  let processed = 0;
  for (const kind of kinds) {
    const events = await deps.calendar.listEvents({
      timeMin,
      timeMax,
      privateProps: { [PROP.marker]: "1", [kind.status]: "pending" },
    });
    const due = events
      // Leave fresh bookings to the request that created them.
      .filter((e: CalendarEvent) => !e.created || now.getTime() - e.created.getTime() > 120_000)
      .sort((a, b) => (a.created?.getTime() ?? 0) - (b.created?.getTime() ?? 0));

    for (const event of due) {
      if (processed >= limit) return summary;
      processed++;
      const booking = fromCalendarEvent(event);
      if (!booking) continue;
      const status =
        kind.status === PROP.garageMail
          ? await sendGarageConfirmation(booking, deps, [0])
          : await sendVbmNotification(booking, (event.privateProps[PROP.garageMail] as DeliveryStatus) ?? "pending", deps, [0]);
      const tries = Number(event.privateProps[kind.tries] ?? "0") + 1;
      const next: DeliveryStatus = status === "pending" && tries >= MAX_BACKGROUND_ATTEMPTS ? "failed" : status;
      if (next === "sent" || next === "skipped") summary.sent++;
      else if (next === "failed") summary.failed++;
      else summary.stillPending++;
      if (next === "failed") log.error("email.gave_up", { reference: booking.reference, kind: kind.status });
      try {
        await deps.calendar.patchPrivateProps(event.id, { [kind.status]: next, [kind.tries]: String(tries) });
      } catch (error) {
        log.warn("calendar.status_patch_failed", { reference: booking.reference, error: (error as Error).message });
      }
    }
  }
  return summary;
}

import { after } from "next/server";
import { invalidateAvailability } from "@/lib/availability-cache";
import { createBooking } from "@/lib/booking/create-booking";
import {
  completeNotifications,
  garageIdempotencyKey,
  retryPendingNotifications,
  vbmIdempotencyKey,
} from "@/lib/booking/notifications";
import { publicSummary } from "@/lib/booking/record";
import { CalendarUnavailableError } from "@/lib/calendar/types";
import { DemoMailer } from "@/lib/email/mailer";
import { clientIp, isSameOrigin, json, rateLimit, verifyTurnstile } from "@/lib/http";
import { LockTimeoutError } from "@/lib/lock";
import { log } from "@/lib/log";
import { getServices } from "@/lib/services";
import { bookingSchema, fieldErrorsFrom } from "@/lib/validation";

const MAX_BODY_BYTES = 16_000;

export async function POST(request: Request) {
  if (!isSameOrigin(request)) {
    return json({ error: "forbidden", message: "Requête refusée." }, { status: 403 });
  }

  const ip = clientIp(request);
  const limit = rateLimit(`booking:${ip}`, 12, 10 * 60_000);
  if (!limit.ok) {
    return json(
      { error: "rate_limited", message: "Trop de tentatives depuis cette connexion. Réessayez dans quelques minutes." },
      { status: 429, headers: { "retry-after": String(limit.retryAfter) } },
    );
  }

  const raw = await request.text();
  if (raw.length > MAX_BODY_BYTES) {
    return json({ error: "payload_too_large", message: "Données trop volumineuses." }, { status: 413 });
  }
  let body: unknown;
  try {
    body = JSON.parse(raw);
  } catch {
    return json({ error: "invalid_json", message: "Données illisibles. Rechargez la page." }, { status: 400 });
  }
  if (typeof body !== "object" || body === null || Array.isArray(body)) {
    return json({ error: "invalid_json", message: "Données illisibles. Rechargez la page." }, { status: 400 });
  }
  // Honeypot field, invisible to people: only bots fill it.
  if ((body as Record<string, unknown>).website) {
    return json({ error: "rejected", message: "Requête refusée." }, { status: 400 });
  }

  const parsed = bookingSchema.safeParse(body);
  if (!parsed.success) {
    return json(
      { error: "validation", message: "Certains champs sont à corriger.", fields: fieldErrorsFrom(parsed.error) },
      { status: 400 },
    );
  }

  const { env, deps, mailer } = getServices();
  if (env.turnstileSecret && !(await verifyTurnstile(parsed.data.turnstileToken, env.turnstileSecret, ip))) {
    return json(
      { error: "captcha", message: "La vérification anti-spam a échoué. Réessayez." },
      { status: 400 },
    );
  }

  try {
    const result = await createBooking(parsed.data, deps);
    if (!result.ok) {
      invalidateAvailability();
      return json({ error: result.code, message: result.message }, { status: 409 });
    }

    const { booking, alreadyExisted, garageEmail } = result;
    invalidateAvailability();
    if (!alreadyExisted) {
      after(async () => {
        await completeNotifications(booking, garageEmail, deps);
        await retryPendingNotifications(deps, { limit: 5 }).catch((error) =>
          log.warn("email.retry_failed", { error: (error as Error).message }),
        );
      });
    }

    const demoEmails = mailer.kind === "demo";
    return json(
      {
        ok: true,
        alreadyExisted,
        booking: publicSummary(booking),
        emailStatus: garageEmail === "sent" ? (demoEmails ? "simulated" : "sent") : "pending",
        demo: {
          calendar: deps.calendar.kind === "demo",
          email: demoEmails,
          previews: demoEmails
            ? {
                garage: `/demo/emails/${DemoMailer.idFor(garageIdempotencyKey(booking.eventId))}`,
                vbm: `/demo/emails/${DemoMailer.idFor(vbmIdempotencyKey(booking.eventId))}`,
              }
            : undefined,
        },
      },
      { status: alreadyExisted ? 200 : 201 },
    );
  } catch (error) {
    if (error instanceof LockTimeoutError) {
      return json({ error: "busy", message: "Ce créneau est en cours de réservation par un autre garage. Réessayez dans un instant." }, { status: 409 });
    }
    if (error instanceof CalendarUnavailableError) {
      log.error("booking.calendar_unavailable", { error: error.message });
      return json(
        {
          error: "calendar_unavailable",
          message:
            "Votre enlèvement n'a pas pu être confirmé : l'agenda de planification ne répond pas. Réessayez dans un instant, vous ne risquez pas de réserver deux fois.",
        },
        { status: 503 },
      );
    }
    log.error("booking.unexpected_error", { error: (error as Error).message });
    return json(
      {
        error: "server_error",
        message:
          "Votre enlèvement n'a pas pu être confirmé à cause d'une erreur technique. Réessayez dans un instant, vous ne risquez pas de réserver deux fois.",
      },
      { status: 500 },
    );
  }
}

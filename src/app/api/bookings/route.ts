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
import { PHOTO_LIMITS } from "@/lib/photo-limits";
import { readPhotos } from "@/lib/photos";
import { log } from "@/lib/log";
import { getServices } from "@/lib/services";
import { bookingSchema, fieldErrorsFrom } from "@/lib/validation";

const MAX_JSON_BYTES = 16_000;
const MAX_MULTIPART_BYTES = PHOTO_LIMITS.maxTotalBytes + 64_000;

type Received =
  | { ok: true; body: Record<string, unknown>; files: File[] }
  | { ok: false; status: number; error: string; message: string };

const UNREADABLE = { ok: false, status: 400, error: "invalid_json", message: "Données illisibles. Rechargez la page." } as const;
const TOO_LARGE = { ok: false, status: 413, error: "payload_too_large", message: "Envoi trop volumineux : retirez une ou deux photos puis réessayez." } as const;

/**
 * Accepts JSON (no photos) or multipart/form-data with a "data" JSON field
 * and up to five "photos" files.
 */
async function readBody(request: Request): Promise<Received> {
  const type = request.headers.get("content-type") ?? "";
  const declared = Number(request.headers.get("content-length") ?? "0");
  let text: string;
  let files: File[] = [];

  if (type.startsWith("multipart/form-data")) {
    if (declared > MAX_MULTIPART_BYTES) return TOO_LARGE;
    let form: FormData;
    try {
      form = await request.formData();
    } catch {
      return UNREADABLE;
    }
    const data = form.get("data");
    if (typeof data !== "string") return UNREADABLE;
    text = data;
    files = form.getAll("photos").filter((value): value is File => typeof value !== "string");
    if (files.reduce((sum, file) => sum + file.size, 0) > MAX_MULTIPART_BYTES) return TOO_LARGE;
  } else {
    text = await request.text();
  }

  if (text.length > MAX_JSON_BYTES) return { ...TOO_LARGE, message: "Données trop volumineuses." };
  let body: unknown;
  try {
    body = JSON.parse(text);
  } catch {
    return UNREADABLE;
  }
  if (typeof body !== "object" || body === null || Array.isArray(body)) return UNREADABLE;
  return { ok: true, body: body as Record<string, unknown>, files };
}

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

  const received = await readBody(request);
  if (!received.ok) return json({ error: received.error, message: received.message }, { status: received.status });
  const { body, files } = received;

  // Honeypot field, invisible to people: only bots fill it.
  if (body.website) {
    return json({ error: "rejected", message: "Requête refusée." }, { status: 400 });
  }

  const parsed = bookingSchema.safeParse(body);
  const photoCheck = await readPhotos(files);
  if (!parsed.success || !photoCheck.ok) {
    const fields = parsed.success ? {} : fieldErrorsFrom(parsed.error);
    return json(
      {
        error: "validation",
        message: "Certains champs sont à corriger.",
        fields: photoCheck.ok ? fields : { ...fields, photos: photoCheck.message },
      },
      { status: 400 },
    );
  }
  const photos = photoCheck.photos;

  const { env, deps, mailer } = getServices();
  if (env.turnstileSecret && !(await verifyTurnstile(parsed.data.turnstileToken, env.turnstileSecret, ip))) {
    return json(
      { error: "captcha", message: "La vérification anti-spam a échoué. Réessayez." },
      { status: 400 },
    );
  }

  try {
    const result = await createBooking(parsed.data, deps, { photoCount: photos.length });
    if (!result.ok) {
      invalidateAvailability();
      return json({ error: result.code, message: result.message }, { status: 409 });
    }

    const { booking, alreadyExisted, garageEmail } = result;
    invalidateAvailability();
    if (!alreadyExisted) {
      after(async () => {
        await completeNotifications(booking, garageEmail, deps, photos);
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

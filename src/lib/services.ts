import "server-only";
import path from "node:path";
import { bookingConfig } from "@/config/booking";
import type { BookingDeps } from "./booking/create-booking";
import { DemoCalendarClient } from "./calendar/demo";
import { GoogleCalendarClient } from "./calendar/google";
import type { CalendarClient } from "./calendar/types";
import { DemoMailer, ResendMailer, type Mailer } from "./email/mailer";
import { getPublicSettings, getServerEnv, type ServerEnv } from "./env";
import { createLock, type SlotLock } from "./lock";

/**
 * Wires the real or demo implementations from the environment. Instances are
 * kept on globalThis so the in-process lock and demo agenda are shared by
 * every route of the same server process.
 */
type Registry = {
  key: string;
  calendar: CalendarClient;
  mailer: Mailer;
  lock: SlotLock;
};

const globalRegistry = globalThis as typeof globalThis & { __rimcollect?: Registry };

function registryKey(env: ServerEnv) {
  return JSON.stringify([env.calendar.mode, env.email.mode, env.lock.mode, env.demoDataDir]);
}

function build(env: ServerEnv): Registry {
  const calendar: CalendarClient =
    env.calendar.mode === "google"
      ? new GoogleCalendarClient(env.calendar.calendarId, env.calendar.auth)
      : new DemoCalendarClient({ file: path.join(env.demoDataDir, "demo-calendar.json"), latencyMs: 120 });
  const mailer: Mailer =
    env.email.mode === "resend"
      ? new ResendMailer(env.email.apiKey, env.email.from)
      : new DemoMailer(path.join(env.demoDataDir, "demo-emails"));
  return { key: registryKey(env), calendar, mailer, lock: createLock(env.lock) };
}

export function getServices() {
  const env = getServerEnv();
  const key = registryKey(env);
  if (globalRegistry.__rimcollect?.key !== key) globalRegistry.__rimcollect = build(env);
  const registry = globalRegistry.__rimcollect;
  const settings = getPublicSettings();

  const deps: BookingDeps = {
    calendar: registry.calendar,
    mailer: registry.mailer,
    lock: registry.lock,
    config: bookingConfig,
    secret: env.secret,
    emailContext: {
      appUrl: env.appUrl,
      contactPhone: settings.contactPhone,
      contactEmail: settings.contactEmail,
    },
    vbmRecipients: env.email.vbmRecipients.length
      ? env.email.vbmRecipients
      : env.email.mode === "demo"
        ? ["vbm-demo@rimcollect.local"]
        : [],
    replyTo: env.email.replyTo ?? env.email.vbmRecipients[0],
  };
  return { env, deps, mailer: registry.mailer };
}

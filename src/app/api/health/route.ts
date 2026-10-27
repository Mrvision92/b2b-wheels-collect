import { connection } from "next/server";
import { json } from "@/lib/http";
import { getServices } from "@/lib/services";

/** Which integrations are active. Never returns secrets or booking data. */
export async function GET() {
  await connection();
  const { env, deps } = getServices();
  return json({
    ok: true,
    calendar: deps.calendar.kind,
    email: deps.mailer.kind,
    lock: deps.lock.kind,
    vbmRecipientsConfigured: env.email.vbmRecipients.length > 0,
    cronConfigured: !!env.cronSecret,
    turnstile: !!env.turnstileSecret,
  });
}

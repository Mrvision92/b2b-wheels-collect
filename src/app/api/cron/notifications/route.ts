import { timingSafeEqual } from "node:crypto";
import { retryPendingNotifications } from "@/lib/booking/notifications";
import { CalendarUnavailableError } from "@/lib/calendar/types";
import { json } from "@/lib/http";
import { log } from "@/lib/log";
import { getServices } from "@/lib/services";

/**
 * Re-sends confirmation and VBM emails that could not be delivered at
 * booking time. Called by Vercel Cron (see vercel.json) or any scheduler
 * with the header "Authorization: Bearer <CRON_SECRET>".
 */
export async function GET(request: Request) {
  const { env, deps } = getServices();
  if (!env.cronSecret) {
    return json({ error: "not_configured", message: "CRON_SECRET n'est pas configuré." }, { status: 503 });
  }
  const expected = Buffer.from(`Bearer ${env.cronSecret}`);
  const received = Buffer.from(request.headers.get("authorization") ?? "");
  if (expected.length !== received.length || !timingSafeEqual(expected, received)) {
    return json({ error: "unauthorized" }, { status: 401 });
  }

  try {
    const summary = await retryPendingNotifications(deps, { limit: 50 });
    log.info("cron.notifications", summary);
    return json({ ok: true, ...summary });
  } catch (error) {
    if (error instanceof CalendarUnavailableError) {
      return json({ error: "calendar_unavailable", message: error.message }, { status: 503 });
    }
    throw error;
  }
}

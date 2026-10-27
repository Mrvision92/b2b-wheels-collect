import { bookableMonths, getMonthAvailability, isValidMonth } from "@/lib/availability";
import { getCachedMonth, setCachedMonth } from "@/lib/availability-cache";
import { CalendarUnavailableError } from "@/lib/calendar/types";
import { clientIp, json, rateLimit } from "@/lib/http";
import { log } from "@/lib/log";
import { getServices } from "@/lib/services";

export async function GET(request: Request) {
  const limit = rateLimit(`availability:${clientIp(request)}`, 120, 60_000);
  if (!limit.ok) {
    return json(
      { error: "rate_limited", message: "Trop de requêtes. Patientez quelques secondes." },
      { status: 429, headers: { "retry-after": String(limit.retryAfter) } },
    );
  }

  const { deps } = getServices();
  const now = new Date();
  const { firstMonth, lastMonth } = bookableMonths(now, deps.config);
  const requested = new URL(request.url).searchParams.get("month") ?? firstMonth;
  if (!isValidMonth(requested)) {
    return json({ error: "invalid_month", message: "Mois invalide." }, { status: 400 });
  }
  const month = requested < firstMonth ? firstMonth : requested > lastMonth ? lastMonth : requested;

  const cacheKey = `${month}:${deps.calendar.kind}`;
  const cached = getCachedMonth(cacheKey);
  if (cached) return json(cached);

  try {
    const availability = await getMonthAvailability(month, { calendar: deps.calendar, config: deps.config, now });
    setCachedMonth(cacheKey, availability);
    return json(availability);
  } catch (error) {
    if (error instanceof CalendarUnavailableError) {
      log.error("availability.calendar_unavailable", { error: error.message });
      return json(
        {
          error: "calendar_unavailable",
          message: "Les disponibilités ne peuvent pas être chargées pour le moment. Réessayez dans un instant.",
        },
        { status: 503 },
      );
    }
    log.error("availability.unexpected_error", { error: (error as Error).message });
    return json({ error: "server_error", message: "Erreur inattendue. Réessayez dans un instant." }, { status: 500 });
  }
}

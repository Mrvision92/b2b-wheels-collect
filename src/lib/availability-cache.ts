import type { MonthAvailability } from "./availability";

/**
 * Very short in-memory cache for month availability, to avoid querying
 * Google Agenda on every calendar click. Bookings are always re-checked
 * against the agenda itself, so a stale entry can never cause an overbooking.
 *
 * Kept on globalThis: Next.js bundles each route separately, and the booking
 * route must be able to clear the entries filled by the availability route.
 */
const TTL_MS = 15_000;

type Entries = Map<string, { value: MonthAvailability; expiresAt: number }>;
const store = globalThis as typeof globalThis & { __rimcollectAvailability?: Entries };

function entries(): Entries {
  store.__rimcollectAvailability ??= new Map();
  return store.__rimcollectAvailability;
}

export function getCachedMonth(key: string): MonthAvailability | null {
  const entry = entries().get(key);
  if (!entry || entry.expiresAt < Date.now()) return null;
  return entry.value;
}

export function setCachedMonth(key: string, value: MonthAvailability) {
  entries().set(key, { value, expiresAt: Date.now() + TTL_MS });
}

export function invalidateAvailability() {
  entries().clear();
}

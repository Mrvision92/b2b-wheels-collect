import { createHmac } from "node:crypto";

/**
 * Identifiers derived from the browser's request id with a server secret.
 * The same request always maps to the same agenda event, which makes a
 * resubmitted form (double click, lost connection) idempotent, while nobody
 * can forge the id of someone else's event.
 */

function hmac(secret: string, purpose: string, value: string): Buffer {
  return createHmac("sha256", secret).update(`${purpose}:${value}`).digest();
}

/** Google Calendar ids allow lowercase a–v and digits: hex is a valid subset. */
export function eventIdFor(requestId: string, secret: string): string {
  return `rc${hmac(secret, "event-id", requestId).toString("hex").slice(0, 40)}`;
}

/** Stored on the event instead of the raw request id. */
export function requestFingerprint(requestId: string, secret: string): string {
  return hmac(secret, "request", requestId).toString("hex").slice(0, 32);
}

const CROCKFORD = "0123456789ABCDEFGHJKMNPQRSTVWXYZ";

/** Short human-readable reference, e.g. "BWC-7K4M-2QX9". */
export function referenceFor(requestId: string, secret: string): string {
  const bytes = hmac(secret, "reference", requestId);
  let bits = 0;
  let value = 0;
  let out = "";
  for (const byte of bytes) {
    value = (value << 8) | byte;
    bits += 8;
    while (bits >= 5 && out.length < 8) {
      out += CROCKFORD[(value >>> (bits - 5)) & 31];
      bits -= 5;
    }
    if (out.length === 8) break;
  }
  return `BWC-${out.slice(0, 4)}-${out.slice(4)}`;
}

/** Non-reversible tag for logs, so log lines never carry personal data. */
export function logTag(value: string, secret: string): string {
  return hmac(secret, "log", value).toString("hex").slice(0, 10);
}

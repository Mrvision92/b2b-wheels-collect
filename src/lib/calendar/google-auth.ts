import { createSign } from "node:crypto";
import type { GoogleAuth } from "../env";

const TOKEN_URL = "https://oauth2.googleapis.com/token";

/** Least privilege: events only, on calendars explicitly shared with the account. */
export const SERVICE_ACCOUNT_SCOPE = "https://www.googleapis.com/auth/calendar.events";

type CachedToken = { key: string; token: string; expiresAt: number };
let cached: CachedToken | null = null;

function base64url(input: Buffer | string): string {
  return Buffer.from(input).toString("base64url");
}

function serviceAccountAssertion(clientEmail: string, privateKey: string, nowSeconds: number): string {
  const header = base64url(JSON.stringify({ alg: "RS256", typ: "JWT" }));
  const claims = base64url(
    JSON.stringify({
      iss: clientEmail,
      scope: SERVICE_ACCOUNT_SCOPE,
      aud: TOKEN_URL,
      iat: nowSeconds,
      exp: nowSeconds + 3600,
    }),
  );
  const signer = createSign("RSA-SHA256");
  signer.update(`${header}.${claims}`);
  return `${header}.${claims}.${base64url(signer.sign(privateKey))}`;
}

export class GoogleAuthError extends Error {
  constructor(
    message: string,
    readonly status?: number,
  ) {
    super(message);
    this.name = "GoogleAuthError";
  }
}

/** Returns a short-lived access token, cached in memory until shortly before expiry. */
export async function getAccessToken(auth: GoogleAuth, fetchImpl: typeof fetch = fetch): Promise<string> {
  const key = auth.type === "service_account" ? auth.clientEmail : auth.clientId;
  if (cached && cached.key === key && cached.expiresAt > Date.now() + 60_000) return cached.token;

  const body = new URLSearchParams();
  if (auth.type === "service_account") {
    body.set("grant_type", "urn:ietf:params:oauth:grant-type:jwt-bearer");
    body.set("assertion", serviceAccountAssertion(auth.clientEmail, auth.privateKey, Math.floor(Date.now() / 1000)));
  } else {
    body.set("grant_type", "refresh_token");
    body.set("client_id", auth.clientId);
    body.set("client_secret", auth.clientSecret);
    body.set("refresh_token", auth.refreshToken);
  }

  let response: Response;
  try {
    response = await fetchImpl(TOKEN_URL, {
      method: "POST",
      headers: { "content-type": "application/x-www-form-urlencoded" },
      body,
      signal: AbortSignal.timeout(10_000),
    });
  } catch (error) {
    throw new GoogleAuthError(`Google OAuth injoignable : ${(error as Error).message}`);
  }
  const json = (await response.json().catch(() => ({}))) as {
    access_token?: string;
    expires_in?: number;
    error?: string;
    error_description?: string;
  };
  if (!response.ok || !json.access_token) {
    // Never log the credentials themselves, only Google's error code.
    throw new GoogleAuthError(
      `Authentification Google refusée (${json.error ?? response.status}${json.error_description ? ` : ${json.error_description}` : ""})`,
      response.status,
    );
  }
  cached = { key, token: json.access_token, expiresAt: Date.now() + (json.expires_in ?? 3600) * 1000 };
  return json.access_token;
}

export function clearAccessTokenCache() {
  cached = null;
}

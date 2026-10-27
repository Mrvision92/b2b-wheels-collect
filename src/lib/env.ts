import path from "node:path";

/**
 * Server configuration, read from environment variables (see .env.example).
 *
 * Each external service is either "live" (credentials present) or "demo"
 * (simulated locally, clearly labelled on the site). DEMO_MODE=true forces
 * every service into demo mode.
 */

export type GoogleAuth =
  | { type: "service_account"; clientEmail: string; privateKey: string }
  | { type: "oauth"; clientId: string; clientSecret: string; refreshToken: string };

export type CalendarSettings = { mode: "google"; calendarId: string; auth: GoogleAuth } | { mode: "demo" };

export type EmailSettings = {
  mode: "resend" | "demo";
  apiKey: string;
  from: string;
  replyTo?: string;
  vbmRecipients: string[];
};

export type LockSettings = { mode: "upstash"; url: string; token: string } | { mode: "memory" };

export type ServerEnv = {
  appUrl: string;
  secret: string;
  calendar: CalendarSettings;
  email: EmailSettings;
  lock: LockSettings;
  turnstileSecret?: string;
  cronSecret?: string;
  demoDataDir: string;
};

const DEV_SECRET = "rimcollect-demo-secret-not-for-production";

function read(name: string): string | undefined {
  const value = process.env[name]?.trim();
  return value ? value : undefined;
}

function list(name: string): string[] {
  return (read(name) ?? "")
    .split(/[,;]/)
    .map((s) => s.trim())
    .filter(Boolean);
}

function googleAuth(): GoogleAuth | null {
  const saEmail = read("GOOGLE_SERVICE_ACCOUNT_EMAIL");
  const saKey = read("GOOGLE_SERVICE_ACCOUNT_PRIVATE_KEY");
  if (saEmail && saKey) {
    return { type: "service_account", clientEmail: saEmail, privateKey: saKey.replace(/\\n/g, "\n") };
  }
  const clientId = read("GOOGLE_OAUTH_CLIENT_ID");
  const clientSecret = read("GOOGLE_OAUTH_CLIENT_SECRET");
  const refreshToken = read("GOOGLE_OAUTH_REFRESH_TOKEN");
  if (clientId && clientSecret && refreshToken) {
    return { type: "oauth", clientId, clientSecret, refreshToken };
  }
  return null;
}

export function getServerEnv(): ServerEnv {
  const forceDemo = read("DEMO_MODE") === "true";

  const calendarId = read("GOOGLE_CALENDAR_ID");
  const auth = googleAuth();
  const calendar: CalendarSettings =
    !forceDemo && calendarId && auth ? { mode: "google", calendarId, auth } : { mode: "demo" };

  const resendKey = read("RESEND_API_KEY");
  const from = read("EMAIL_FROM");
  const email: EmailSettings = {
    mode: !forceDemo && resendKey && from ? "resend" : "demo",
    apiKey: resendKey ?? "",
    from: from ?? "RimCollect <demo@rimcollect.local>",
    replyTo: read("EMAIL_REPLY_TO"),
    vbmRecipients: list("VBM_NOTIFICATION_EMAIL"),
  };

  const upstashUrl = read("UPSTASH_REDIS_REST_URL");
  const upstashToken = read("UPSTASH_REDIS_REST_TOKEN");
  const lock: LockSettings =
    upstashUrl && upstashToken ? { mode: "upstash", url: upstashUrl, token: upstashToken } : { mode: "memory" };

  const anyLive = calendar.mode === "google" || email.mode === "resend";
  const secret = read("APP_SECRET");
  if (anyLive && (!secret || secret.length < 32)) {
    throw new Error("APP_SECRET doit contenir au moins 32 caractères dès qu'un service réel est configuré.");
  }

  return {
    appUrl: (read("APP_URL") ?? "http://localhost:3000").replace(/\/+$/, ""),
    secret: secret ?? DEV_SECRET,
    calendar,
    email,
    lock,
    turnstileSecret: read("TURNSTILE_SECRET_KEY"),
    cronSecret: read("CRON_SECRET"),
    demoDataDir: read("DEMO_DATA_DIR") ?? path.join(process.cwd(), ".data"),
  };
}

/** Non-secret information shown on the public page. */
export type PublicSettings = {
  demo: { calendar: boolean; email: boolean };
  contactPhone?: string;
  contactEmail?: string;
  turnstileSiteKey?: string;
  legal: {
    entity?: string;
    address?: string;
    vat?: string;
    privacyEmail?: string;
    retention?: string;
  };
};

export function getPublicSettings(): PublicSettings {
  const forceDemo = read("DEMO_MODE") === "true";
  const calendarLive = !forceDemo && !!read("GOOGLE_CALENDAR_ID") && googleAuth() !== null;
  const emailLive = !forceDemo && !!read("RESEND_API_KEY") && !!read("EMAIL_FROM");
  return {
    demo: { calendar: !calendarLive, email: !emailLive },
    contactPhone: read("NEXT_PUBLIC_CONTACT_PHONE"),
    contactEmail: read("NEXT_PUBLIC_CONTACT_EMAIL"),
    turnstileSiteKey: read("NEXT_PUBLIC_TURNSTILE_SITE_KEY"),
    legal: {
      entity: read("LEGAL_ENTITY_NAME"),
      address: read("LEGAL_ADDRESS"),
      vat: read("LEGAL_VAT_NUMBER"),
      privacyEmail: read("LEGAL_PRIVACY_EMAIL") ?? read("NEXT_PUBLIC_CONTACT_EMAIL"),
      retention: read("LEGAL_RETENTION"),
    },
  };
}

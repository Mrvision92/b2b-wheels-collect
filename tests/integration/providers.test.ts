import { createVerify, generateKeyPairSync } from "node:crypto";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { GoogleCalendarClient } from "@/lib/calendar/google";
import { clearAccessTokenCache, SERVICE_ACCOUNT_SCOPE } from "@/lib/calendar/google-auth";
import { CalendarUnavailableError } from "@/lib/calendar/types";
import { MailerError, ResendMailer } from "@/lib/email/mailer";

const { privateKey, publicKey } = generateKeyPairSync("rsa", { modulusLength: 2048 });
const auth = {
  type: "service_account" as const,
  clientEmail: "rimcollect@project.iam.gserviceaccount.com",
  privateKey: privateKey.export({ type: "pkcs8", format: "pem" }).toString(),
};

type Call = { url: string; init: RequestInit };

function fakeFetch(handler: (url: URL, init: RequestInit) => Response | Promise<Response>) {
  const calls: Call[] = [];
  const fn = vi.fn(async (input: string | URL | Request, init: RequestInit = {}) => {
    const url = new URL(String(input));
    calls.push({ url: url.toString(), init });
    if (url.hostname === "oauth2.googleapis.com") {
      return Response.json({ access_token: "ya29.token", expires_in: 3600 });
    }
    return handler(url, init);
  });
  return { fn: fn as unknown as typeof fetch, calls };
}

const googleEvent = (overrides: Record<string, unknown> = {}) => ({
  id: "rcabc",
  status: "confirmed",
  summary: "ENLÈVEMENT — Garage — 4 jantes",
  start: { dateTime: "2026-11-03T09:00:00+01:00" },
  end: { dateTime: "2026-11-03T11:00:00+01:00" },
  created: "2026-11-02T07:00:00.000Z",
  extendedProperties: { private: { rc: "1" } },
  ...overrides,
});

beforeEach(() => clearAccessTokenCache());

describe("Google Agenda client", () => {
  it("signs a service-account token request limited to agenda events", async () => {
    const { fn, calls } = fakeFetch(() => Response.json({ items: [] }));
    const client = new GoogleCalendarClient("agenda@group.calendar.google.com", auth, fn);
    await client.listEvents({ timeMin: new Date("2026-11-03T00:00:00Z"), timeMax: new Date("2026-11-04T00:00:00Z") });

    const tokenCall = calls[0];
    const params = new URLSearchParams(String(tokenCall.init.body));
    expect(params.get("grant_type")).toBe("urn:ietf:params:oauth:grant-type:jwt-bearer");
    const [header, claims, signature] = params.get("assertion")!.split(".");
    const verifier = createVerify("RSA-SHA256");
    verifier.update(`${header}.${claims}`);
    expect(verifier.verify(publicKey, Buffer.from(signature, "base64url"))).toBe(true);
    const payload = JSON.parse(Buffer.from(claims, "base64url").toString());
    expect(payload.scope).toBe(SERVICE_ACCOUNT_SCOPE);
    expect(payload.iss).toBe(auth.clientEmail);

    const apiCall = calls[1];
    expect(apiCall.url).toContain("/calendars/agenda%40group.calendar.google.com/events");
    expect((apiCall.init.headers as Record<string, string>).authorization).toBe("Bearer ya29.token");
  });

  it("filters on private properties, follows pages and maps all-day events", async () => {
    let page = 0;
    const { fn, calls } = fakeFetch(() =>
      Response.json(
        page++ === 0
          ? { items: [googleEvent()], nextPageToken: "p2" }
          : {
              items: [
                googleEvent({ id: "allday", start: { date: "2026-11-05" }, end: { date: "2026-11-06" }, transparency: "transparent" }),
              ],
            },
      ),
    );
    const client = new GoogleCalendarClient("cal", auth, fn);
    const events = await client.listEvents({
      timeMin: new Date("2026-11-01T00:00:00Z"),
      timeMax: new Date("2026-12-01T00:00:00Z"),
      privateProps: { rc: "1", rcMailG: "pending" },
    });
    const first = new URL(calls[1].url);
    expect(first.searchParams.getAll("privateExtendedProperty")).toEqual(["rc=1", "rcMailG=pending"]);
    expect(first.searchParams.get("singleEvents")).toBe("true");
    expect(new URL(calls[2].url).searchParams.get("pageToken")).toBe("p2");

    expect(events).toHaveLength(2);
    expect(events[0].start.toISOString()).toBe("2026-11-03T08:00:00.000Z");
    expect(events[1]).toMatchObject({ allDay: true, transparent: true });
    expect(events[1].start.toISOString()).toBe("2026-11-04T23:00:00.000Z");
  });

  it("creates events with a fixed id in the Brussels time zone and reports duplicates", async () => {
    let status = 200;
    const { fn, calls } = fakeFetch(() =>
      status === 409 ? new Response("duplicate", { status: 409 }) : Response.json(googleEvent({ htmlLink: "https://calendar.google.com/x" })),
    );
    const client = new GoogleCalendarClient("cal", auth, fn);
    const input = {
      id: "rcabc",
      summary: "ENLÈVEMENT — Garage — 4 jantes",
      description: "…",
      location: "Rue 1, 1000 Bruxelles, Belgique",
      start: new Date("2026-11-03T08:00:00Z"),
      end: new Date("2026-11-03T10:00:00Z"),
      privateProps: { rc: "1" },
    };
    const created = await client.insertEvent(input);
    expect(created.created && created.event.htmlLink).toBe("https://calendar.google.com/x");
    const body = JSON.parse(String(calls[1].init.body));
    expect(body).toMatchObject({
      id: "rcabc",
      start: { dateTime: "2026-11-03T08:00:00.000Z", timeZone: "Europe/Brussels" },
      extendedProperties: { private: { rc: "1" } },
    });
    expect(new URL(calls[1].url).searchParams.get("sendUpdates")).toBe("none");

    status = 409;
    expect(await client.insertEvent(input)).toEqual({ created: false });
  });

  it("returns null for unknown events and accepts deleting a missing event", async () => {
    const { fn } = fakeFetch(() => new Response("gone", { status: 410 }));
    const client = new GoogleCalendarClient("cal", auth, fn);
    expect(await client.getEvent("nope")).toBeNull();
    await expect(client.deleteEvent("nope")).resolves.toBeUndefined();
  });

  it("retries once on server errors, then reports the agenda as unavailable", async () => {
    const { fn, calls } = fakeFetch(() => new Response("backend", { status: 503 }));
    const client = new GoogleCalendarClient("cal", auth, fn);
    await expect(client.getEvent("x")).rejects.toBeInstanceOf(CalendarUnavailableError);
    expect(calls.filter((c) => c.url.includes("/events/")).length).toBe(2);
  });

  it("does not retry permission errors", async () => {
    const { fn, calls } = fakeFetch(() => new Response("forbidden", { status: 403 }));
    const client = new GoogleCalendarClient("cal", auth, fn);
    await expect(client.getEvent("x")).rejects.toThrow("403");
    expect(calls.filter((c) => c.url.includes("/events/")).length).toBe(1);
  });
});

describe("Resend mailer", () => {
  const email = {
    to: ["atelier@garage.be"],
    subject: "Confirmation",
    html: "<p>Bonjour</p>",
    text: "Bonjour",
    replyTo: "planning@vbm.be",
    idempotencyKey: "rimcollect/garage-confirmation/rcabc",
  };

  it("sends with an idempotency key so retries never duplicate the email", async () => {
    const calls: Call[] = [];
    const fn = (async (url: string, init: RequestInit) => {
      calls.push({ url, init });
      return Response.json({ id: "email_123" });
    }) as unknown as typeof fetch;
    const mailer = new ResendMailer("re_key", "RimCollect <enlevements@example.be>", fn);
    expect(await mailer.send(email)).toEqual({ id: "email_123" });
    const headers = calls[0].init.headers as Record<string, string>;
    expect(headers["idempotency-key"]).toBe(email.idempotencyKey);
    expect(headers.authorization).toBe("Bearer re_key");
    expect(JSON.parse(String(calls[0].init.body))).toMatchObject({
      from: "RimCollect <enlevements@example.be>",
      to: ["atelier@garage.be"],
      reply_to: "planning@vbm.be",
    });
  });

  it.each([
    [429, true],
    [500, true],
    [422, false],
    [403, false],
  ])("classifies HTTP %i as retryable=%s", async (status, retryable) => {
    const fn = (async () => Response.json({ name: "error", message: "x" }, { status })) as unknown as typeof fetch;
    const mailer = new ResendMailer("re_key", "from@example.be", fn);
    const error = await mailer.send(email).catch((e) => e);
    expect(error).toBeInstanceOf(MailerError);
    expect(error.retryable).toBe(retryable);
  });

  it("treats network failures as retryable", async () => {
    const fn = (async () => {
      throw new TypeError("fetch failed");
    }) as unknown as typeof fetch;
    const error = await new ResendMailer("k", "f@e.be", fn).send(email).catch((e) => e);
    expect(error).toMatchObject({ retryable: true });
  });
});

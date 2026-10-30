import { generateKeyPairSync } from "node:crypto";
import { existsSync, mkdtempSync, readdirSync, readFileSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import path from "node:path";
import { afterAll, afterEach, beforeEach, describe, expect, it, vi } from "vitest";

/* `after()` only exists inside a Next.js request; collect the callbacks instead. */
const scheduled: (() => Promise<void> | void)[] = [];
vi.mock("next/server", async (importOriginal) => ({
  ...(await importOriginal<typeof import("next/server")>()),
  after: (fn: () => Promise<void> | void) => void scheduled.push(fn),
  connection: async () => undefined,
}));

const { GET: getAvailability } = await import("@/app/api/availability/route");
const { POST: postBooking } = await import("@/app/api/bookings/route");
const { GET: runCron } = await import("@/app/api/cron/notifications/route");
const { GET: getHealth } = await import("@/app/api/health/route");
const { GET: getDemoEmail } = await import("@/app/demo/emails/[id]/route");
const { resetRateLimits } = await import("@/lib/http");
const { invalidateAvailability } = await import("@/lib/availability-cache");

const ENV_KEYS = [
  "DEMO_MODE",
  "DEMO_DATA_DIR",
  "APP_SECRET",
  "APP_URL",
  "CRON_SECRET",
  "RESEND_API_KEY",
  "EMAIL_FROM",
  "VBM_NOTIFICATION_EMAIL",
  "GOOGLE_CALENDAR_ID",
  "GOOGLE_SERVICE_ACCOUNT_EMAIL",
  "GOOGLE_SERVICE_ACCOUNT_PRIVATE_KEY",
];
const savedEnv = Object.fromEntries(ENV_KEYS.map((k) => [k, process.env[k]]));
let dataDir: string;

beforeEach(() => {
  for (const key of ENV_KEYS) delete process.env[key];
  dataDir = mkdtempSync(path.join(tmpdir(), "rimcollect-api-"));
  process.env.DEMO_MODE = "true";
  process.env.DEMO_DATA_DIR = dataDir;
  process.env.APP_URL = "http://localhost:3100";
  resetRateLimits();
  invalidateAvailability();
  scheduled.length = 0;
  vi.useFakeTimers({ toFake: ["Date"], now: new Date("2026-11-02T07:00:00Z") });
});

afterEach(() => {
  vi.useRealTimers();
  vi.unstubAllGlobals();
  rmSync(dataDir, { recursive: true, force: true });
});

afterAll(() => {
  for (const [key, value] of Object.entries(savedEnv)) {
    if (value === undefined) delete process.env[key];
    else process.env[key] = value;
  }
});

async function runScheduled() {
  while (scheduled.length) await scheduled.shift()!();
}

const validBody = () => ({
  requestId: crypto.randomUUID(),
  garageName: "Garage Dupont",
  contactName: "Marie Dupont",
  phone: "0470 12 34 56",
  email: "atelier@garage-dupont.be",
  street: "Rue de la Station 12",
  postalCode: "1000",
  city: "Bruxelles",
  rimCount: 4,
  date: "2026-11-03",
  slot: "0900",
  comment: "",
});

function post(body: unknown, headers: Record<string, string> = {}) {
  return postBooking(
    new Request("http://localhost:3100/api/bookings", {
      method: "POST",
      headers: { "content-type": "application/json", host: "localhost:3100", origin: "http://localhost:3100", ...headers },
      body: typeof body === "string" ? body : JSON.stringify(body),
    }),
  );
}

describe("GET /api/availability", () => {
  it("returns the bookable days of the requested month without caching", async () => {
    const response = await getAvailability(new Request("http://localhost:3100/api/availability?month=2026-11"));
    expect(response.status).toBe(200);
    expect(response.headers.get("cache-control")).toBe("no-store");
    const body = await response.json();
    expect(body.month).toBe("2026-11");
    expect(body.days[0]).toMatchObject({ date: "2026-11-03" });
    expect(body.days[0].slots[0]).toEqual({ key: "0900", label: "9 h – 11 h", available: true });
  });

  it("clamps to the bookable range and rejects malformed months", async () => {
    const past = await (await getAvailability(new Request("http://localhost:3100/api/availability?month=2020-01"))).json();
    expect(past.month).toBe("2026-11");
    const bad = await getAvailability(new Request("http://localhost:3100/api/availability?month=2026-13"));
    expect(bad.status).toBe(400);
  });
});

describe("POST /api/bookings", () => {
  it("confirms a valid booking and returns only the garage's own summary", async () => {
    const response = await post(validBody());
    expect(response.status).toBe(201);
    const body = await response.json();
    expect(body).toMatchObject({
      ok: true,
      alreadyExisted: false,
      emailStatus: "simulated",
      demo: { calendar: true, email: true },
    });
    expect(Object.keys(body.booking).sort()).toEqual(
      [
        "address",
        "contactName",
        "date",
        "dateLabel",
        "email",
        "garageName",
        "garageReference",
        "photoCount",
        "photosLabel",
        "rimCount",
        "rimsLabel",
        "reference",
        "slotLabel",
      ].sort(),
    );
    expect(body.booking.dateLabel).toBe("Mardi 3 novembre 2026");

    await runScheduled();
    const emails = readdirSync(path.join(dataDir, "demo-emails"));
    expect(emails).toHaveLength(2);

    const preview = await getDemoEmail(new Request("http://localhost:3100"), {
      params: Promise.resolve({ id: body.demo.previews.garage.split("/").pop() }),
    });
    expect(preview.status).toBe(200);
    expect(await preview.text()).toContain("aucun envoi réel");
  });

  it("answers a repeated submission with the same booking", async () => {
    const body = validBody();
    const first = await (await post(body)).json();
    const second = await post(body);
    expect(second.status).toBe(200);
    const json = await second.json();
    expect(json.alreadyExisted).toBe(true);
    expect(json.booking.reference).toBe(first.booking.reference);
  });

  it("returns field errors for missing or invalid data", async () => {
    const response = await post({ ...validBody(), email: "nope", phone: "", garageName: "" });
    expect(response.status).toBe(400);
    const body = await response.json();
    expect(body.error).toBe("validation");
    expect(Object.keys(body.fields).sort()).toEqual(["email", "garageName", "phone"]);
  });

  it.each([
    ["malformed JSON", "{not json", 400],
    ["a JSON array", "[]", 400],
    ["an oversized body", JSON.stringify({ ...validBody(), comment: "x".repeat(20_000) }), 413],
  ])("rejects %s", async (_label, raw, status) => {
    expect((await post(raw)).status).toBe(status);
  });

  it("rejects bots filling the hidden field and cross-site requests", async () => {
    expect((await post({ ...validBody(), website: "http://spam" })).status).toBe(400);
    expect((await post(validBody(), { origin: "https://evil.example" })).status).toBe(403);
  });

  it("refuses a past date with a clear message", async () => {
    const response = await post({ ...validBody(), date: "2026-10-30" });
    expect(response.status).toBe(409);
    expect((await response.json()).error).toBe("slot_closed");
  });

  it("refuses a slot that is already full", async () => {
    await post(validBody());
    await post({ ...validBody(), email: "b@garage.be", street: "Rue B 2" });
    const third = await post({ ...validBody(), email: "c@garage.be", street: "Rue C 3" });
    expect(third.status).toBe(409);
    expect((await third.json()).error).toBe("slot_unavailable");
  });

  it("limits the number of attempts per connection", async () => {
    const statuses: number[] = [];
    for (let i = 0; i < 14; i++) statuses.push((await post({ requestId: "x" })).status);
    expect(statuses.slice(0, 12).every((s) => s === 400)).toBe(true);
    expect(statuses.at(-1)).toBe(429);
  });

  it("does not confirm and sends nothing when Google Agenda is unreachable", async () => {
    const { privateKey } = generateKeyPairSync("rsa", { modulusLength: 2048 });
    process.env.DEMO_MODE = "false";
    process.env.APP_SECRET = "s".repeat(40);
    process.env.GOOGLE_CALENDAR_ID = "agenda@group.calendar.google.com";
    process.env.GOOGLE_SERVICE_ACCOUNT_EMAIL = "rimcollect@project.iam.gserviceaccount.com";
    process.env.GOOGLE_SERVICE_ACCOUNT_PRIVATE_KEY = privateKey.export({ type: "pkcs8", format: "pem" }).toString();
    const fetchMock = vi.fn<typeof fetch>(async () => new Response("unavailable", { status: 503 }));
    vi.stubGlobal("fetch", fetchMock);

    const response = await post(validBody());
    expect(response.status).toBe(503);
    const body = await response.json();
    expect(body.error).toBe("calendar_unavailable");
    expect(body.message).toContain("n'a pas pu être confirmé");
    expect(scheduled).toHaveLength(0);
    expect(fetchMock.mock.calls.every(([url]) => !String(url).includes("resend"))).toBe(true);
  });
});

/* Minimal valid image headers: enough for type detection, which is all the server checks. */
const JPEG = Buffer.from([0xff, 0xd8, 0xff, 0xe0, 0x00, 0x10, 0x4a, 0x46, 0x49, 0x46, 0x00, 0x01]);
const PNG = Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a, 0x00, 0x00, 0x00, 0x0d]);

function postMultipart(body: unknown, photos: { name: string; type: string; bytes: Buffer }[]) {
  const form = new FormData();
  form.append("data", JSON.stringify(body));
  for (const photo of photos) form.append("photos", new Blob([new Uint8Array(photo.bytes)], { type: photo.type }), photo.name);
  return postBooking(
    new Request("http://localhost:3100/api/bookings", {
      method: "POST",
      headers: { host: "localhost:3100", origin: "http://localhost:3100" },
      body: form,
    }),
  );
}

describe("photos and garage reference", () => {
  it("attaches the photos to VBM's email and records the reference everywhere", async () => {
    const response = await postMultipart({ ...validBody(), garageReference: "  DOSSIER  2041 " }, [
      { name: "IMG_0001.JPG", type: "image/jpeg", bytes: JPEG },
      { name: "capture.png", type: "image/png", bytes: PNG },
    ]);
    expect(response.status).toBe(201);
    const body = await response.json();
    expect(body.booking).toMatchObject({ garageReference: "DOSSIER 2041", photoCount: 2, photosLabel: "2 photos" });

    await runScheduled();
    const { DemoMailer } = await import("@/lib/email/mailer");
    const mailer = new DemoMailer(path.join(dataDir, "demo-emails"));
    const vbm = await mailer.read(body.demo.previews.vbm.split("/").pop());
    const garage = await mailer.read(body.demo.previews.garage.split("/").pop());
    expect(vbm?.attachments.map((a) => [a.filename, a.contentType])).toEqual([
      ["photo-1.jpg", "image/jpeg"],
      ["photo-2.png", "image/png"],
    ]);
    expect(vbm?.html).toContain("2 photos jointes à cet email");
    expect(vbm?.html).toContain("DOSSIER 2041");
    expect(garage?.attachments).toEqual([]);
    expect(garage?.text).toContain("Votre référence : DOSSIER 2041");
    expect(garage?.text).toContain("Photos : 2 photos transmises à l'atelier");

    const agenda = JSON.parse(readFileSync(path.join(dataDir, "demo-calendar.json"), "utf8"));
    expect(agenda[0].description).toContain("Référence du garage : DOSSIER 2041");
    expect(agenda[0].description).toContain("Photos : 2 photos");
  });

  it("still accepts a booking without photos sent as multipart", async () => {
    const response = await postMultipart(validBody(), []);
    expect(response.status).toBe(201);
    expect((await response.json()).booking.photoCount).toBe(0);
  });

  it("refuses files that are not images, whatever their name or declared type", async () => {
    const response = await postMultipart(validBody(), [
      { name: "facture.jpg", type: "image/jpeg", bytes: Buffer.from("%PDF-1.7 not an image") },
    ]);
    expect(response.status).toBe(400);
    expect((await response.json()).fields.photos).toContain("Seules les photos");
    expect(existsSync(path.join(dataDir, "demo-calendar.json"))).toBe(false);
  });

  it("refuses more than five photos", async () => {
    const photos = Array.from({ length: 6 }, (_, i) => ({ name: `p${i}.jpg`, type: "image/jpeg", bytes: JPEG }));
    const response = await postMultipart(validBody(), photos);
    expect(response.status).toBe(400);
    expect((await response.json()).fields.photos).toContain("5 photos maximum");
  });

  it("refuses an upload that is too heavy before reading it", async () => {
    const big = Buffer.concat([JPEG, Buffer.alloc(5 * 1024 * 1024)]);
    const response = await postMultipart(validBody(), [{ name: "big.jpg", type: "image/jpeg", bytes: big }]);
    expect(response.status).toBe(413);
  });

  it("rejects a garage reference longer than 60 characters", async () => {
    const response = await post({ ...validBody(), garageReference: "R".repeat(61) });
    expect(response.status).toBe(400);
    expect((await response.json()).fields.garageReference).toContain("60 caractères");
  });
});

describe("protected and demo-only endpoints", () => {
  it("requires the cron secret", async () => {
    const call = (auth?: string) =>
      runCron(new Request("http://localhost:3100/api/cron/notifications", { headers: auth ? { authorization: auth } : {} }));
    expect((await call()).status).toBe(503);
    process.env.CRON_SECRET = "cron-secret-value";
    expect((await call()).status).toBe(401);
    expect((await call("Bearer wrong")).status).toBe(401);
    expect((await call("Bearer cron-secret-value")).status).toBe(200);
  });

  it("hides simulated emails as soon as a real email service is configured", async () => {
    process.env.DEMO_MODE = "false";
    process.env.APP_SECRET = "s".repeat(40);
    process.env.RESEND_API_KEY = "re_test";
    process.env.EMAIL_FROM = "B2B Wheels Collect <enlevements@example.be>";
    const response = await getDemoEmail(new Request("http://localhost:3100"), {
      params: Promise.resolve({ id: "0123456789abcdef01234567" }),
    });
    expect(response.status).toBe(404);
  });

  it("reports the active integrations without secrets", async () => {
    process.env.CRON_SECRET = "cron-secret-value";
    const body = await (await getHealth()).json();
    expect(body).toMatchObject({ calendar: "demo", email: "demo", lock: "memory", cronConfigured: true });
    expect(JSON.stringify(body)).not.toContain("cron-secret-value");
  });

  it("refuses to run live services with a weak APP_SECRET", async () => {
    process.env.DEMO_MODE = "false";
    process.env.APP_SECRET = "short";
    process.env.RESEND_API_KEY = "re_test";
    process.env.EMAIL_FROM = "B2B Wheels Collect <enlevements@example.be>";
    await expect(getHealth()).rejects.toThrow("APP_SECRET");
  });
});

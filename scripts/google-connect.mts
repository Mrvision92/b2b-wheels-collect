/**
 * Connects B2B Wheels Collect to VBM's Google account (option B of the README).
 *
 *   npm run google:connect
 *
 * Requires GOOGLE_OAUTH_CLIENT_ID and GOOGLE_OAUTH_CLIENT_SECRET (OAuth client
 * of type "Application de bureau") in .env.local. Opens a consent page, then:
 *   1. obtains a refresh token limited to calendars created by B2B Wheels Collect;
 *   2. creates the "B2B Wheels Collect — Enlèvements" agenda in VBM's account;
 *   3. prints the values to copy into the hosting environment variables.
 *
 * Nothing is written to disk.
 */
import { createHash, randomBytes } from "node:crypto";
import { existsSync } from "node:fs";
import { createServer } from "node:http";

if (existsSync(".env.local")) process.loadEnvFile(".env.local");

const SCOPE = "https://www.googleapis.com/auth/calendar.app.created";
const CALENDAR_NAME = "B2B Wheels Collect — Enlèvements";
const PORT = 53682;
const REDIRECT_URI = `http://127.0.0.1:${PORT}/oauth/callback`;

const clientId = process.env.GOOGLE_OAUTH_CLIENT_ID?.trim();
const clientSecret = process.env.GOOGLE_OAUTH_CLIENT_SECRET?.trim();
if (!clientId || !clientSecret) {
  console.error("GOOGLE_OAUTH_CLIENT_ID et GOOGLE_OAUTH_CLIENT_SECRET doivent être définis dans .env.local.");
  process.exit(1);
}

const state = randomBytes(16).toString("hex");
const verifier = randomBytes(32).toString("base64url");
const challenge = createHash("sha256").update(verifier).digest("base64url");

const consentUrl = new URL("https://accounts.google.com/o/oauth2/v2/auth");
consentUrl.search = new URLSearchParams({
  client_id: clientId,
  redirect_uri: REDIRECT_URI,
  response_type: "code",
  scope: SCOPE,
  access_type: "offline",
  prompt: "consent",
  state,
  code_challenge: challenge,
  code_challenge_method: "S256",
}).toString();

async function exchange(code: string) {
  const response = await fetch("https://oauth2.googleapis.com/token", {
    method: "POST",
    headers: { "content-type": "application/x-www-form-urlencoded" },
    body: new URLSearchParams({
      code,
      client_id: clientId!,
      client_secret: clientSecret!,
      redirect_uri: REDIRECT_URI,
      grant_type: "authorization_code",
      code_verifier: verifier,
    }),
  });
  const json = (await response.json()) as { access_token?: string; refresh_token?: string; error?: string };
  if (!response.ok || !json.access_token || !json.refresh_token) {
    throw new Error(`Échange du code refusé : ${json.error ?? response.status}`);
  }
  return { accessToken: json.access_token, refreshToken: json.refresh_token };
}

async function createCalendar(accessToken: string) {
  const response = await fetch("https://www.googleapis.com/calendar/v3/calendars", {
    method: "POST",
    headers: { authorization: `Bearer ${accessToken}`, "content-type": "application/json" },
    body: JSON.stringify({
      summary: CALENDAR_NAME,
      description: "Enlèvements de jantes réservés en ligne sur B2B Wheels Collect.",
      timeZone: "Europe/Brussels",
    }),
  });
  const json = (await response.json()) as { id?: string; error?: { message?: string } };
  if (!response.ok || !json.id) throw new Error(`Création de l'agenda refusée : ${json.error?.message ?? response.status}`);
  return json.id;
}

const server = createServer(async (req, res) => {
  const url = new URL(req.url ?? "/", REDIRECT_URI);
  if (url.pathname !== "/oauth/callback") {
    res.writeHead(404).end();
    return;
  }
  const done = (status: number, message: string) => {
    res.writeHead(status, { "content-type": "text/html; charset=utf-8" });
    res.end(`<!doctype html><meta charset="utf-8"><body style="font:16px system-ui;padding:40px">${message}</body>`);
  };
  try {
    if (url.searchParams.get("state") !== state) throw new Error("Paramètre state invalide.");
    const error = url.searchParams.get("error");
    if (error) throw new Error(`Autorisation refusée : ${error}`);
    const tokens = await exchange(url.searchParams.get("code") ?? "");
    const calendarId = await createCalendar(tokens.accessToken);
    done(200, "Connexion réussie. Vous pouvez fermer cette page et revenir au terminal.");
    console.log(`\nAgenda « ${CALENDAR_NAME} » créé dans le compte Google connecté.\n`);
    console.log("Ajoutez ces variables d'environnement (hébergeur + .env.local) :\n");
    console.log(`GOOGLE_CALENDAR_ID=${calendarId}`);
    console.log(`GOOGLE_OAUTH_REFRESH_TOKEN=${tokens.refreshToken}`);
    console.log("\nLe jeton ci-dessus est un secret : ne le partagez pas et ne le versionnez pas.");
  } catch (err) {
    done(400, `Échec : ${(err as Error).message}`);
    console.error(`\nÉchec : ${(err as Error).message}`);
    process.exitCode = 1;
  } finally {
    server.close();
  }
});

server.listen(PORT, "127.0.0.1", () => {
  console.log("Ouvrez cette adresse dans votre navigateur, connectée au compte Google professionnel de VBM :\n");
  console.log(consentUrl.toString());
  console.log("\nEn attente de l'autorisation…");
});

/**
 * Checks the real integrations with the current environment variables.
 *
 *   npm run check:services                 read-only checks
 *   npm run check:services -- --write      also creates then deletes a test event
 *   npm run check:services -- --email      also sends a test email to VBM
 *
 * Reads .env.local when present.
 */
import { randomUUID } from "node:crypto";
import { existsSync } from "node:fs";
import { GoogleCalendarClient } from "../src/lib/calendar/google";
import { ResendMailer } from "../src/lib/email/mailer";
import { getServerEnv } from "../src/lib/env";
import { eventIdFor } from "../src/lib/ids";

if (existsSync(".env.local")) process.loadEnvFile(".env.local");

const args = new Set(process.argv.slice(2));
let failures = 0;
const ok = (message: string) => console.log(`  ✓ ${message}`);
const ko = (message: string) => {
  failures++;
  console.log(`  ✗ ${message}`);
};

const env = getServerEnv();
console.log(`\nAPP_URL : ${env.appUrl}`);

console.log("\nGoogle Agenda");
if (env.calendar.mode !== "google") {
  ko("non configuré (GOOGLE_CALENDAR_ID + compte de service ou OAuth) — le site utilise l'agenda de démonstration");
} else {
  const client = new GoogleCalendarClient(env.calendar.calendarId, env.calendar.auth);
  ok(`authentification : ${env.calendar.auth.type === "service_account" ? "compte de service" : "OAuth"}`);
  try {
    const now = new Date();
    const events = await client.listEvents({ timeMin: now, timeMax: new Date(now.getTime() + 14 * 86_400_000) });
    ok(`lecture de l'agenda : ${events.length} événement(s) dans les 14 prochains jours`);
    if (args.has("--write")) {
      const start = new Date(now.getTime() + 400 * 86_400_000);
      const id = eventIdFor(randomUUID(), env.secret);
      const inserted = await client.insertEvent({
        id,
        summary: "TEST B2B Wheels Collect — à supprimer",
        description: "Événement de test créé par npm run check:services.",
        location: "",
        start,
        end: new Date(start.getTime() + 3600_000),
        privateProps: { rcTest: "1" },
      });
      if (!inserted.created) throw new Error("insertion refusée");
      ok("écriture : événement de test créé");
      await client.patchPrivateProps(id, { rcTest: "2" });
      ok("modification : propriétés mises à jour");
      await client.deleteEvent(id);
      ok("suppression : événement de test supprimé");
    } else {
      console.log("    (ajoutez --write pour tester aussi la création d'un événement)");
    }
  } catch (error) {
    ko((error as Error).message);
  }
}

console.log("\nEmails");
if (env.email.mode !== "resend") {
  ko("non configurés (RESEND_API_KEY + EMAIL_FROM) — les emails sont simulés");
} else {
  ok(`expéditeur : ${env.email.from}`);
  if (env.email.vbmRecipients.length === 0) ko("VBM_NOTIFICATION_EMAIL est vide : VBM ne recevra pas les notifications");
  else ok(`notifications VBM : ${env.email.vbmRecipients.join(", ")}`);
  if (args.has("--email") && env.email.vbmRecipients.length > 0) {
    try {
      const mailer = new ResendMailer(env.email.apiKey, env.email.from);
      const { id } = await mailer.send({
        to: env.email.vbmRecipients,
        subject: "Test B2B Wheels Collect — configuration des emails",
        html: "<p>Cet email confirme que B2B Wheels Collect peut envoyer des emails depuis ce domaine.</p>",
        text: "Cet email confirme que B2B Wheels Collect peut envoyer des emails depuis ce domaine.",
        replyTo: env.email.replyTo,
        idempotencyKey: `rimcollect/check/${randomUUID()}`,
      });
      ok(`email de test accepté par Resend (id ${id})`);
    } catch (error) {
      ko((error as Error).message);
    }
  } else if (!args.has("--email")) {
    console.log("    (ajoutez --email pour envoyer un email de test à VBM)");
  }
}

console.log("\nAutres réglages");
if (env.lock.mode === "upstash") ok("verrou partagé Upstash configuré");
else console.log("  · verrou en mémoire (suffisant sur un seul serveur ; Upstash recommandé sur Vercel)");
if (env.cronSecret) ok("CRON_SECRET défini");
else ko("CRON_SECRET manquant : les emails en échec ne seront pas renvoyés automatiquement");

console.log(failures ? `\n${failures} point(s) à corriger.\n` : "\nTout est prêt.\n");
process.exitCode = failures ? 1 : 0;

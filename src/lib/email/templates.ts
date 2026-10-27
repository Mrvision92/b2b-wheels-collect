import { describeBooking, type BookingRecord } from "../booking/record";
import { formatPhone, normalizePhone } from "../phone";

/**
 * Transactional emails, hand-written as table-based HTML for broad client
 * support (Gmail, Outlook, Apple Mail). All user-provided text is escaped.
 * Output is deterministic for a given booking so retries are recognised as
 * the same email by the sending service.
 */

export type EmailContent = { subject: string; html: string; text: string };

export type EmailContext = {
  appUrl: string;
  contactPhone?: string;
  contactEmail?: string;
};

const FONT = "'Mona Sans','Helvetica Neue',Helvetica,Arial,sans-serif";
const INK = "#1d1d1f";
const MUTED = "#6e6e73";
const LINE = "#e3e3e1";
const PAPER = "#f8f8f8";

export function escapeHtml(value: string): string {
  return value
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}

const nl2br = (value: string) => escapeHtml(value).replace(/\n/g, "<br>");

function layout(options: { title: string; preheader: string; body: string; footer: string }): string {
  return `<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>${escapeHtml(options.title)}</title>
<link href="https://fonts.googleapis.com/css2?family=Mona+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  body{margin:0;padding:0;-webkit-text-size-adjust:100%;}
  a{color:${INK};}
  @media (max-width:600px){
    .card{padding:32px 24px!important;}
    .h1{font-size:26px!important;line-height:31px!important;}
    .when{font-size:21px!important;line-height:27px!important;}
    .btn{display:block!important;width:100%!important;box-sizing:border-box;margin:0 0 10px 0!important;text-align:center!important;}
    .label{display:block!important;width:auto!important;padding:14px 0 2px!important;border:0!important;}
    .value{display:block!important;width:auto!important;padding:0 0 14px!important;}
  }
</style>
</head>
<body style="margin:0;padding:0;background:${PAPER};">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;mso-hide:all;">${escapeHtml(options.preheader)}&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:${PAPER};">
<tr><td align="center" style="padding:40px 12px 48px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:580px;">
<tr><td style="padding:0 4px 20px;font-family:${FONT};font-size:18px;font-weight:600;letter-spacing:-0.3px;color:${INK};">B2B Wheels Collect</td></tr>
<tr><td class="card" style="background:#ffffff;border:1px solid ${LINE};padding:44px 48px;font-family:${FONT};color:${INK};">
${options.body}
</td></tr>
<tr><td style="padding:22px 4px 0;font-family:${FONT};font-size:12px;line-height:18px;color:${MUTED};">${options.footer}</td></tr>
</table>
</td></tr>
</table>
</body>
</html>`;
}

function detailRows(rows: [string, string][]): string {
  return `<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top:1px solid ${LINE};margin-top:8px;">
${rows
  .map(
    ([label, value]) => `<tr>
<td class="label" width="38%" valign="top" style="width:38%;padding:13px 16px 13px 0;border-bottom:1px solid ${LINE};font-family:${FONT};font-size:14px;line-height:20px;color:${MUTED};">${escapeHtml(label)}</td>
<td class="value" valign="top" style="padding:13px 0;border-bottom:1px solid ${LINE};font-family:${FONT};font-size:15px;line-height:21px;color:${INK};">${value}</td>
</tr>`,
  )
  .join("\n")}
</table>`;
}

function button(href: string, label: string, primary: boolean): string {
  const style = primary
    ? `background:${INK};color:#ffffff;border:1px solid ${INK};`
    : `background:#ffffff;color:${INK};border:1px solid #d2d2d0;`;
  return `<a class="btn" href="${escapeHtml(href)}" style="${style}display:inline-block;margin:0 8px 10px 0;padding:12px 20px;border-radius:999px;font-family:${FONT};font-size:15px;font-weight:500;line-height:18px;text-decoration:none;">${escapeHtml(label)}</a>`;
}

function contactSentence(ctx: EmailContext): string {
  const phone = ctx.contactPhone ? normalizePhone(ctx.contactPhone) : null;
  return phone ? ` ou appelez-nous au ${formatPhone(phone)}` : "";
}

function host(appUrl: string): string {
  try {
    return new URL(appUrl).host;
  } catch {
    return appUrl;
  }
}

/* ------------------------------------------------------------------ */
/* Garage confirmation                                                 */
/* ------------------------------------------------------------------ */

export function renderGarageConfirmation(b: BookingRecord, ctx: EmailContext): EmailContent {
  const d = describeBooking(b);
  const subject = `Confirmation de votre enlèvement — ${b.reference}`;
  const contact = contactSentence(ctx);

  const rows: [string, string][] = [
    ["Garage", escapeHtml(b.garageName)],
    ["Adresse", escapeHtml(d.address)],
    ["Date", escapeHtml(d.dateLong)],
    ["Créneau", escapeHtml(d.slotLabel)],
    ["Nombre de jantes", String(b.rimCount)],
    ["Référence", `<strong style="font-weight:600;">${escapeHtml(b.reference)}</strong>`],
  ];
  if (b.comment) rows.push(["Commentaire", nl2br(b.comment)]);

  const body = `
<p style="margin:0 0 10px;font-size:14px;line-height:20px;color:${MUTED};">Enlèvement confirmé</p>
<h1 class="h1" style="margin:0 0 28px;font-size:30px;line-height:36px;font-weight:600;letter-spacing:-0.6px;color:${INK};">Votre enlèvement est planifié.</h1>
<p style="margin:0 0 14px;font-size:16px;line-height:24px;">Bonjour ${escapeHtml(b.contactName)},</p>
<p style="margin:0 0 28px;font-size:16px;line-height:24px;">Votre enlèvement de jantes a bien été planifié. Nous passerons à l'adresse indiquée ${escapeHtml(d.slotPhrase)}.</p>
<p class="when" style="margin:0;font-size:23px;line-height:29px;font-weight:600;letter-spacing:-0.4px;color:${INK};">${escapeHtml(d.dateLong)}</p>
<p style="margin:2px 0 24px;font-size:23px;line-height:29px;font-weight:600;letter-spacing:-0.4px;color:${MUTED};">${escapeHtml(d.slotLabel)}</p>
${detailRows(rows)}
<p style="margin:28px 0 0;font-size:15px;line-height:23px;color:${INK};">Besoin de modifier ou d'annuler&nbsp;? Répondez simplement à cet email${escapeHtml(contact)}, en mentionnant votre référence.</p>
<p style="margin:24px 0 0;font-size:15px;line-height:23px;color:${INK};">Merci pour votre confiance.<br>L'équipe B2B Wheels Collect / VBM Wheels Repair</p>`;

  const footer = `Cet email confirme une réservation effectuée sur ${escapeHtml(host(ctx.appUrl))}. Vos coordonnées servent uniquement à organiser cet enlèvement. <a href="${escapeHtml(ctx.appUrl)}/#confidentialite" style="color:${MUTED};">Confidentialité</a>`;

  const text = [
    `Bonjour ${b.contactName},`,
    "",
    "Votre enlèvement de jantes a bien été planifié.",
    "",
    "Récapitulatif :",
    `Garage : ${b.garageName}`,
    `Adresse : ${d.address}`,
    `Date : ${d.dateLong}`,
    `Créneau : ${d.slotLabel}`,
    `Nombre de jantes : ${b.rimCount}`,
    `Référence : ${b.reference}`,
    ...(b.comment ? [`Commentaire : ${b.comment}`] : []),
    "",
    `Besoin de modifier ou d'annuler ? Répondez simplement à cet email${contact}, en mentionnant votre référence.`,
    "",
    "Merci pour votre confiance.",
    "L'équipe B2B Wheels Collect / VBM Wheels Repair",
  ].join("\n");

  return {
    subject,
    html: layout({ title: subject, preheader: `${d.dateLong}, ${d.slotLabel} — ${d.rims}`, body, footer }),
    text,
  };
}

/* ------------------------------------------------------------------ */
/* Notification to VBM                                                 */
/* ------------------------------------------------------------------ */

export function renderVbmNotification(
  b: BookingRecord,
  ctx: EmailContext,
  garageEmailStatus: "sent" | "pending" | "failed",
): EmailContent {
  const d = describeBooking(b);
  const subject = `Nouvel enlèvement — ${b.garageName} — ${d.dateLong}`;
  const subjectLine = subject.replace(/[\r\n]+/g, " ");

  const buttons = [
    button(d.mapsUrl, "Itinéraire Google Maps", true),
    button(`tel:${b.phone}`, `Appeler ${formatPhone(b.phone)}`, false),
    button(`mailto:${b.email}?subject=${encodeURIComponent(`Votre enlèvement ${b.reference}`)}`, "Envoyer un email", false),
    ...(b.htmlLink ? [button(b.htmlLink, "Ouvrir dans Google Agenda", false)] : []),
  ].join("");

  const rows: [string, string][] = [
    ["Garage", escapeHtml(b.garageName)],
    ["Contact", escapeHtml(b.contactName)],
    ["Téléphone", `<a href="tel:${escapeHtml(b.phone)}" style="color:${INK};">${escapeHtml(d.phone)}</a>`],
    ["Email", `<a href="mailto:${escapeHtml(b.email)}" style="color:${INK};">${escapeHtml(b.email)}</a>`],
    ["Adresse", `<a href="${escapeHtml(d.mapsUrl)}" style="color:${INK};">${escapeHtml(d.address)}</a>`],
    ["Date", escapeHtml(d.dateLong)],
    ["Créneau", escapeHtml(d.slotLabel)],
    ["Nombre de jantes", `<strong style="font-weight:600;">${b.rimCount}</strong>`],
    ["Commentaire", b.comment ? nl2br(b.comment) : `<span style="color:${MUTED};">Aucun</span>`],
    ["Référence", escapeHtml(b.reference)],
  ];

  const warning =
    garageEmailStatus === "sent"
      ? ""
      : `<p style="margin:0 0 24px;padding:12px 14px;border:1px solid #f0c4c6;background:#fdf5f5;font-size:14px;line-height:20px;color:#9b1c22;">L'email de confirmation n'a pas encore pu être envoyé au garage. Un nouvel essai est fait automatiquement&nbsp;; en cas de doute, prévenez le garage par téléphone.</p>`;

  const body = `
${warning}<p style="margin:0 0 10px;font-size:14px;line-height:20px;color:${MUTED};">Nouvel enlèvement · ${escapeHtml(b.reference)}</p>
<h1 class="h1" style="margin:0 0 6px;font-size:30px;line-height:36px;font-weight:600;letter-spacing:-0.6px;color:${INK};">${escapeHtml(b.garageName)}</h1>
<p style="margin:0 0 26px;font-size:16px;line-height:24px;color:${MUTED};">${escapeHtml(d.rims)} à enlever</p>
<p class="when" style="margin:0;font-size:23px;line-height:29px;font-weight:600;letter-spacing:-0.4px;">${escapeHtml(d.dateLong)}</p>
<p style="margin:2px 0 26px;font-size:23px;line-height:29px;font-weight:600;letter-spacing:-0.4px;color:${MUTED};">${escapeHtml(d.slotLabel)}</p>
<div style="margin:0 0 22px;">${buttons}</div>
${detailRows(rows)}
<p style="margin:24px 0 0;font-size:13px;line-height:19px;color:${MUTED};">L'enlèvement a été ajouté automatiquement à l'agenda « B2B Wheels Collect — Enlèvements ».</p>`;

  const text = [
    `Nouvel enlèvement — ${b.reference}`,
    "",
    ...(garageEmailStatus === "sent"
      ? []
      : ["ATTENTION : l'email de confirmation n'a pas encore pu être envoyé au garage (nouvel essai automatique).", ""]),
    `Garage : ${b.garageName}`,
    `Contact : ${b.contactName}`,
    `Téléphone : ${d.phone}`,
    `Email : ${b.email}`,
    `Adresse : ${d.address}`,
    `Date : ${d.dateLong}`,
    `Créneau : ${d.slotLabel}`,
    `Nombre de jantes : ${b.rimCount}`,
    `Commentaire : ${b.comment || "aucun"}`,
    "",
    `Itinéraire : ${d.mapsUrl}`,
    ...(b.htmlLink ? [`Google Agenda : ${b.htmlLink}`] : []),
  ].join("\n");

  return {
    subject: subjectLine,
    html: layout({
      title: subjectLine,
      preheader: `${d.dateLong}, ${d.slotLabel} — ${d.rims} — ${d.address}`,
      body,
      footer: "Notification automatique B2B Wheels Collect. Ne pas transférer : contient des données personnelles.",
    }),
    text,
  };
}

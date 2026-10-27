import { DemoMailer } from "@/lib/email/mailer";
import { escapeHtml } from "@/lib/email/templates";
import { getServices } from "@/lib/services";

/**
 * Demo mode only: shows a simulated email exactly as it would have been
 * sent. Returns 404 as soon as a real email service is configured.
 */
export async function GET(_request: Request, context: { params: Promise<{ id: string }> }) {
  const { mailer } = getServices();
  if (!(mailer instanceof DemoMailer)) return new Response("Introuvable", { status: 404 });

  const { id } = await context.params;
  const email = await mailer.read(id);
  if (!email) {
    return new Response("Cet email n'a pas encore été généré. Rechargez la page dans un instant.", {
      status: 404,
      headers: { "content-type": "text/plain; charset=utf-8" },
    });
  }

  const page = `<!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>${escapeHtml(email.subject)}</title>
<style>
  body{margin:0;font:14px/1.45 system-ui,-apple-system,sans-serif;color:#1d1d1f;background:#f2f2f0}
  header{padding:14px 20px;background:#1d1d1f;color:#fff}
  header strong{font-weight:600}
  dl{margin:10px 0 0;display:grid;grid-template-columns:auto 1fr;gap:2px 12px;color:#d2d2d0}
  dd{margin:0;color:#fff;overflow-wrap:anywhere}
  iframe{display:block;width:100%;height:calc(100vh - 120px);border:0;background:#fff}
</style></head>
<body>
<header>
  <strong>Email simulé — mode démonstration, aucun envoi réel</strong>
  <dl><dt>À</dt><dd>${escapeHtml(email.to.join(", "))}</dd><dt>Objet</dt><dd>${escapeHtml(email.subject)}</dd></dl>
</header>
<iframe sandbox="allow-popups allow-popups-to-escape-sandbox" title="Aperçu de l'email" srcdoc="${escapeHtml(email.html)}"></iframe>
</body></html>`;

  return new Response(page, {
    headers: {
      "content-type": "text/html; charset=utf-8",
      "cache-control": "no-store",
      "x-robots-tag": "noindex",
    },
  });
}

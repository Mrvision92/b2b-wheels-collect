import { createHash } from "node:crypto";
import { mkdir, readFile, writeFile } from "node:fs/promises";
import path from "node:path";

export type OutgoingEmail = {
  to: string[];
  subject: string;
  html: string;
  text: string;
  replyTo?: string;
  /** Same key = same email: the provider will not send it twice (24 h window at Resend). */
  idempotencyKey: string;
};

export interface Mailer {
  readonly kind: "resend" | "demo";
  send(email: OutgoingEmail): Promise<{ id: string }>;
}

export class MailerError extends Error {
  constructor(
    message: string,
    readonly retryable: boolean,
  ) {
    super(message);
    this.name = "MailerError";
  }
}

export class ResendMailer implements Mailer {
  readonly kind = "resend" as const;

  constructor(
    private readonly apiKey: string,
    private readonly from: string,
    private readonly fetchImpl: typeof fetch = fetch,
  ) {}

  async send(email: OutgoingEmail): Promise<{ id: string }> {
    let response: Response;
    try {
      response = await this.fetchImpl("https://api.resend.com/emails", {
        method: "POST",
        headers: {
          authorization: `Bearer ${this.apiKey}`,
          "content-type": "application/json",
          "idempotency-key": email.idempotencyKey,
        },
        body: JSON.stringify({
          from: this.from,
          to: email.to,
          subject: email.subject,
          html: email.html,
          text: email.text,
          ...(email.replyTo ? { reply_to: email.replyTo } : {}),
        }),
        signal: AbortSignal.timeout(10_000),
      });
    } catch (error) {
      throw new MailerError(`Resend injoignable : ${(error as Error).message}`, true);
    }
    const json = (await response.json().catch(() => ({}))) as { id?: string; message?: string; name?: string };
    if (response.ok && json.id) return { id: json.id };
    const retryable = response.status === 429 || response.status >= 500;
    throw new MailerError(`Resend a refusé l'envoi (${response.status} ${json.name ?? ""} ${json.message ?? ""})`.trim(), retryable);
  }
}

export type StoredDemoEmail = OutgoingEmail & { id: string; createdAt: string };

/**
 * Demo mode: nothing leaves the machine. Emails are written to disk so they
 * can be previewed at /demo/emails/<id>.
 */
export class DemoMailer implements Mailer {
  readonly kind = "demo" as const;

  constructor(
    private readonly dir: string,
    private readonly failure?: () => Error | undefined,
  ) {}

  static idFor(idempotencyKey: string): string {
    return createHash("sha256").update(idempotencyKey).digest("hex").slice(0, 24);
  }

  async send(email: OutgoingEmail): Promise<{ id: string }> {
    const error = this.failure?.();
    if (error) throw error instanceof MailerError ? error : new MailerError(error.message, true);
    const id = DemoMailer.idFor(email.idempotencyKey);
    const file = path.join(this.dir, `${id}.json`);
    try {
      await readFile(file);
      return { id }; // Already "sent": behave like an idempotent provider.
    } catch {
      await mkdir(this.dir, { recursive: true });
      const stored: StoredDemoEmail = { ...email, id, createdAt: new Date().toISOString() };
      await writeFile(file, JSON.stringify(stored, null, 2));
      return { id };
    }
  }

  async read(id: string): Promise<StoredDemoEmail | null> {
    if (!/^[a-f0-9]{24}$/.test(id)) return null;
    try {
      return JSON.parse(await readFile(path.join(this.dir, `${id}.json`), "utf8")) as StoredDemoEmail;
    } catch {
      return null;
    }
  }
}

import { randomUUID } from "node:crypto";
import type { LockSettings } from "./env";
import { log } from "./log";

/**
 * Serialises bookings on the same slot so two garages cannot take the last
 * place at the same instant.
 *
 * - "memory": exact on a single server process (local, Docker, VPS).
 * - "upstash": shared across serverless instances (recommended on Vercel).
 *
 * A second safety net runs after every insertion (see create-booking.ts).
 */
export interface SlotLock {
  readonly kind: "memory" | "upstash";
  withLock<T>(key: string, fn: () => Promise<T>): Promise<T>;
}

export class LockTimeoutError extends Error {
  constructor() {
    super("Le créneau est en cours de réservation par un autre garage.");
    this.name = "LockTimeoutError";
  }
}

export class MemoryLock implements SlotLock {
  readonly kind = "memory" as const;
  private tails = new Map<string, Promise<void>>();

  async withLock<T>(key: string, fn: () => Promise<T>): Promise<T> {
    const previous = this.tails.get(key) ?? Promise.resolve();
    let release!: () => void;
    const current = new Promise<void>((r) => (release = r));
    const tail = previous.then(() => current);
    this.tails.set(key, tail);
    await previous;
    try {
      return await fn();
    } finally {
      release();
      if (this.tails.get(key) === tail) this.tails.delete(key);
    }
  }
}

const RELEASE_SCRIPT = 'if redis.call("get", KEYS[1]) == ARGV[1] then return redis.call("del", KEYS[1]) else return 0 end';

export class UpstashLock implements SlotLock {
  readonly kind = "upstash" as const;
  private fallback = new MemoryLock();

  constructor(
    private readonly url: string,
    private readonly token: string,
    private readonly options: { ttlMs?: number; waitMs?: number } = {},
  ) {}

  private async command(args: (string | number)[]): Promise<unknown> {
    const response = await fetch(this.url, {
      method: "POST",
      headers: { authorization: `Bearer ${this.token}`, "content-type": "application/json" },
      body: JSON.stringify(args),
      signal: AbortSignal.timeout(4000),
    });
    const json = (await response.json()) as { result?: unknown; error?: string };
    if (!response.ok || json.error) throw new Error(json.error ?? `Upstash ${response.status}`);
    return json.result;
  }

  async withLock<T>(key: string, fn: () => Promise<T>): Promise<T> {
    const lockKey = `rimcollect:lock:${key}`;
    const token = randomUUID();
    const ttl = this.options.ttlMs ?? 25_000;
    const deadline = Date.now() + (this.options.waitMs ?? 12_000);
    let acquired = false;
    try {
      while (!acquired) {
        acquired = (await this.command(["SET", lockKey, token, "NX", "PX", ttl])) === "OK";
        if (acquired) break;
        if (Date.now() > deadline) throw new LockTimeoutError();
        await new Promise((r) => setTimeout(r, 150));
      }
    } catch (error) {
      if (error instanceof LockTimeoutError) throw error;
      // Redis unreachable: keep taking bookings with the local lock; the
      // post-insert verification still prevents overbooking.
      log.warn("lock.upstash_unavailable", { error: (error as Error).message });
      return this.fallback.withLock(key, fn);
    }
    try {
      return await fn();
    } finally {
      await this.command(["EVAL", RELEASE_SCRIPT, 1, lockKey, token]).catch((error) =>
        log.warn("lock.release_failed", { error: (error as Error).message }),
      );
    }
  }
}

export function createLock(settings: LockSettings): SlotLock {
  return settings.mode === "upstash" ? new UpstashLock(settings.url, settings.token) : new MemoryLock();
}

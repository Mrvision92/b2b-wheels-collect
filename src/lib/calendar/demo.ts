import { mkdir, readFile, rename, writeFile } from "node:fs/promises";
import path from "node:path";
import { overlaps } from "../availability";
import {
  CalendarUnavailableError,
  type CalendarClient,
  type CalendarEvent,
  type InsertResult,
  type ListQuery,
  type NewCalendarEvent,
} from "./types";

/**
 * Local stand-in for Google Agenda, used in demo mode and in tests.
 * It mirrors the behaviours the booking logic relies on: unique event ids
 * (409 on duplicates), deleted events keeping their id, and private
 * extended-property filters.
 */

type StoredEvent = Omit<CalendarEvent, "start" | "end" | "created"> & {
  start: string;
  end: string;
  created: string;
};

export type DemoCalendarOptions = {
  /** JSON file used for persistence; null keeps everything in memory. */
  file: string | null;
  /** Simulated network latency per call, in ms. */
  latencyMs?: number;
  /** Events younger than this are not returned by listEvents (eventual consistency). */
  listLagMs?: number;
  /** Test hook: return an error to make the next matching call fail. */
  failure?: (operation: "list" | "get" | "insert" | "patch" | "delete") => Error | undefined;
};

const sleep = (ms: number) => new Promise((r) => setTimeout(r, ms));

function revive(e: StoredEvent): CalendarEvent {
  return { ...e, start: new Date(e.start), end: new Date(e.end), created: new Date(e.created) };
}

export class DemoCalendarClient implements CalendarClient {
  readonly kind = "demo" as const;
  private memory = new Map<string, StoredEvent>();
  private queue: Promise<unknown> = Promise.resolve();

  constructor(private readonly options: DemoCalendarOptions) {}

  /** Serialises access so each operation is atomic, like a single API call. */
  private exclusive<T>(fn: (events: Map<string, StoredEvent>) => Promise<T> | T): Promise<T> {
    const run = this.queue.then(async () => {
      const events = await this.load();
      const result = await fn(events);
      await this.save(events);
      return result;
    });
    this.queue = run.catch(() => undefined);
    return run;
  }

  private async load(): Promise<Map<string, StoredEvent>> {
    if (!this.options.file) return this.memory;
    try {
      const raw = JSON.parse(await readFile(this.options.file, "utf8")) as StoredEvent[];
      return new Map(raw.map((e) => [e.id, e]));
    } catch {
      return new Map();
    }
  }

  private async save(events: Map<string, StoredEvent>) {
    if (!this.options.file) {
      this.memory = events;
      return;
    }
    await mkdir(path.dirname(this.options.file), { recursive: true });
    const tmp = `${this.options.file}.${process.pid}.tmp`;
    await writeFile(tmp, JSON.stringify([...events.values()], null, 2));
    await rename(tmp, this.options.file);
  }

  private async step(operation: Parameters<NonNullable<DemoCalendarOptions["failure"]>>[0]) {
    if (this.options.latencyMs) await sleep(this.options.latencyMs * (0.5 + Math.random()));
    const error = this.options.failure?.(operation);
    if (error) throw error instanceof CalendarUnavailableError ? error : new CalendarUnavailableError(error.message, error);
  }

  async listEvents(query: ListQuery): Promise<CalendarEvent[]> {
    await this.step("list");
    const lag = this.options.listLagMs ?? 0;
    const now = Date.now();
    return this.exclusive((events) =>
      [...events.values()]
        .map(revive)
        .filter((e) => e.status !== "cancelled")
        .filter((e) => !lag || now - (e.created?.getTime() ?? 0) >= lag)
        .filter((e) => overlaps(e, query.timeMin, query.timeMax))
        .filter((e) => Object.entries(query.privateProps ?? {}).every(([k, v]) => e.privateProps[k] === v))
        .sort((a, b) => a.start.getTime() - b.start.getTime()),
    );
  }

  async getEvent(id: string): Promise<CalendarEvent | null> {
    await this.step("get");
    return this.exclusive((events) => {
      const found = events.get(id);
      return found ? revive(found) : null;
    });
  }

  async insertEvent(event: NewCalendarEvent): Promise<InsertResult> {
    await this.step("insert");
    return this.exclusive((events) => {
      if (events.has(event.id)) return { created: false } as const;
      const stored: StoredEvent = {
        id: event.id,
        status: "confirmed",
        summary: event.summary,
        description: event.description,
        location: event.location,
        start: event.start.toISOString(),
        end: event.end.toISOString(),
        allDay: false,
        transparent: false,
        created: new Date().toISOString(),
        privateProps: { ...event.privateProps },
      };
      events.set(stored.id, stored);
      return { created: true, event: revive(stored) } as const;
    });
  }

  async patchPrivateProps(id: string, props: Record<string, string>): Promise<void> {
    await this.step("patch");
    await this.exclusive((events) => {
      const found = events.get(id);
      if (!found) throw new CalendarUnavailableError(`Événement introuvable : ${id}`);
      found.privateProps = { ...found.privateProps, ...props };
    });
  }

  async deleteEvent(id: string): Promise<void> {
    await this.step("delete");
    await this.exclusive((events) => {
      const found = events.get(id);
      // Like Google: a deleted event keeps its id with status "cancelled".
      if (found) found.status = "cancelled";
    });
  }

  /** Test/demo helper: add an event as if VBM had created it in Google Agenda. */
  async addManualEvent(event: {
    id?: string;
    summary: string;
    start: Date;
    end: Date;
    allDay?: boolean;
    transparent?: boolean;
  }): Promise<CalendarEvent> {
    return this.exclusive((events) => {
      const stored: StoredEvent = {
        id: event.id ?? `manual${Math.random().toString(32).slice(2, 12)}`,
        status: "confirmed",
        summary: event.summary,
        start: event.start.toISOString(),
        end: event.end.toISOString(),
        allDay: event.allDay ?? false,
        transparent: event.transparent ?? false,
        created: new Date().toISOString(),
        privateProps: {},
      };
      events.set(stored.id, stored);
      return revive(stored);
    });
  }

  /** Test helper: every stored event, including cancelled ones. */
  async allEvents(): Promise<CalendarEvent[]> {
    return this.exclusive((events) => [...events.values()].map(revive));
  }
}

import type { GoogleAuth } from "../env";
import { zonedTimeToUtc, TIME_ZONE } from "../time";
import { getAccessToken, GoogleAuthError } from "./google-auth";
import {
  CalendarUnavailableError,
  type CalendarClient,
  type CalendarEvent,
  type InsertResult,
  type ListQuery,
  type NewCalendarEvent,
} from "./types";

const API = "https://www.googleapis.com/calendar/v3";
const LIST_FIELDS =
  "items(id,status,summary,start,end,transparency,created,htmlLink,extendedProperties),nextPageToken";

type GoogleEventJson = {
  id: string;
  status?: string;
  summary?: string;
  description?: string;
  location?: string;
  start?: { dateTime?: string; date?: string };
  end?: { dateTime?: string; date?: string };
  transparency?: string;
  created?: string;
  htmlLink?: string;
  extendedProperties?: { private?: Record<string, string> };
};

function toInstant(value: { dateTime?: string; date?: string } | undefined): { at: Date; allDay: boolean } {
  if (value?.dateTime) return { at: new Date(value.dateTime), allDay: false };
  if (value?.date) return { at: zonedTimeToUtc(value.date, "00:00") ?? new Date(`${value.date}T00:00:00Z`), allDay: true };
  return { at: new Date(NaN), allDay: false };
}

export function fromGoogleEvent(json: GoogleEventJson): CalendarEvent {
  const start = toInstant(json.start);
  const end = toInstant(json.end);
  return {
    id: json.id,
    status: json.status === "cancelled" ? "cancelled" : json.status === "tentative" ? "tentative" : "confirmed",
    summary: json.summary ?? "",
    description: json.description,
    location: json.location,
    start: start.at,
    end: end.at,
    allDay: start.allDay,
    transparent: json.transparency === "transparent",
    created: json.created ? new Date(json.created) : undefined,
    htmlLink: json.htmlLink,
    privateProps: json.extendedProperties?.private ?? {},
  };
}

type RequestOptions = {
  query?: URLSearchParams;
  body?: unknown;
  /** Statuses that are expected answers rather than failures. */
  accept?: number[];
};

export class GoogleCalendarClient implements CalendarClient {
  readonly kind = "google" as const;

  constructor(
    private readonly calendarId: string,
    private readonly auth: GoogleAuth,
    private readonly fetchImpl: typeof fetch = fetch,
  ) {}

  private async request(method: string, path: string, options: RequestOptions = {}) {
    const url = `${API}/calendars/${encodeURIComponent(this.calendarId)}${path}${options.query ? `?${options.query}` : ""}`;
    let lastError: unknown;
    // One quick retry on network errors, 429 and 5xx. Inserts are safe to
    // retry because the event id is fixed: a duplicate comes back as 409.
    for (let attempt = 0; attempt < 2; attempt++) {
      if (attempt > 0) await new Promise((r) => setTimeout(r, 400));
      let token: string;
      try {
        token = await getAccessToken(this.auth, this.fetchImpl);
      } catch (error) {
        const status = error instanceof GoogleAuthError ? error.status : undefined;
        lastError = error;
        if (status && status < 500) break;
        continue;
      }
      try {
        const response = await this.fetchImpl(url, {
          method,
          headers: {
            authorization: `Bearer ${token}`,
            ...(options.body ? { "content-type": "application/json" } : {}),
          },
          body: options.body ? JSON.stringify(options.body) : undefined,
          signal: AbortSignal.timeout(10_000),
        });
        if (response.ok || options.accept?.includes(response.status)) return response;
        const detail = await response.text().catch(() => "");
        lastError = new Error(`Google Agenda a répondu ${response.status} ${detail.slice(0, 300)}`);
        if (response.status !== 429 && response.status < 500) break;
      } catch (error) {
        lastError = error;
      }
    }
    throw new CalendarUnavailableError(
      `Google Agenda indisponible : ${(lastError as Error)?.message ?? "erreur inconnue"}`,
      lastError,
    );
  }

  async listEvents(query: ListQuery): Promise<CalendarEvent[]> {
    const events: CalendarEvent[] = [];
    let pageToken: string | undefined;
    do {
      const params = new URLSearchParams({
        timeMin: query.timeMin.toISOString(),
        timeMax: query.timeMax.toISOString(),
        singleEvents: "true",
        showDeleted: "false",
        maxResults: "2500",
        fields: LIST_FIELDS,
      });
      for (const [key, value] of Object.entries(query.privateProps ?? {})) {
        params.append("privateExtendedProperty", `${key}=${value}`);
      }
      if (pageToken) params.set("pageToken", pageToken);
      const response = await this.request("GET", "/events", { query: params });
      const json = (await response.json()) as { items?: GoogleEventJson[]; nextPageToken?: string };
      events.push(...(json.items ?? []).map(fromGoogleEvent));
      pageToken = json.nextPageToken;
    } while (pageToken);
    return events;
  }

  async getEvent(id: string): Promise<CalendarEvent | null> {
    const response = await this.request("GET", `/events/${encodeURIComponent(id)}`, { accept: [404, 410] });
    if (response.status === 404 || response.status === 410) return null;
    return fromGoogleEvent((await response.json()) as GoogleEventJson);
  }

  async insertEvent(event: NewCalendarEvent): Promise<InsertResult> {
    const response = await this.request("POST", "/events", {
      query: new URLSearchParams({ sendUpdates: "none" }),
      accept: [409],
      body: {
        id: event.id,
        summary: event.summary,
        description: event.description,
        location: event.location,
        start: { dateTime: event.start.toISOString(), timeZone: TIME_ZONE },
        end: { dateTime: event.end.toISOString(), timeZone: TIME_ZONE },
        transparency: "opaque",
        extendedProperties: { private: event.privateProps },
      },
    });
    if (response.status === 409) return { created: false };
    return { created: true, event: fromGoogleEvent((await response.json()) as GoogleEventJson) };
  }

  async patchPrivateProps(id: string, props: Record<string, string>): Promise<void> {
    await this.request("PATCH", `/events/${encodeURIComponent(id)}`, {
      query: new URLSearchParams({ sendUpdates: "none" }),
      body: { extendedProperties: { private: props } },
    });
  }

  async deleteEvent(id: string): Promise<void> {
    await this.request("DELETE", `/events/${encodeURIComponent(id)}`, {
      query: new URLSearchParams({ sendUpdates: "none" }),
      accept: [404, 410],
    });
  }
}

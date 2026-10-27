/**
 * Minimal agenda abstraction. The Google implementation talks to the real
 * Google Calendar API; the demo implementation keeps events in a local file
 * so the whole flow can be tried without any external account.
 */

export type CalendarEvent = {
  id: string;
  status: "confirmed" | "tentative" | "cancelled";
  summary: string;
  description?: string;
  location?: string;
  start: Date;
  end: Date;
  allDay: boolean;
  /** Events marked "Disponible" in Google Agenda do not use capacity. */
  transparent: boolean;
  created?: Date;
  htmlLink?: string;
  privateProps: Record<string, string>;
};

export type NewCalendarEvent = {
  id: string;
  summary: string;
  description: string;
  location: string;
  start: Date;
  end: Date;
  privateProps: Record<string, string>;
};

export type ListQuery = {
  timeMin: Date;
  timeMax: Date;
  /** Only events whose private extended properties match all pairs. */
  privateProps?: Record<string, string>;
};

export type InsertResult = { created: true; event: CalendarEvent } | { created: false };

export interface CalendarClient {
  readonly kind: "google" | "demo";
  listEvents(query: ListQuery): Promise<CalendarEvent[]>;
  getEvent(id: string): Promise<CalendarEvent | null>;
  /** Returns { created: false } when an event with the same id already exists. */
  insertEvent(event: NewCalendarEvent): Promise<InsertResult>;
  patchPrivateProps(id: string, props: Record<string, string>): Promise<void>;
  deleteEvent(id: string): Promise<void>;
}

/** The agenda could not be reached or refused the request. Nothing was booked. */
export class CalendarUnavailableError extends Error {
  constructor(
    message: string,
    readonly cause?: unknown,
  ) {
    super(message);
    this.name = "CalendarUnavailableError";
  }
}

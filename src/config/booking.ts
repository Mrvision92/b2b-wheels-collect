/**
 * Réglages des disponibilités RimCollect.
 *
 * C'est le seul fichier à modifier pour changer les jours, horaires et
 * créneaux proposés aux garages. Après modification : redéployer le site.
 *
 * Pour fermer ponctuellement un créneau ou une journée sans redéployer,
 * VBM peut aussi créer un événement dans l'agenda Google « RimCollect —
 * Enlèvements » (voir README, section « Bloquer un créneau »).
 */

export type TimeWindow = {
  /** Heure de début, format 24 h « HH:MM » (heure de Bruxelles). */
  from: string;
  /** Heure de fin, format 24 h « HH:MM » (heure de Bruxelles). */
  to: string;
};

export type BookingConfig = {
  timeZone: "Europe/Brussels";
  /**
   * "ranges" : plages d'enlèvement affichées « 9 h – 11 h ».
   * "times"  : heures précises affichées « 9 h 30 ».
   */
  mode: "ranges" | "times";
  /** Durée d'un créneau (et de l'événement Google Agenda), en minutes. */
  slotMinutes: number;
  /**
   * Intervalle entre deux débuts de créneau, en minutes.
   * Par défaut égal à slotMinutes (créneaux consécutifs).
   */
  slotStepMinutes?: number;
  /** Nombre maximum d'enlèvements acceptés sur un même créneau. */
  capacityPerSlot: number;
  /** Délai minimum entre la réservation et le début du créneau, en heures. */
  minNoticeHours: number;
  /** Nombre de jours à l'avance pendant lesquels on peut réserver. */
  maxAdvanceDays: number;
  /** Ferme automatiquement les jours fériés légaux belges. */
  closeOnPublicHolidays: boolean;
  /** Jours de fermeture exceptionnels, format « AAAA-MM-JJ ». */
  closedDates: string[];
  /**
   * Plages ouvertes par jour de la semaine (1 = lundi … 7 = dimanche).
   * Un jour absent ou vide est fermé.
   */
  weeklySchedule: Partial<Record<1 | 2 | 3 | 4 | 5 | 6 | 7, TimeWindow[]>>;
  /** Nombre maximum de jantes pour une même réservation. */
  maxRimsPerBooking: number;
};

export const bookingConfig: BookingConfig = {
  timeZone: "Europe/Brussels",
  mode: "ranges",
  slotMinutes: 120,
  capacityPerSlot: 2,
  minNoticeHours: 12,
  maxAdvanceDays: 60,
  closeOnPublicHolidays: true,
  closedDates: [],
  weeklySchedule: {
    1: [{ from: "09:00", to: "17:00" }],
    2: [{ from: "09:00", to: "17:00" }],
    3: [{ from: "09:00", to: "17:00" }],
    4: [{ from: "09:00", to: "17:00" }],
    5: [{ from: "09:00", to: "17:00" }],
  },
  maxRimsPerBooking: 40,
};

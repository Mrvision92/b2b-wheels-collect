import { z } from "zod";
import { bookingConfig } from "@/config/booking";
import { normalizePhone } from "./phone";
import { isValidDateString } from "./time";

/**
 * The booking form contract, shared by the browser (instant feedback) and the
 * server (the only validation that counts).
 */

const CONTROL_CHARS = /[\u0000-\u001f\u007f]+/g;
const CONTROL_CHARS_EXCEPT_NEWLINE = /[\u0000-\u0009\u000b-\u001f\u007f]+/g;

const singleLine = (min: number, max: number, minMessage: string, maxMessage: string) =>
  z
    .string(minMessage)
    .transform((v) => v.replace(CONTROL_CHARS, " ").replace(/\s+/g, " ").trim())
    .pipe(z.string().min(min, minMessage).max(max, maxMessage));

export const bookingSchema = z.object({
  requestId: z.uuid("Requête invalide. Rechargez la page."),
  garageName: singleLine(2, 120, "Indiquez le nom du garage.", "Ce nom est trop long (120 caractères maximum)."),
  contactName: singleLine(3, 120, "Indiquez le prénom et le nom.", "Ce nom est trop long (120 caractères maximum).").refine(
    (v) => v.split(" ").filter((part) => part.length > 0).length >= 2,
    "Indiquez le prénom et le nom.",
  ),
  phone: z
    .string("Indiquez un numéro de téléphone.")
    .max(40, "Numéro de téléphone invalide.")
    .transform((v, ctx) => {
      if (!v.trim()) {
        ctx.addIssue({ code: "custom", message: "Indiquez un numéro de téléphone." });
        return z.NEVER;
      }
      const normalized = normalizePhone(v);
      if (!normalized) {
        ctx.addIssue({ code: "custom", message: "Numéro invalide. Exemple : 0470 12 34 56 ou +32 2 123 45 67." });
        return z.NEVER;
      }
      return normalized;
    }),
  email: z
    .string("Indiquez une adresse email.")
    .trim()
    .min(1, "Indiquez une adresse email.")
    .max(254, "Adresse email trop longue.")
    .pipe(z.email("Adresse email invalide. Exemple : atelier@garage.be")),
  street: singleLine(3, 160, "Indiquez la rue et le numéro.", "Adresse trop longue (160 caractères maximum).").refine(
    (v) => /\d/.test(v),
    "Ajoutez le numéro de rue.",
  ),
  postalCode: z
    .string("Indiquez le code postal.")
    .trim()
    .min(1, "Indiquez le code postal.")
    .regex(/^[1-9]\d{3}$/, "Code postal belge à 4 chiffres."),
  city: singleLine(2, 80, "Indiquez la ville.", "Nom de ville trop long."),
  rimCount: z.coerce
    .number("Indiquez le nombre de jantes.")
    .int("Indiquez un nombre entier.")
    .min(1, "Indiquez au moins 1 jante.")
    .max(bookingConfig.maxRimsPerBooking, `Pour plus de ${bookingConfig.maxRimsPerBooking} jantes, contactez-nous directement.`),
  date: z.string("Choisissez une date.").refine(isValidDateString, "Choisissez une date."),
  slot: z.string("Choisissez un créneau.").regex(/^\d{4}$/, "Choisissez un créneau."),
  comment: z
    .string()
    .max(2000, "Commentaire trop long (500 caractères maximum).")
    .optional()
    .transform((v) => (v ?? "").replace(CONTROL_CHARS_EXCEPT_NEWLINE, " ").trim())
    .pipe(z.string().max(500, "Commentaire trop long (500 caractères maximum).")),
  turnstileToken: z.string().max(4096).optional(),
});

export type BookingInput = z.output<typeof bookingSchema>;
export type BookingFormValues = z.input<typeof bookingSchema>;
export type BookingField = keyof BookingInput;
export type FieldErrors = Partial<Record<BookingField, string>>;

export function fieldErrorsFrom(error: z.ZodError): FieldErrors {
  const errors: FieldErrors = {};
  for (const issue of error.issues) {
    const field = issue.path[0] as BookingField | undefined;
    if (field && !errors[field]) errors[field] = issue.message;
  }
  return errors;
}

/** Validates a single field in isolation (used on blur in the form). */
export function validateField(field: BookingField, value: unknown): string | undefined {
  const shape = bookingSchema.shape[field];
  const result = shape.safeParse(value);
  return result.success ? undefined : result.error.issues[0]?.message;
}

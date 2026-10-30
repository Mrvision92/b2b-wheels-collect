"use client";

import { useRef, useState, type FormEvent } from "react";
import type { PublicDay } from "@/lib/availability";
import { capitalize, formatDayMonth } from "@/lib/time";
import {
  bookingSchema,
  fieldErrorsFrom,
  validateField,
  type BookingField,
  type BookingFormValues,
  type FieldErrors,
} from "@/lib/validation";
import { Confirmation, type BookingResponse } from "./confirmation";
import { DatePicker } from "./date-picker";
import { describedBy, Field, SectionTitle } from "./field";
import { PHOTO_LIMITS } from "@/lib/photo-limits";
import { PhotoPicker, type PickedPhoto } from "./photo-picker";
import { RimCount, type RimChoice } from "./rim-count";
import { SlotPicker } from "./slot-picker";
import { Turnstile } from "./turnstile";
import { useAvailability } from "./use-availability";

type Values = {
  garageName: string;
  contactName: string;
  phone: string;
  email: string;
  street: string;
  postalCode: string;
  city: string;
  rimChoice: RimChoice;
  rimOther: string;
  comment: string;
  garageReference: string;
  date: string;
  slot: string;
  website: string;
};

type FormErrors = FieldErrors & { photos?: string };

const EMPTY: Values = {
  garageName: "",
  contactName: "",
  phone: "",
  email: "",
  street: "",
  postalCode: "",
  city: "",
  rimChoice: "",
  rimOther: "",
  comment: "",
  garageReference: "",
  date: "",
  slot: "",
  website: "",
};

/** Order used to focus the first invalid field (mobile reading order). */
const FIELD_ORDER: (BookingField | "photos")[] = [
  "garageName",
  "contactName",
  "phone",
  "email",
  "street",
  "postalCode",
  "city",
  "rimCount",
  "garageReference",
  "comment",
  "photos",
  "date",
  "slot",
];

const FOCUS_TARGET: Partial<Record<BookingField | "photos", string>> = {
  rimCount: '#rimCount, input[name="rimChoice"]',
  date: 'input[name="date"]:checked, input[name="date"]:not(:disabled)',
  slot: 'input[name="slot"]:not(:disabled)',
  photos: "#photos",
};

const REQUEST_KEY = "rimcollect.requestId";

function storedRequestId(): string | null {
  try {
    return sessionStorage.getItem(REQUEST_KEY);
  } catch {
    return null;
  }
}

function storeRequestId(id: string | null) {
  try {
    if (id) sessionStorage.setItem(REQUEST_KEY, id);
    else sessionStorage.removeItem(REQUEST_KEY);
  } catch {
    /* private browsing: the in-memory id is enough */
  }
}

function Spinner() {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true" className="size-5 [animation:rc-spin_0.8s_linear_infinite]" fill="none">
      <circle cx="12" cy="12" r="9" stroke="currentColor" strokeOpacity="0.3" strokeWidth="2.5" />
      <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" />
    </svg>
  );
}

type Props = {
  maxRims: number;
  turnstileSiteKey?: string;
};

export function BookingForm({ maxRims, turnstileSiteKey }: Props) {
  const availability = useAvailability();
  const data = availability.state.data;
  const loadingMonths = availability.state.status === "loading";

  const [values, setValues] = useState<Values>(EMPTY);
  const [errors, setErrors] = useState<FormErrors>({});
  const [photos, setPhotos] = useState<PickedPhoto[]>([]);
  const [formError, setFormError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [result, setResult] = useState<BookingResponse | null>(null);
  const [savedDay, setSavedDay] = useState<PublicDay | null>(null);
  const [turnstileToken, setTurnstileToken] = useState("");
  const [turnstileReset, setTurnstileReset] = useState(0);
  const inFlight = useRef(false);
  const requestId = useRef<string | null>(null);
  const formRef = useRef<HTMLFormElement>(null);

  // The selected day, refreshed whenever its month is (re)loaded.
  const liveDay = data && values.date.startsWith(data.month) ? data.days.find((d) => d.date === values.date) : undefined;
  const day = liveDay ?? (values.date ? savedDay : null);
  const selectedSlot = day?.slots.find((s) => s.key === values.slot);
  const slotNoLongerFree = !!values.slot && !!liveDay && !selectedSlot?.available;

  const rimCountValue = values.rimChoice === "other" ? values.rimOther : values.rimChoice || undefined;

  function payload(): BookingFormValues & { website: string } {
    if (!requestId.current) {
      requestId.current = storedRequestId() ?? crypto.randomUUID();
      storeRequestId(requestId.current);
    }
    return {
      requestId: requestId.current,
      garageName: values.garageName,
      contactName: values.contactName,
      phone: values.phone,
      email: values.email,
      street: values.street,
      postalCode: values.postalCode,
      city: values.city,
      rimCount: rimCountValue,
      comment: values.comment,
      garageReference: values.garageReference,
      date: values.date,
      slot: values.slot,
      turnstileToken: turnstileToken || undefined,
      website: values.website,
    };
  }

  function update<K extends keyof Values>(key: K, value: Values[K]) {
    setValues((prev) => ({ ...prev, [key]: value }));
    setFormError(null);
    const field = key as BookingField;
    if (errors[field]) {
      setErrors((prev) => ({ ...prev, [field]: validateField(field, value) }));
    }
  }

  function blur(field: BookingField, value: unknown) {
    if (value === "" || value === undefined) return;
    setErrors((prev) => ({ ...prev, [field]: validateField(field, value) }));
  }

  function focusFirstError(fieldErrors: FormErrors) {
    const first = FIELD_ORDER.find((f) => fieldErrors[f]);
    if (!first || !formRef.current) return;
    const target = formRef.current.querySelector<HTMLElement>(FOCUS_TARGET[first] ?? `#${first}`);
    target?.focus({ preventScroll: true });
    (target?.closest("fieldset, div") ?? target)?.scrollIntoView({ behavior: "smooth", block: "center" });
  }

  function selectDate(date: string) {
    const picked = data?.days.find((d) => d.date === date) ?? null;
    setSavedDay(picked);
    setValues((prev) => ({ ...prev, date, slot: prev.date === date ? prev.slot : "" }));
    setErrors((prev) => ({ ...prev, date: undefined, slot: undefined }));
    setFormError(null);
  }

  function selectSlot(slot: string) {
    setValues((prev) => ({ ...prev, slot }));
    setErrors((prev) => ({ ...prev, slot: undefined }));
    setFormError(null);
  }

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (inFlight.current) return;

    const body = payload();
    const parsed = bookingSchema.safeParse(body);
    const fieldErrors: FormErrors = parsed.success ? {} : fieldErrorsFrom(parsed.error);
    if (photos.reduce((sum, p) => sum + p.file.size, 0) > PHOTO_LIMITS.maxTotalBytes) {
      fieldErrors.photos = "Les photos sont trop lourdes. Retirez-en une ou deux.";
    }
    if (slotNoLongerFree) fieldErrors.slot = "Ce créneau vient d'être complété. Choisissez-en un autre.";
    if (Object.keys(fieldErrors).length > 0) {
      setErrors(fieldErrors);
      setFormError("Vérifiez les champs signalés.");
      focusFirstError(fieldErrors);
      return;
    }
    if (photos.some((p) => !p.ready)) {
      setFormError("Les photos sont en cours de préparation. Patientez une seconde puis confirmez à nouveau.");
      return;
    }
    if (turnstileSiteKey && !turnstileToken) {
      setFormError("Vérification anti-spam en cours. Patientez une seconde puis confirmez à nouveau.");
      return;
    }

    inFlight.current = true;
    setSubmitting(true);
    setFormError(null);
    try {
      const form = new FormData();
      form.append("data", JSON.stringify(body));
      photos.forEach((photo, index) => form.append("photos", photo.file, `photo-${index + 1}`));
      const response = await fetch("/api/bookings", {
        method: "POST",
        body: form,
        signal: AbortSignal.timeout(photos.length ? 60_000 : 30_000),
      });
      const json = await response.json().catch(() => null);

      if (response.ok && json?.ok) {
        requestId.current = null;
        storeRequestId(null);
        setResult(json as BookingResponse);
        return;
      }
      if (response.status === 400 && json?.fields) {
        setErrors(json.fields);
        setFormError(json.message ?? "Vérifiez les champs signalés.");
        focusFirstError(json.fields);
        return;
      }
      if (response.status === 409) {
        // Nothing was booked with this request: start a fresh one next time.
        requestId.current = null;
        storeRequestId(null);
        setValues((prev) => ({ ...prev, slot: "" }));
        availability.refresh();
      }
      setFormError(
        json?.message ??
          "Votre enlèvement n'a pas pu être confirmé. Réessayez dans un instant, vous ne risquez pas de réserver deux fois.",
      );
    } catch {
      setFormError(
        "La connexion a été interrompue avant la confirmation. Vérifiez votre connexion puis cliquez à nouveau : vous ne risquez pas de réserver deux fois.",
      );
    } finally {
      inFlight.current = false;
      setSubmitting(false);
      if (turnstileSiteKey) {
        setTurnstileToken("");
        setTurnstileReset((n) => n + 1);
      }
    }
  }

  function reset() {
    photos.forEach((photo) => URL.revokeObjectURL(photo.preview));
    setPhotos([]);
    setResult(null);
    setValues(EMPTY);
    setErrors({});
    setFormError(null);
    setSavedDay(null);
    availability.refresh();
  }

  const input = (field: Exclude<BookingField, "rimCount" | "date" | "slot" | "requestId" | "turnstileToken">) => ({
    id: field,
    name: field,
    value: values[field],
    onChange: (e: { target: { value: string } }) => update(field, e.target.value),
    onBlur: () => blur(field, values[field]),
    ...describedBy(field, errors[field]),
  });

  return (
    <section id="reservation" aria-label="Réservation d'un enlèvement" className="mx-auto w-full max-w-[1120px] scroll-mt-20 sm:px-6">
      <div className="border-y border-hairline bg-surface sm:border">
        {result ? (
          <Confirmation result={result} onReset={reset} />
        ) : (
          <form
            ref={formRef}
            noValidate
            onSubmit={onSubmit}
            className="grid lg:grid-cols-[minmax(0,1.12fr)_minmax(0,1fr)]"
            aria-busy={submitting}
          >
            <div className="space-y-11 px-5 py-10 sm:px-10 sm:py-12 lg:border-r lg:border-hairline">
              <div className="space-y-5">
                <SectionTitle>Le garage</SectionTitle>
                <Field id="garageName" label="Nom du garage" error={errors.garageName}>
                  <input {...input("garageName")} type="text" autoComplete="organization" maxLength={120} className="field-input" />
                </Field>
                <Field id="contactName" label="Nom et prénom" error={errors.contactName}>
                  <input
                    {...input("contactName")}
                    type="text"
                    autoComplete="name"
                    autoCapitalize="words"
                    maxLength={120}
                    className="field-input"
                  />
                </Field>
                <div className="grid gap-5 sm:grid-cols-2">
                  <Field id="phone" label="Téléphone" error={errors.phone}>
                    <input
                      {...input("phone")}
                      type="tel"
                      inputMode="tel"
                      autoComplete="tel"
                      placeholder="0470 12 34 56"
                      maxLength={40}
                      className="field-input"
                    />
                  </Field>
                  <Field id="email" label="Email" error={errors.email}>
                    <input
                      {...input("email")}
                      type="email"
                      inputMode="email"
                      autoComplete="email"
                      autoCapitalize="off"
                      spellCheck={false}
                      placeholder="atelier@garage.be"
                      maxLength={254}
                      className="field-input"
                    />
                  </Field>
                </div>
              </div>

              <div className="space-y-5">
                <SectionTitle>Adresse d&apos;enlèvement</SectionTitle>
                <Field id="street" label="Rue et numéro" error={errors.street}>
                  <input
                    {...input("street")}
                    type="text"
                    autoComplete="address-line1"
                    maxLength={160}
                    className="field-input"
                  />
                </Field>
                <div className="grid grid-cols-[7.5rem_minmax(0,1fr)] gap-4 sm:gap-5">
                  <Field id="postalCode" label="Code postal" error={errors.postalCode}>
                    <input
                      {...input("postalCode")}
                      type="text"
                      inputMode="numeric"
                      autoComplete="postal-code"
                      maxLength={4}
                      className="field-input"
                    />
                  </Field>
                  <Field id="city" label="Ville" error={errors.city}>
                    <input {...input("city")} type="text" autoComplete="address-level2" maxLength={80} className="field-input" />
                  </Field>
                </div>
              </div>

              <div className="space-y-5">
                <SectionTitle>Les jantes</SectionTitle>
                <RimCount
                  choice={values.rimChoice}
                  other={values.rimOther}
                  max={maxRims}
                  onChoice={(choice) => {
                    setValues((prev) => ({ ...prev, rimChoice: choice }));
                    setErrors((prev) => ({ ...prev, rimCount: undefined }));
                  }}
                  onOther={(value) => {
                    update("rimOther", value);
                    if (errors.rimCount) setErrors((prev) => ({ ...prev, rimCount: validateField("rimCount", value) }));
                  }}
                  onOtherBlur={() => blur("rimCount", values.rimOther)}
                  error={errors.rimCount}
                />
                <Field id="garageReference" label="Votre référence" optional error={errors.garageReference}>
                  <input
                    {...input("garageReference")}
                    type="text"
                    autoComplete="off"
                    maxLength={60}
                    placeholder="N° de dossier, immatriculation…"
                    className="field-input"
                  />
                </Field>
                <Field id="comment" label="Commentaire" optional error={errors.comment}>
                  <textarea
                    {...input("comment")}
                    rows={3}
                    maxLength={500}
                    placeholder="Exemple : jantes avec pneus, accès par l'arrière de l'atelier…"
                    className="field-input"
                  />
                </Field>
                <PhotoPicker
                  photos={photos}
                  onChange={setPhotos}
                  error={errors.photos}
                  onError={(message) => setErrors((prev) => ({ ...prev, photos: message }))}
                />
              </div>

              {/* Honeypot: hidden from people and assistive technologies. */}
              <div aria-hidden="true" className="absolute -left-[9999px] h-px w-px overflow-hidden">
                <label htmlFor="website">Site web</label>
                <input
                  id="website"
                  name="website"
                  type="text"
                  tabIndex={-1}
                  autoComplete="off"
                  value={values.website}
                  onChange={(e) => update("website", e.target.value)}
                />
              </div>
            </div>

            <div className="border-t border-hairline px-5 py-10 sm:px-10 sm:py-12 lg:border-t-0">
              <div className="space-y-10 lg:sticky lg:top-8">
                <DatePicker
                  data={data}
                  loading={loadingMonths}
                  errorMessage={availability.state.status === "error" ? availability.state.message : undefined}
                  onRetry={availability.refresh}
                  onMonthChange={availability.showMonth}
                  selectedDate={values.date}
                  onSelect={selectDate}
                  error={errors.date}
                />
                <SlotPicker
                  day={day}
                  mode={data?.mode ?? "ranges"}
                  selected={values.slot}
                  onSelect={selectSlot}
                  error={
                    errors.slot ??
                    (slotNoLongerFree ? "Ce créneau vient d'être complété. Choisissez-en un autre." : undefined)
                  }
                />

                <div className="border-t border-hairline pt-6">
                  <p className="text-[15px] leading-relaxed text-muted" aria-live="polite">
                    {values.date && selectedSlot ? (
                      <>
                        <span className="font-medium text-ink">{capitalize(formatDayMonth(values.date))}</span>
                        {`, ${selectedSlot.label}`}
                        {rimCountValue ? ` — ${rimCountValue} jante${Number(rimCountValue) > 1 ? "s" : ""}` : ""}
                      </>
                    ) : (
                      "Choisissez une date et un créneau pour confirmer."
                    )}
                  </p>

                  {formError ? (
                    <div
                      role="alert"
                      className="mt-4 border border-[#efc9cb] bg-danger-soft px-4 py-3 text-[14.5px] leading-snug text-danger"
                    >
                      {formError}
                    </div>
                  ) : null}

                  {turnstileSiteKey ? (
                    <div className="mt-4">
                      <Turnstile siteKey={turnstileSiteKey} onToken={setTurnstileToken} resetSignal={turnstileReset} />
                    </div>
                  ) : null}

                  <button
                    type="submit"
                    disabled={submitting}
                    className="mt-5 flex h-[3.4rem] w-full items-center justify-center gap-2.5 rounded-full bg-ink px-6 text-[17px] font-medium text-white transition-[background-color,transform] duration-150 hover:bg-black active:scale-[0.99] disabled:cursor-wait disabled:bg-graphite"
                  >
                    {submitting ? (
                      <>
                        <Spinner />
                        Confirmation en cours…
                      </>
                    ) : (
                      "Confirmer mon enlèvement"
                    )}
                  </button>
                  <p className="mt-4 text-[13px] leading-relaxed text-muted">
                    Vos coordonnées servent uniquement à organiser l&apos;enlèvement.{" "}
                    <a href="#confidentialite" className="text-ink underline underline-offset-2">
                      Confidentialité
                    </a>
                  </p>
                </div>
              </div>
            </div>
          </form>
        )}
      </div>
    </section>
  );
}

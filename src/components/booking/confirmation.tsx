"use client";

import { useEffect, useRef } from "react";
import type { PublicBookingSummary } from "@/lib/booking/record";

export type BookingResponse = {
  ok: true;
  alreadyExisted: boolean;
  booking: PublicBookingSummary;
  emailStatus: "sent" | "pending" | "simulated";
  demo: { calendar: boolean; email: boolean; previews?: { garage: string; vbm: string } };
};

function EmailLine({ result }: { result: BookingResponse }) {
  const { booking, emailStatus, alreadyExisted } = result;
  if (alreadyExisted) {
    return (
      <>
        Cette réservation était déjà enregistrée&nbsp;: aucun doublon n&apos;a été créé. Le récapitulatif a été envoyé à{" "}
        <span className="text-ink">{booking.email}</span>.
      </>
    );
  }
  if (emailStatus === "pending") {
    return (
      <>
        Votre réservation est bien enregistrée. L&apos;email récapitulatif n&apos;a pas pu partir immédiatement&nbsp;: il sera
        renvoyé automatiquement à <span className="text-ink">{booking.email}</span>. Notez votre référence.
      </>
    );
  }
  if (emailStatus === "simulated") {
    return (
      <>
        Un email récapitulatif a été préparé pour <span className="text-ink">{booking.email}</span>.
      </>
    );
  }
  return (
    <>
      Un email récapitulatif vient de vous être envoyé à <span className="text-ink">{booking.email}</span>.
    </>
  );
}

export function Confirmation({ result, onReset }: { result: BookingResponse; onReset: () => void }) {
  const heading = useRef<HTMLHeadingElement>(null);
  const { booking, demo } = result;

  useEffect(() => {
    heading.current?.focus({ preventScroll: true });
    heading.current?.closest("section")?.scrollIntoView({ behavior: "smooth", block: "start" });
  }, []);

  const rows: [string, string][] = [
    ["Date", booking.dateLabel],
    ["Créneau", booking.slotLabel],
    ["Garage", booking.garageName],
    ["Adresse", booking.address],
    ["Jantes", booking.rimsLabel],
  ];
  if (booking.garageReference) rows.push(["Votre réf.", booking.garageReference]);
  if (booking.photoCount > 0) {
    rows.push(["Photos", `${booking.photosLabel} transmise${booking.photoCount > 1 ? "s" : ""}`]);
  }

  return (
    <div className="mx-auto max-w-[34rem] px-5 py-12 sm:px-8 sm:py-16 [animation:rc-fade-up_420ms_var(--ease-out-soft)]">
      <div className="flex size-14 items-center justify-center rounded-full bg-ink text-white">
        <svg viewBox="0 0 24 24" aria-hidden="true" className="size-7" fill="none" stroke="currentColor" strokeWidth="2.2">
          <path
            d="M5.5 12.5l4.2 4.2L18.5 8"
            strokeLinecap="round"
            strokeLinejoin="round"
            strokeDasharray="24"
            className="[animation:rc-check_420ms_160ms_var(--ease-out-soft)_both]"
          />
        </svg>
      </div>

      <h2
        ref={heading}
        tabIndex={-1}
        className="mt-7 text-[clamp(1.9rem,1.5rem+1.6vw,2.6rem)] font-semibold leading-[1.08] tracking-[-0.025em] outline-none"
      >
        Votre enlèvement est confirmé.
      </h2>
      <p className="mt-3 text-[17px] leading-relaxed text-muted" role="status">
        <EmailLine result={result} />
      </p>

      {demo.email || demo.calendar ? (
        <div className="mt-5 border border-hairline bg-paper px-4 py-3 text-[14px] leading-relaxed text-graphite">
          <p>
            Mode démonstration&nbsp;: {demo.calendar ? "l'agenda Google est simulé" : "l'événement a été ajouté à Google Agenda"}
            {demo.email ? " et aucun email réel n'a été envoyé." : "."}
          </p>
          {demo.previews ? (
            <p className="mt-1.5 flex flex-wrap gap-x-4 gap-y-1">
              <a href={demo.previews.garage} target="_blank" rel="noopener" className="font-medium text-ink underline underline-offset-2">
                Voir l&apos;email du garage
              </a>
              <a href={demo.previews.vbm} target="_blank" rel="noopener" className="font-medium text-ink underline underline-offset-2">
                Voir la notification VBM
              </a>
            </p>
          ) : null}
        </div>
      ) : null}

      <div className="mt-9 border-y border-hairline py-5">
        <p className="text-[13.5px] text-muted">Référence</p>
        <p className="mt-0.5 text-[28px] font-semibold tracking-[0.02em]">{booking.reference}</p>
      </div>

      <dl className="divide-y divide-hairline border-b border-hairline">
        {rows.map(([label, value]) => (
          <div key={label} className="grid grid-cols-[6.5rem_1fr] gap-4 py-3.5 text-[15.5px]">
            <dt className="text-muted">{label}</dt>
            <dd className="text-ink">{value}</dd>
          </div>
        ))}
      </dl>

      <p className="mt-6 text-[15px] leading-relaxed text-muted">
        Pour modifier ou annuler, répondez simplement à l&apos;email de confirmation en indiquant votre référence.
      </p>

      <button
        type="button"
        onClick={onReset}
        className="mt-8 inline-flex h-12 items-center justify-center rounded-full border border-line px-6 text-[16px] font-medium text-ink transition-colors hover:border-ink"
      >
        Planifier un autre enlèvement
      </button>
    </div>
  );
}

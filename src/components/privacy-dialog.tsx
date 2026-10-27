"use client";

import { useEffect, useRef, type ReactNode } from "react";
import type { PublicSettings } from "@/lib/env";

const HASH = "#confidentialite";

function ToComplete({ value, hint }: { value?: string; hint: string }) {
  return value ? <>{value}</> : <span className="bg-fill px-1 text-graphite">[{hint} — à compléter par VBM]</span>;
}

function Section({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section className="mt-6">
      <h3 className="text-[16px] font-semibold tracking-[-0.01em] text-ink">{title}</h3>
      <div className="mt-1.5 space-y-2 text-[14.5px] leading-relaxed text-graphite">{children}</div>
    </section>
  );
}

/**
 * Privacy notice, kept on the same page in a native dialog. Opens on any link
 * to "#confidentialite" (footer, form, emails).
 */
export function PrivacyDialog({ legal, turnstile }: { legal: PublicSettings["legal"]; turnstile: boolean }) {
  const dialog = useRef<HTMLDialogElement>(null);

  useEffect(() => {
    const sync = () => {
      if (window.location.hash === HASH && !dialog.current?.open) dialog.current?.showModal();
    };
    sync();
    window.addEventListener("hashchange", sync);
    return () => window.removeEventListener("hashchange", sync);
  }, []);

  function onClose() {
    if (window.location.hash === HASH) {
      history.replaceState(null, "", window.location.pathname + window.location.search);
    }
  }

  return (
    <dialog
      ref={dialog}
      onClose={onClose}
      onClick={(e) => {
        if (e.target === dialog.current) dialog.current?.close();
      }}
      aria-labelledby="privacy-title"
      className="m-auto max-h-[min(88dvh,52rem)] w-[min(44rem,calc(100%-2rem))] overflow-y-auto border border-hairline bg-surface p-0 text-ink shadow-[0_24px_80px_rgb(0_0_0/0.18)] backdrop:bg-black/35 backdrop:backdrop-blur-[2px]"
    >
      <div className="sticky top-0 flex items-center justify-between border-b border-hairline bg-surface/95 px-6 py-4 backdrop-blur sm:px-8">
        <h2 id="privacy-title" className="text-[19px] font-semibold tracking-[-0.015em]">
          Confidentialité
        </h2>
        <button
          type="button"
          onClick={() => dialog.current?.close()}
          aria-label="Fermer"
          className="-mr-2 flex size-10 items-center justify-center rounded-full transition-colors hover:bg-fill"
        >
          <svg viewBox="0 0 20 20" aria-hidden="true" className="size-5" fill="none" stroke="currentColor" strokeWidth="1.8">
            <path d="M5 5l10 10M15 5 5 15" strokeLinecap="round" />
          </svg>
        </button>
      </div>

      <div className="px-6 pb-8 pt-2 sm:px-8">
        <Section title="Responsable du traitement">
          <p>
            <ToComplete value={legal.entity} hint="Dénomination légale" />, <ToComplete value={legal.address} hint="adresse du siège" />
            , numéro d&apos;entreprise <ToComplete value={legal.vat} hint="BCE / TVA" />.
          </p>
          <p>
            Contact pour toute question relative à vos données&nbsp;: <ToComplete value={legal.privacyEmail} hint="adresse email" />.
          </p>
        </Section>

        <Section title="Données collectées">
          <p>
            Nom du garage, nom et prénom de la personne de contact, téléphone, adresse email, adresse d&apos;enlèvement, nombre de
            jantes, date et créneau choisis, et le commentaire éventuel. Aucune autre donnée n&apos;est demandée.
          </p>
        </Section>

        <Section title="Utilisation">
          <p>
            Ces données servent uniquement à planifier et réaliser l&apos;enlèvement de vos jantes, à vous envoyer la confirmation et
            à vous contacter au sujet de cet enlèvement. Elles ne sont ni vendues, ni utilisées à des fins publicitaires.
          </p>
          <p>
            Base légale&nbsp;: l&apos;exécution de mesures précontractuelles et du contrat de service (article 6.1.b du RGPD).
          </p>
        </Section>

        <Section title="Destinataires">
          <p>
            Les données sont accessibles à l&apos;équipe de VBM Wheels Repair chargée des enlèvements. Elles sont traitées pour son
            compte par Google (agenda de planification), Resend (envoi des emails) et l&apos;hébergeur du site
            {turnstile ? ", ainsi que Cloudflare pour la protection anti-spam du formulaire" : ""}. Ces prestataires peuvent
            traiter des données hors de l&apos;Union européenne, dans le cadre des garanties prévues par le RGPD (clauses
            contractuelles types ou Data Privacy Framework).
          </p>
        </Section>

        <Section title="Durée de conservation">
          <p>
            Les données sont conservées <ToComplete value={legal.retention} hint="durée, par exemple 24 mois après l'enlèvement" />,
            puis supprimées.
          </p>
        </Section>

        <Section title="Vos droits">
          <p>
            Vous pouvez demander l&apos;accès à vos données, leur rectification, leur effacement, la limitation du traitement ou vous
            y opposer, ainsi que leur portabilité, en écrivant à l&apos;adresse de contact ci-dessus. Vous pouvez aussi introduire une
            réclamation auprès de l&apos;Autorité de protection des données (autoriteprotectiondonnees.be).
          </p>
        </Section>

        <Section title="Cookies">
          <p>
            Ce site n&apos;utilise ni cookie publicitaire, ni outil de mesure d&apos;audience.
            {turnstile ? " La protection anti-spam peut utiliser des données techniques strictement nécessaires." : ""}
          </p>
        </Section>
      </div>
    </dialog>
  );
}
